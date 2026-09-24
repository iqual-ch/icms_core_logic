<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\ExistingSite;

use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the login name rules of ICMS-714.
 *
 * New accounts are named after their email address and follow it, any other
 * username is kept, and the login form accepts either value. ICMS pins
 * email_registration for the login side and names new accounts itself, so the
 * naming rule holds however an account is created — most accounts here are
 * built programmatically, the path the contrib module leaves alone; one comes
 * through /admin/people/create.
 *
 * @see icms_core_logic_email_registration_name_alter()
 * @see icms_admin_form_user_form_alter()
 * @see icms_core_logic_update_9055()
 *
 * @group icms_core_logic
 */
class EmailAsUsernameTest extends ExistingSiteBase {

  /**
   * A new account is named after its email address, whatever name it was given.
   */
  public function testNewAccountIsNamedAfterItsEmailAddress(): void {
    $account = $this->createNewAccount('first');

    $this->assertSame($account->getEmail(), $account->getAccountName());
  }

  /**
   * An account named after its address follows the address when it changes.
   */
  public function testAccountNamedAfterItsAddressFollowsIt(): void {
    $account = $this->createNewAccount('follow');
    $address = $this->address('moved');

    $account->setEmail($address)->save();

    $account = User::load($account->id());
    $this->assertSame($address, $account->getEmail());
    $this->assertSame($address, $account->getAccountName());
  }

  /**
   * The create form offers no username and names the account after the address.
   */
  public function testCreateFormNamesTheAccountAfterItsAddress(): void {
    $admin = $this->createNewAccount('admin');
    $admin->addRole($this->createRole(['administer users']))->save();
    $this->drupalLogin($admin);

    $address = $this->address('created');
    $password = $this->randomMachineName();

    $this->drupalGet('/admin/people/create');
    // Not fieldNotExists('name'): Mink would also match the "First name" label.
    $this->assertSession()->elementNotExists('css', 'input[name="name"]');
    $this->assertSession()->elementExists('css', 'input[name="mail"]');
    $this->submitForm([
      'mail' => $address,
      'pass[pass1]' => $password,
      'pass[pass2]' => $password,
      'field_user_first_name[0][value]' => 'Created',
      'field_user_last_name[0][value]' => 'Account',
    ], 'Create new account');

    $account = user_load_by_mail($address);
    $this->assertNotFalse($account);
    $this->markEntityForCleanup($account);
    $this->assertSame($address, $account->getAccountName());
  }

  /**
   * An existing account keeps its username, even when the address changes.
   */
  public function testExistingAccountKeepsItsUsername(): void {
    $account = $this->createLegacyAccount('legacy');
    $username = $account->getAccountName();
    $address = $this->address('after');

    $account->setEmail($address)->save();

    $account = User::load($account->id());
    $this->assertSame($address, $account->getEmail());
    $this->assertSame($username, $account->getAccountName());
  }

  /**
   * A new account whose address does not fit the username column is shortened.
   */
  public function testOverlongAddressIsShortened(): void {
    $long = str_repeat('a', 55) . '@a-very-long-domain-name.example.com';
    $this->assertGreaterThan(UserInterface::USERNAME_MAX_LENGTH, mb_strlen($long));

    $account = User::create(['name' => 'placeholder', 'mail' => $long, 'status' => 1]);
    $account->save();
    $this->markEntityForCleanup($account);

    $account = User::load($account->id());
    $this->assertSame($long, $account->getEmail());
    $this->assertSame(
      icms_core_logic_username_max_length(),
      mb_strlen($account->getAccountName()),
      'The username is shortened to exactly the limit ICMS reserves.'
    );
    $this->assertStringStartsWith($account->getAccountName(), $long);
  }

  /**
   * The login form accepts the email address.
   */
  public function testLoginWithTheEmailAddress(): void {
    $account = $this->createLegacyAccount('mail-login');
    $this->assertNotSame($account->getEmail(), $account->getAccountName());

    $this->logInWith($account, $account->getEmail());
  }

  /**
   * The login form still accepts a username that differs from the address.
   */
  public function testLoginWithTheUsername(): void {
    $account = $this->createLegacyAccount('name-login');
    $this->assertNotSame($account->getEmail(), $account->getAccountName());

    $this->logInWith($account, $account->getAccountName());
  }

  /**
   * Submits the login form with the given login name and expects success.
   */
  private function logInWith(UserInterface $account, string $login): void {
    $password = $this->randomMachineName();
    $account->setPassword($password)->save();

    $this->drupalGet('/user/login');
    $this->assertSession()->pageTextContains('Email address or username');

    $this->submitForm([
      'name' => $login,
      'pass' => $password,
    ], 'Log in');

    // Landing on the account page rather than back on the form is the proof;
    // the path carries the negotiated language prefix.
    $this->assertSession()->addressMatches('#/user/' . $account->id() . '$#');
  }

  /**
   * Creates an account the way any code would, cleaned up after the test.
   *
   * @param string $key
   *   Distinguishes the addresses used within one test.
   *
   * @return \Drupal\user\UserInterface
   *   The account, as stored.
   */
  private function createNewAccount(string $key): UserInterface {
    $account = User::create([
      'name' => 'given-' . $this->randomMachineName(),
      'mail' => $this->address($key),
      'status' => 1,
    ]);
    $account->save();
    $this->markEntityForCleanup($account);

    return User::load($account->id());
  }

  /**
   * Creates an account whose username differs from its address.
   *
   * Renaming after the first save is how such an account comes to exist: it
   * was created before ICMS-714, or renamed by hand since.
   *
   * @param string $key
   *   Distinguishes the addresses used within one test.
   *
   * @return \Drupal\user\UserInterface
   *   The account, as stored.
   */
  private function createLegacyAccount(string $key): UserInterface {
    $account = $this->createNewAccount($key);
    $account->setUsername('legacy-' . strtolower($this->randomMachineName()))->save();

    return User::load($account->id());
  }

  /**
   * Builds an address that no other account on the site can hold.
   *
   * @param string $key
   *   Distinguishes the addresses used within one test.
   *
   * @return string
   *   The address.
   */
  private function address(string $key): string {
    return strtolower($key . '-' . $this->randomMachineName()) . '@icms-714.example.com';
  }

}
