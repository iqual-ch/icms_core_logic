<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\ExistingSite;

use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests that the email address is the one and only login name (ICMS-714).
 *
 * ICMS pins email_registration for the login side and names accounts itself, so
 * the rule holds however an account is created — the accounts here are built
 * programmatically, which is precisely the path the contrib submodule leaves
 * unsynced. Covers the contract the ticket asks for (one value, kept in sync,
 * not editable on its own) plus the shortening ICMS applies to an address that
 * does not fit the username column.
 *
 * @see icms_core_logic_email_registration_name_alter()
 * @see icms_admin_form_user_form_alter()
 *
 * @group icms_core_logic
 */
class EmailAsUsernameTest extends ExistingSiteBase {

  /**
   * A new account is named after its email address.
   */
  public function testAccountIsNamedAfterItsEmailAddress(): void {
    $account = $this->createAccount('first');

    $this->assertSame($account->getEmail(), $account->getAccountName());
  }

  /**
   * Changing the address renames the account with it.
   */
  public function testChangingTheAddressRenamesTheAccount(): void {
    $account = $this->createAccount('before');
    $address = $this->address('after');

    $account->setEmail($address)->save();

    $account = User::load($account->id());
    $this->assertSame($address, $account->getEmail());
    $this->assertSame($address, $account->getAccountName());
  }

  /**
   * An address that does not fit the username column is shortened, not refused.
   */
  public function testOverlongAddressIsShortened(): void {
    $account = $this->createAccount('short');
    $long = str_repeat('a', 55) . '@a-very-long-domain-name.example.com';
    $this->assertGreaterThan(UserInterface::USERNAME_MAX_LENGTH, mb_strlen($long));

    $account->setEmail($long)->save();

    $account = User::load($account->id());
    $this->assertSame($long, $account->getEmail());
    $this->assertSame(
      icms_core_logic_username_max_length(),
      mb_strlen($account->getAccountName()),
      'The username is shortened to exactly the limit ICMS reserves.'
    );
    $this->assertStringStartsWith($account->getAccountName(), $long);

    // The account that carries a shortened username must keep following its
    // address — the contrib release stops syncing here, ICMS does not.
    $next = $this->address('back');
    $account->setEmail($next)->save();

    $this->assertSame($next, User::load($account->id())->getAccountName());
  }

  /**
   * The account form offers no username to maintain, and requires the address.
   */
  public function testUserFormHasNoUsernameWidget(): void {
    $admin = $this->createAccount('admin', ['administer users']);
    $this->drupalLogin($admin);

    $form = \Drupal::service('entity.form_builder')->getForm($admin, 'default');

    // '#type' => 'value' carries the name through without rendering a widget.
    $this->assertSame('value', $form['account']['name']['#type']);
    $this->assertTrue($form['account']['mail']['#required']);
  }

  /**
   * The login form asks for the address and accepts it.
   */
  public function testLoginWithTheEmailAddress(): void {
    $account = $this->createAccount('login');
    $password = $this->randomMachineName();
    $account->setPassword($password)->save();

    $this->drupalGet('/user/login');
    $this->assertSession()->fieldExists('name');
    $this->assertSession()->pageTextContains('Email address');

    $this->submitForm([
      'name' => $account->getEmail(),
      'pass' => $password,
    ], 'Log in');

    // Landing on the account page rather than back on the form is the proof;
    // the path carries the negotiated language prefix.
    $this->assertSession()->addressMatches('#/user/' . $account->id() . '$#');
  }

  /**
   * Creates a member of the site, cleaned up when the test finishes.
   *
   * @param string $key
   *   Distinguishes the addresses used within one test.
   * @param string[] $permissions
   *   Permissions to grant through a generated role.
   *
   * @return \Drupal\user\UserInterface
   *   The account.
   */
  private function createAccount(string $key, array $permissions = []): UserInterface {
    $account = $this->createUser($permissions);
    $account->setEmail($this->address($key))->save();

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
