<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\icms_core_logic\BackendDomain;
use Drupal\icms_core_logic\BlokkliEditor;
use Drupal\node\NodeInterface;
use Drupal\paragraphs_blokkli\ParagraphsBlokkliConfig;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the link from the backend into the blökkli editor.
 */
#[Group('icms_core_logic')]
class BlokkliEditorTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * Creates a node mock of a blökkli-enabled (or other) type.
   */
  private function node(bool $enabled = TRUE, bool $mayUpdate = TRUE): NodeInterface {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('de');
    $url = $this->createMock(Url::class);
    $url->method('toString')->with(TRUE)->willReturn((new GeneratedUrl())->setGeneratedUrl('/de/ueber-uns'));
    $node = $this->createMock(NodeInterface::class);
    $node->method('getEntityTypeId')->willReturn('node');
    $node->method('bundle')->willReturn($enabled ? 'icms_page' : 'other');
    $node->method('uuid')->willReturn('7c4d9a87-99d8-434e-aaa0-a989b224fa6d');
    $node->method('language')->willReturn($language);
    $node->method('toUrl')->willReturn($url);
    $node->method('access')->willReturn($mayUpdate ? AccessResult::allowed() : AccessResult::forbidden());
    return $node;
  }

  /**
   * Creates the editor service for the current request.
   */
  private function editor(?Request $request = NULL, bool $permission = TRUE): BlokkliEditor {
    $config = $this->createMock(ParagraphsBlokkliConfig::class);
    $config->method('isEnabledForEntity')->willReturnCallback(fn($entity) => $entity->bundle() === 'icms_page');
    $repository = $this->createMock(EntityRepositoryInterface::class);
    $repository->method('getTranslationFromContext')->willReturnArgument(0);
    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('hasPermission')->willReturn($permission);
    $requestStack = new RequestStack();
    $requestStack->push($request ?? Request::create('https://backend.example.com/admin/content'));
    return new BlokkliEditor($config, new BackendDomain(new Settings(['icms_frontend_hosts' => ['www.example.com']])), $repository, $user, $requestStack);
  }

  /**
   * The editor opens the frontend page with the blökkli query.
   */
  public function testUrl(): void {
    $url = $this->editor()->getUrl($this->node());
    $this->assertSame('https://www.example.com/de/ueber-uns', $url->getUri());
    $this->assertSame(['blokkliEditing' => '7c4d9a87-99d8-434e-aaa0-a989b224fa6d', 'language' => 'de'], $url->getOption('query'));
  }

  /**
   * Outside of a backend request (Drush) the URL is relative.
   */
  public function testUrlWithoutBackendDomain(): void {
    $url = $this->editor(Request::create('https://www.example.com/'))->getUrl($this->node());
    $this->assertFalse($url->isExternal());
    $this->assertSame('base:de/ueber-uns', $url->getUri());
  }

  /**
   * Blökkli-enabled type, permission and update access are all required.
   */
  public function testAccess(): void {
    $this->assertTrue($this->editor()->access($this->node())->isAllowed());
    $this->assertFalse($this->editor()->access($this->node(FALSE))->isAllowed());
    $this->assertFalse($this->editor()->access($this->node(TRUE, FALSE))->isAllowed());
    $this->assertFalse($this->editor(NULL, FALSE)->access($this->node())->isAllowed());
  }

}
