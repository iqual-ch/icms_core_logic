<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The toolbar language switcher must not crash on a monolingual site.
 *
 * With a single configurable language core does not register the language
 * request subscriber, so the language negotiator never receives the current
 * user. Building the toolbar item then threw a TypeError on every admin page.
 * Covered by the patched drupal/toolbar_language_switcher in iqual/icms_core.
 *
 * @see https://www.drupal.org/project/toolbar_language_switcher/issues/3619422
 */
#[Group('icms_core_logic')]
class ToolbarLanguageSwitcherMonolingualTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'language',
    'breakpoint',
    'toolbar',
    'toolbar_language_switcher',
  ];

  /**
   * Building the toolbar item on a monolingual site returns a cacheable stub.
   */
  public function testBuildOnMonolingualSite(): void {
    $this->installConfig(['language']);

    // A site that used to be multilingual keeps URL negotiation enabled.
    $this->config('language.types')
      ->set('negotiation.language_interface.enabled', ['language-url' => 0])
      ->save();

    // Instantiating the negotiator attaches it to the language manager, as a
    // request to e.g. the language detection form does. Without the request
    // subscriber its current user stays unset.
    $this->container->get('language_negotiator');
    $language_manager = $this->container->get('language_manager');
    $this->assertFalse($language_manager->isMultilingual());

    $build = $this->container->get('tls.render.builder')->build();

    $this->assertArrayHasKey('admin_toolbar_langswitch', $build);
    $this->assertArrayNotHasKey('tab', $build['admin_toolbar_langswitch']);
    $this->assertContains('config:configurable_language_list', $build['admin_toolbar_langswitch']['#cache']['tags']);
  }

}
