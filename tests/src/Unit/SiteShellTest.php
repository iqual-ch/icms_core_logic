<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\icms_core_logic\BackendDomain;
use Drupal\icms_core_logic\SiteShell;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests when and how the frontend is shown inside the backend.
 */
#[Group('icms_core_logic')]
class SiteShellTest extends UnitTestCase {

  /**
   * The frontend's route aliases of the search and news pages, as exported.
   */
  private const ALIASES = [
    'search' => ['aliases' => ['de' => '/suche', 'en' => '/search', 'fr' => '/recherche']],
    'news-detail' => ['aliases' => ['de' => '/news/:slug', 'en' => '/news/:slug']],
    'catch-all' => ['aliases' => ['en' => '/listing/:slug(.*)*']],
  ];

  /**
   * Creates the shell for a user with permissions and frontend aliases.
   */
  private function shell(array $permissions = ['access navigation'], array $keys = self::ALIASES): SiteShell {
    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('hasPermission')->willReturnCallback(fn(string $permission) => in_array($permission, $permissions, TRUE));
    $config = $this->getConfigFactoryStub(['frontend_routing.settings' => ['keys' => $keys]]);
    return new SiteShell(new BackendDomain(new Settings(['icms_frontend_hosts' => ['www.example.com']])), $user, $config);
  }

  /**
   * The shell needs a toolbar user on the backend domain.
   */
  public function testApplies(): void {
    $backendRequest = Request::create('https://backend.example.com/de/text');
    $this->assertTrue($this->shell()->applies($backendRequest));
    $this->assertTrue($this->shell(['access toolbar'])->applies($backendRequest));
    $this->assertFalse($this->shell([])->applies($backendRequest), 'Anonymous users are redirected instead.');
    $this->assertFalse($this->shell()->applies(Request::create('https://www.example.com/de/text')), 'Frontend requests are not shelled.');
    $jsonRequest = Request::create('https://backend.example.com/de/text');
    $jsonRequest->setRequestFormat('json');
    $this->assertFalse($this->shell()->applies($jsonRequest));
  }

  /**
   * The eligibility check on its own, for other callers.
   */
  public function testIsEligible(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnMap([['access navigation', FALSE], ['access toolbar', TRUE]]);
    $this->assertTrue($this->shell()->isEligible($account));
  }

  /**
   * Only paths the frontend registered as its routes are frontend pages.
   */
  #[DataProvider('frontendPathProvider')]
  public function testIsFrontendPath(string $path, bool $expected): void {
    $this->assertSame($expected, $this->shell()->isFrontendPath($path), $path);
  }

  /**
   * Data provider for testIsFrontendPath().
   */
  public static function frontendPathProvider(): array {
    return [
      'alias' => ['/suche', TRUE],
      'alias with language prefix' => ['/de/suche', TRUE],
      'alias, other language' => ['/fr/search', TRUE],
      'alias with query and trailing slash' => ['/de/suche/?q=icms', TRUE],
      'param' => ['/en/news/some-article', TRUE],
      'param takes one segment' => ['/en/news/a/b', FALSE],
      'catch-all param' => ['/en/listing/a/b', TRUE],
      'backend path' => ['/en/admin/foobar', FALSE],
      'unknown frontend path' => ['/en/foobar', FALSE],
      'front page' => ['/', FALSE],
      'prefix only' => ['/de/such', FALSE],
    ];
  }

  /**
   * Without registered routes nothing is a frontend path.
   */
  public function testIsFrontendPathWithoutAliases(): void {
    $this->assertFalse($this->shell(['access navigation'], [])->isFrontendPath('/de/suche'));
  }

  /**
   * The shell frames the frontend URL of the requested path.
   */
  public function testBuild(): void {
    $build = $this->shell()->build(Request::create('https://backend.example.com/de/text'), '/de/text?blokkliEditing=1');
    $this->assertSame('icms_site_shell', $build['#theme']);
    $this->assertSame('https://www.example.com/de/text?blokkliEditing=1', $build['#url']);
    $this->assertSame('https://www.example.com', $build['#frontend_origin']);
    $this->assertContains('icms_core_logic/site_shell', $build['#attached']['library']);
  }

}
