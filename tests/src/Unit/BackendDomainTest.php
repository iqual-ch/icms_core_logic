<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Unit;

use Drupal\icms_core_logic\BackendDomain;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the backend domain description.
 */
#[Group('icms_core_logic')]
class BackendDomainTest extends UnitTestCase {

  /**
   * Without both URLs, or with the same host, the split is disabled.
   */
  public function testDisabled(): void {
    $this->assertFalse((new BackendDomain(NULL, NULL))->isEnabled());
    $this->assertFalse((new BackendDomain('https://www.example.com', NULL))->isEnabled());
    $this->assertFalse((new BackendDomain('https://www.example.com', 'https://www.example.com/'))->isEnabled());
    $this->assertNull((new BackendDomain('https://www.example.com', 'https://www.example.com'))->getCookieDomain());
    $this->assertFalse((new BackendDomain(NULL, NULL))->isBackendRequest(Request::create('https://backend.example.com/')));
  }

  /**
   * Hosts are compared case-insensitively and without the frontend's port.
   */
  public function testHosts(): void {
    $domain = new BackendDomain('https://www.example.com', 'https://Backend.example.com');
    $this->assertTrue($domain->isEnabled());
    $this->assertSame('www.example.com', $domain->getFrontendHost());
    $this->assertSame('backend.example.com', $domain->getBackendHost());
    $this->assertTrue($domain->isBackendRequest(Request::create('https://backend.example.com/de/node/1')));
    $this->assertFalse($domain->isBackendRequest(Request::create('https://www.example.com/de/node/1')));
    // Requests proxied by the frontend carry its host.
    $request = Request::create('http://drupal.internal/de/graphql', server: ['HTTP_X_FORWARDED_HOST' => 'www.example.com']);
    Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_HOST);
    $this->assertFalse($domain->isBackendRequest($request));
    Request::setTrustedProxies([], -1);
    $this->assertSame('https://www.example.com/de/about?x=1', $domain->getFrontendUrlFor('/de/about?x=1'));
    $this->assertSame('https://www.example.com/', $domain->getFrontendUrlFor(''));
  }

  /**
   * The cookie domain is the closest common parent, or the override.
   */
  #[DataProvider('cookieDomainProvider')]
  public function testCookieDomain(string $frontend, string $backend, ?string $expected): void {
    $this->assertSame($expected, (new BackendDomain($frontend, $backend))->getCookieDomain());
  }

  public static function cookieDomainProvider(): array {
    return [
      'apex + subdomain' => ['https://example.com', 'https://backend.example.com', '.example.com'],
      'www + backend' => ['https://www.example.com', 'https://backend.example.com', '.example.com'],
      'platform.sh preview' => [
        'https://main-bvxea6i-ft7z4vgxofrna.ch-1.platformsh.site',
        'https://backend.main-bvxea6i-ft7z4vgxofrna.ch-1.platformsh.site',
        '.main-bvxea6i-ft7z4vgxofrna.ch-1.platformsh.site',
      ],
      'ddev' => ['https://starterkit.ddev.site', 'https://backend.starterkit.ddev.site', '.starterkit.ddev.site'],
      'nothing in common' => ['https://www.example.com', 'https://cms.other.org', NULL],
      'only the tld in common' => ['https://www.example.com', 'https://cms.other.com', NULL],
      'ip address' => ['https://127.0.0.1', 'https://127.0.0.1:8080', NULL],
    ];
  }

  /**
   * DRUPAL_COOKIE_DOMAIN wins over the derived domain.
   */
  public function testCookieDomainOverride(): void {
    $domain = new BackendDomain('https://www.example.com', 'https://backend.example.com', 'example.org');
    $this->assertSame('.example.org', $domain->getCookieDomain());
  }

}
