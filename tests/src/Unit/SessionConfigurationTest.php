<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Unit;

use Drupal\icms_core_logic\BackendDomain;
use Drupal\icms_core_logic\Session\SessionConfiguration;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the session cookie shared by a frontend domain and its backend.
 */
#[Group('icms_core_logic')]
class SessionConfigurationTest extends UnitTestCase {

  /**
   * Creates the configuration, outside of a test run as core's own test does.
   */
  private function configuration(array $options = []): SessionConfiguration {
    $configuration = $this->getMockBuilder(SessionConfiguration::class)
      ->setConstructorArgs([$options, new BackendDomain()])
      ->onlyMethods(['drupalValidTestUa'])
      ->getMock();
    $configuration->method('drupalValidTestUa')->willReturn(FALSE);
    return $configuration;
  }

  /**
   * A backend request uses the cookie domain and name of its frontend host.
   */
  public function testBackendRequestSharesTheFrontendCookie(): void {
    $backend = $this->configuration()->getOptions(Request::create('https://backend.www.example.com/user/login'));
    $frontend = $this->configuration()->getOptions(Request::create('https://www.example.com/graphql'));

    $this->assertSame('.www.example.com', $backend['cookie_domain']);
    $this->assertSame('.www.example.com', $frontend['cookie_domain']);
    $this->assertSame($frontend['name'], $backend['name']);
    $this->assertStringStartsWith('SSESS', $backend['name']);
    $this->assertTrue($backend['cookie_secure']);

    // The cookie the backend set is recognised when the frontend forwards it.
    $request = Request::create('https://www.example.com/graphql', cookies: [$backend['name'] => 'abc']);
    $this->assertTrue($this->configuration()->hasSession($request));

    // Another domain gets its own cookie.
    $other = $this->configuration()->getOptions(Request::create('https://backend.example.org/user/login'));
    $this->assertSame('.example.org', $other['cookie_domain']);
    $this->assertNotSame($backend['name'], $other['name']);
  }

  /**
   * Requests on other hosts keep core's behaviour.
   */
  public function testOtherRequestsKeepCoreBehaviour(): void {
    $options = $this->configuration()->getOptions(Request::create('https://cms.example.com/user/login'));
    $this->assertSame('.cms.example.com', $options['cookie_domain']);
    $this->assertSame(
      $this->configuration()->getOptions(Request::create('https://cms.example.com/'))['name'],
      $options['name'],
    );
  }

  /**
   * A cookie domain configured in services.yml wins, as in core.
   */
  public function testConfiguredCookieDomainWins(): void {
    $configured = ['cookie_domain' => '.example.org'];
    $backend = $this->configuration($configured)->getOptions(Request::create('https://backend.www.example.com/'));
    $frontend = $this->configuration($configured)->getOptions(Request::create('https://www.example.com/'));
    $this->assertSame('.example.org', $backend['cookie_domain']);
    $this->assertSame('.example.org', $frontend['cookie_domain']);
    $this->assertSame($frontend['name'], $backend['name']);
  }

}
