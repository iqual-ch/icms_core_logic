<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Unit;

use Drupal\icms_core_logic\BackendDomain;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the backend domain derived from the request host.
 */
#[Group('icms_core_logic')]
class BackendDomainTest extends UnitTestCase {

  /**
   * The service under test.
   */
  private BackendDomain $domain;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->domain = new BackendDomain();
  }

  /**
   * A request on "backend.<host>" belongs to the frontend on "<host>".
   */
  public function testBackendRequest(): void {
    $request = Request::create('https://backend.www.example.com/de/node/1?x=1');
    $this->assertTrue($this->domain->isBackendRequest($request));
    $this->assertSame('www.example.com', $this->domain->getFrontendHost($request));
    $this->assertSame('https://www.example.com', $this->domain->getFrontendUrl($request));
    $this->assertSame('https://www.example.com/de/about?x=1', $this->domain->getFrontendUrlFor($request, '/de/about?x=1'));
    $this->assertSame('https://www.example.com/', $this->domain->getFrontendUrlFor($request, ''));
    $this->assertSame('.www.example.com', $this->domain->getCookieDomain($request));

    // Hosts are compared case-insensitively.
    $request = Request::create('https://Backend.Example.com/');
    $this->assertSame('example.com', $this->domain->getFrontendHost($request));
  }

  /**
   * Requests on any other host are not backend requests.
   */
  public function testOtherRequests(): void {
    foreach (['https://www.example.com/de/node/1', 'https://backend/', 'https://frontend.example.com/'] as $uri) {
      $request = Request::create($uri);
      $this->assertFalse($this->domain->isBackendRequest($request), $uri);
      $this->assertNull($this->domain->getFrontendHost($request), $uri);
      $this->assertNull($this->domain->getFrontendUrl($request), $uri);
      $this->assertNull($this->domain->getFrontendUrlFor($request, '/de/about'), $uri);
      $this->assertNull($this->domain->getCookieDomain($request), $uri);
    }

    // Requests proxied by the frontend carry its host.
    $request = Request::create('http://backend.internal/de/graphql', server: ['HTTP_X_FORWARDED_HOST' => 'www.example.com']);
    Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_HOST);
    try {
      $this->assertFalse($this->domain->isBackendRequest($request));
    }
    finally {
      Request::setTrustedProxies([], -1);
    }
  }

  /**
   * The frontend URL keeps the request's scheme and non-default port.
   */
  public function testFrontendUrlKeepsSchemeAndPort(): void {
    $this->assertSame('http://starterkit.ddev.site:8080', $this->domain->getFrontendUrl(Request::create('http://backend.starterkit.ddev.site:8080/')));
    $this->assertSame('https://example.com', $this->domain->getFrontendUrl(Request::create('https://backend.example.com:443/')));
    $this->assertSame('http://example.com', $this->domain->getFrontendUrl(Request::create('http://backend.example.com/')));
  }

  /**
   * The cookie domain is the frontend host, which covers the backend too.
   */
  #[DataProvider('cookieDomainProvider')]
  public function testCookieDomain(string $uri, ?string $expected): void {
    $this->assertSame($expected, $this->domain->getCookieDomain(Request::create($uri)));
  }

  /**
   * Data provider for testCookieDomain().
   */
  public static function cookieDomainProvider(): array {
    return [
      'apex' => ['https://backend.example.com/', '.example.com'],
      'www' => ['https://backend.www.example.com/', '.www.example.com'],
      'platform.sh preview' => ['https://backend.main-bvxea6i-ft7z4vgxofrna.ch-1.platformsh.site/', '.main-bvxea6i-ft7z4vgxofrna.ch-1.platformsh.site'],
      'ddev' => ['https://backend.starterkit.ddev.site/', '.starterkit.ddev.site'],
      'frontend request' => ['https://www.example.com/', NULL],
    ];
  }

}
