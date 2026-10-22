<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Unit;

use Drupal\Core\Session\SessionConfiguration as CoreSessionConfiguration;
use Drupal\Core\Site\Settings;
use Drupal\icms_core_logic\BackendDomain;
use Drupal\icms_core_logic\Session\SessionConfiguration;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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
  private function configuration(array $options = [], array $frontendHosts = ['www.example.com'], string|false $testUa = FALSE): SessionConfiguration {
    $configuration = $this->getMockBuilder(SessionConfiguration::class)
      ->setConstructorArgs([$options, new BackendDomain(new Settings(['icms_frontend_hosts' => $frontendHosts]))])
      ->onlyMethods(['drupalValidTestUa'])
      ->getMock();
    $configuration->method('drupalValidTestUa')->willReturn($testUa);
    return $configuration;
  }

  /**
   * Returns the options core's own configuration gives a request.
   */
  private function coreOptions(Request $request, array $options = []): array {
    $configuration = $this->getMockBuilder(CoreSessionConfiguration::class)
      ->setConstructorArgs([$options])
      ->onlyMethods(['drupalValidTestUa'])
      ->getMock();
    $configuration->method('drupalValidTestUa')->willReturn(FALSE);
    return $configuration->getOptions($request);
  }

  /**
   * Backend, www and apex requests share the cookie of the site domain.
   */
  public function testWwwFrontendSharesTheBackendCookie(): void {
    $backend = $this->configuration()->getOptions(Request::create('https://backend.example.com/user/login'));
    $www = $this->configuration()->getOptions(Request::create('https://www.example.com/graphql'));
    $apex = $this->configuration()->getOptions(Request::create('https://example.com/graphql'));

    foreach ([$backend, $www, $apex] as $options) {
      $this->assertSame('.example.com', $options['cookie_domain']);
      $this->assertSame('SSESS' . substr(hash('sha256', 'example.com'), 0, 32), $options['name']);
      $this->assertTrue($options['cookie_secure']);
    }

    // The cookie the backend set is recognised when the frontend forwards it.
    $request = Request::create('https://www.example.com/graphql', cookies: [$backend['name'] => 'abc']);
    $this->assertTrue($this->configuration()->hasSession($request));

    // Also with the frontend host only known from X-Forwarded-Host.
    Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_HOST);
    try {
      $request = Request::create('http://backend.internal/graphql', server: ['HTTP_X_FORWARDED_HOST' => 'www.example.com'], cookies: ['SESS' . substr($backend['name'], 5) => 'abc']);
      $proxied = $this->configuration()->getOptions($request);
      $this->assertSame('.example.com', $proxied['cookie_domain']);
      $this->assertSame('SESS' . substr($backend['name'], 5), $proxied['name']);
      $this->assertTrue($this->configuration()->hasSession($request));
    }
    finally {
      Request::setTrustedProxies([], -1);
    }
  }

  /**
   * The backend cookie is the one core sets on "<domain>".
   */
  #[DataProvider('siteDomainProvider')]
  public function testBackendCookieIsCoreCookieOfTheSiteDomain(string $backendUri, string $siteUri, array $frontendHosts): void {
    foreach ([[], ['name_suffix' => '-suffix']] as $options) {
      $backend = $this->configuration($options, $frontendHosts)->getOptions(Request::create($backendUri));
      $core = $this->coreOptions(Request::create($siteUri), $options);
      $this->assertSame($core['cookie_domain'], $backend['cookie_domain']);
      $this->assertSame($core['name'], $backend['name']);
    }
  }

  /**
   * Data provider for testBackendCookieIsCoreCookieOfTheSiteDomain().
   */
  public static function siteDomainProvider(): array {
    return [
      'A apex' => ['https://backend.foobar.com/user/login', 'https://foobar.com/', ['foobar.com']],
      'B www' => ['https://backend.foobar.com/user/login', 'https://foobar.com/', ['www.foobar.com']],
      'C sub-subdomain' => ['https://backend.baz.foobar.com/user/login', 'https://baz.foobar.com/', ['baz.foobar.com']],
      'C+www' => ['https://backend.baz.foobar.com/user/login', 'https://baz.foobar.com/', ['www.baz.foobar.com']],
      'local DDEV' => ['https://backend.starterkit.ddev.site/user/login', 'https://starterkit.ddev.site/', []],
      'co.uk' => ['https://backend.example.co.uk/user/login', 'https://example.co.uk/', ['www.example.co.uk']],
      'port' => ['http://backend.starterkit.ddev.site:8080/user/login', 'http://starterkit.ddev.site:8080/', []],
    ];
  }

  /**
   * Each domain of a multi-domain site gets its own cookie.
   */
  public function testMultiDomain(): void {
    $hosts = ['foo.com', 'www.bar.ch'];
    $foo = $this->configuration([], $hosts)->getOptions(Request::create('https://backend.foo.com/user/login'));
    $bar = $this->configuration([], $hosts)->getOptions(Request::create('https://backend.bar.ch/user/login'));
    $this->assertSame('.foo.com', $foo['cookie_domain']);
    $this->assertSame('.bar.ch', $bar['cookie_domain']);
    $this->assertNotSame($foo['name'], $bar['name']);
    $this->assertSame($bar['name'], $this->configuration([], $hosts)->getOptions(Request::create('https://www.bar.ch/graphql'))['name']);
  }

  /**
   * Requests on other hosts keep core's behaviour.
   */
  #[DataProvider('otherHostProvider')]
  public function testOtherHostsKeepCoreBehaviour(string $uri): void {
    $request = Request::create($uri);
    $this->assertSame($this->coreOptions($request), $this->configuration()->getOptions($request));
  }

  /**
   * Data provider for testOtherHostsKeepCoreBehaviour().
   */
  public static function otherHostProvider(): array {
    return [
      'other subdomain' => ['https://cms.example.com/user/login'],
      'apex' => ['https://example.com/'],
      'localhost' => ['http://localhost/'],
      'IP address' => ['http://127.0.0.1/user/login'],
    ];
  }

  /**
   * A site domain without an embedded dot is no valid cookie domain.
   *
   * The cookie domain then is core's for the request host, the name still
   * that of the site domain.
   */
  public function testInvalidSiteDomainFallsBackToCoreDomain(): void {
    $options = $this->configuration([], [])->getOptions(Request::create('http://backend.localhost/user/login'));
    $this->assertSame('.backend.localhost', $options['cookie_domain']);
    $this->assertSame($this->coreOptions(Request::create('http://localhost/'))['name'], $options['name']);
  }

  /**
   * A cookie domain configured in services.yml wins, as in core.
   */
  public function testConfiguredCookieDomainWins(): void {
    $configured = ['cookie_domain' => '.example.org'];
    $backend = $this->configuration($configured)->getOptions(Request::create('https://backend.example.com/'));
    $frontend = $this->configuration($configured)->getOptions(Request::create('https://www.example.com/'));
    $core = $this->coreOptions(Request::create('https://backend.example.com/'), $configured);
    $this->assertSame('.example.org', $backend['cookie_domain']);
    $this->assertSame('.example.org', $frontend['cookie_domain']);
    $this->assertSame($core['name'], $backend['name']);
    $this->assertSame($core['name'], $frontend['name']);
  }

  /**
   * The test user agent's session name wins, as in core.
   */
  public function testTestUserAgentNameWins(): void {
    $options = $this->configuration(testUa: 'test12345')->getOptions(Request::create('https://backend.example.com/'));
    $this->assertSame('SSESS' . substr(hash('sha256', 'test12345'), 0, 32), $options['name']);
    $this->assertSame('.example.com', $options['cookie_domain']);
  }

}
