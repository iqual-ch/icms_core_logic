<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Unit;

use Drupal\Core\Site\Settings;
use Drupal\icms_core_logic\BackendDomain;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the frontend and backend domains derived from the request host.
 */
#[Group('icms_core_logic')]
class BackendDomainTest extends UnitTestCase {

  /**
   * Creates the service with the given known frontend hosts.
   */
  private function domain(mixed $frontendHosts = NULL): BackendDomain {
    return new BackendDomain(new Settings($frontendHosts === NULL ? [] : ['icms_frontend_hosts' => $frontendHosts]));
  }

  /**
   * A request on "backend.D" belongs to the frontend on D or www.D.
   */
  #[DataProvider('scenarioProvider')]
  public function testScenarios(mixed $frontendHosts, string $backendUri, string $frontendUrl, string $cookieDomain): void {
    $domain = $this->domain($frontendHosts);
    $request = Request::create($backendUri . '/de/node/1?x=1');
    $this->assertTrue($domain->isBackendRequest($request));
    $this->assertSame(parse_url($frontendUrl, PHP_URL_HOST), $domain->getFrontendHost($request));
    $this->assertSame($frontendUrl, $domain->getFrontendUrl($request));
    $this->assertSame($frontendUrl . '/de/about?x=1', $domain->getFrontendUrlFor($request, '/de/about?x=1'));
    $this->assertSame($frontendUrl . '/', $domain->getFrontendUrlFor($request, ''));
    $this->assertSame(substr($cookieDomain, 1), $domain->getSiteDomain($request));
    $this->assertSame($cookieDomain, $domain->getCookieDomain($request));

    // Requests on the frontend host (proxied GraphQL) are no backend requests;
    // on www.D they share the cookie domain, on D core's already is ".D".
    $frontend = Request::create($frontendUrl . '/graphql');
    $this->assertFalse($domain->isBackendRequest($frontend));
    $this->assertNull($domain->getFrontendUrl($frontend));
    $www = str_starts_with((string) parse_url($frontendUrl, PHP_URL_HOST), 'www.');
    $this->assertSame($www ? $cookieDomain : NULL, $domain->getCookieDomain($frontend));
  }

  /**
   * Data provider for testScenarios().
   */
  public static function scenarioProvider(): array {
    $multi = ['foo.com', 'www.bar.ch'];
    return [
      'A apex' => [['foobar.com'], 'https://backend.foobar.com', 'https://foobar.com', '.foobar.com'],
      'B www' => [['www.foobar.com'], 'https://backend.foobar.com', 'https://www.foobar.com', '.foobar.com'],
      'C sub-subdomain' => [['baz.foobar.com'], 'https://backend.baz.foobar.com', 'https://baz.foobar.com', '.baz.foobar.com'],
      'C+www' => [['www.baz.foobar.com'], 'https://backend.baz.foobar.com', 'https://www.baz.foobar.com', '.baz.foobar.com'],
      'multi-domain apex' => [$multi, 'https://backend.foo.com', 'https://foo.com', '.foo.com'],
      'multi-domain www' => [$multi, 'https://backend.bar.ch', 'https://www.bar.ch', '.bar.ch'],
      'D and www.D both known' => [['foobar.com', 'www.foobar.com'], 'https://backend.foobar.com', 'https://foobar.com', '.foobar.com'],
      'only another www known' => [['www.other.com'], 'https://backend.foobar.com', 'https://foobar.com', '.foobar.com'],
      'no settings' => [NULL, 'https://backend.foobar.com', 'https://foobar.com', '.foobar.com'],
      'invalid settings' => ['www.foobar.com', 'https://backend.foobar.com', 'https://foobar.com', '.foobar.com'],
      'local DDEV' => [NULL, 'https://backend.starterkit.ddev.site', 'https://starterkit.ddev.site', '.starterkit.ddev.site'],
      'upsun preview' => [
        ['main-bvxea6i-ft7z4vgxofrna.ch-1.platformsh.site'],
        'https://backend.main-bvxea6i-ft7z4vgxofrna.ch-1.platformsh.site',
        'https://main-bvxea6i-ft7z4vgxofrna.ch-1.platformsh.site',
        '.main-bvxea6i-ft7z4vgxofrna.ch-1.platformsh.site',
      ],
      'co.uk apex' => [['example.co.uk'], 'https://backend.example.co.uk', 'https://example.co.uk', '.example.co.uk'],
      'co.uk www' => [['www.example.co.uk'], 'https://backend.example.co.uk', 'https://www.example.co.uk', '.example.co.uk'],
      'case-insensitive' => [['WWW.FooBar.com'], 'https://Backend.FOOBAR.com', 'https://www.foobar.com', '.foobar.com'],
    ];
  }

  /**
   * Requests on hosts other than "backend.D" are not backend requests.
   */
  #[DataProvider('otherRequestProvider')]
  public function testOtherRequests(string $uri, ?string $siteDomain): void {
    $domain = $this->domain(['www.example.com']);
    $request = Request::create($uri);
    $this->assertFalse($domain->isBackendRequest($request));
    $this->assertNull($domain->getFrontendHost($request));
    $this->assertNull($domain->getFrontendUrl($request));
    $this->assertNull($domain->getFrontendUrlFor($request, '/de/about'));
    $this->assertSame($siteDomain, $domain->getSiteDomain($request));
    $this->assertSame($siteDomain === NULL ? NULL : '.' . $siteDomain, $domain->getCookieDomain($request));
  }

  /**
   * Data provider for testOtherRequests().
   */
  public static function otherRequestProvider(): array {
    return [
      'www frontend' => ['https://www.example.com/de/node/1', 'example.com'],
      'apex frontend' => ['https://example.com/', NULL],
      'other subdomain' => ['https://frontend.example.com/', NULL],
      'bare backend label' => ['https://backend/', NULL],
      'localhost' => ['http://localhost/', NULL],
      'IP address' => ['http://127.0.0.1/', NULL],
    ];
  }

  /**
   * Requests proxied by the frontend carry its host.
   */
  public function testProxiedRequests(): void {
    Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_HOST);
    try {
      $request = Request::create('http://backend.internal/de/graphql', server: ['HTTP_X_FORWARDED_HOST' => 'www.example.com']);
      $this->assertFalse($this->domain()->isBackendRequest($request));
      $this->assertSame('.example.com', $this->domain()->getCookieDomain($request));
    }
    finally {
      Request::setTrustedProxies([], -1);
    }
  }

  /**
   * The frontend URL keeps the request's scheme and non-default port.
   */
  public function testFrontendUrlKeepsSchemeAndPort(): void {
    $domain = $this->domain(['www.example.com']);
    $this->assertSame('http://starterkit.ddev.site:8080', $domain->getFrontendUrl(Request::create('http://backend.starterkit.ddev.site:8080/')));
    $this->assertSame('https://www.example.com:8443', $domain->getFrontendUrl(Request::create('https://backend.example.com:8443/')));
    $this->assertSame('https://www.example.com', $domain->getFrontendUrl(Request::create('https://backend.example.com:443/')));
    $this->assertSame('http://www.example.com', $domain->getFrontendUrl(Request::create('http://backend.example.com/')));
    $this->assertSame('.example.com', $domain->getCookieDomain(Request::create('https://backend.example.com:8443/')));
  }

}
