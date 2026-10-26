<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Kernel;

use Drupal\Core\Config\StorageInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\system\Entity\Menu;
use PHPUnit\Framework\Attributes\Group;

/**
 * Update hooks install config with the UUID the project exports (ICMS-816).
 *
 * Otherwise the config import after the hooks deletes and recreates the
 * entity and drops its config translations.
 */
#[Group('icms_core_logic')]
class UpdateConfigImportTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * The project's config sync directory.
   */
  protected StorageInterface $syncStorage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->syncStorage = $this->container->get('config.storage.sync');
    require_once dirname(__DIR__, 3) . '/icms_core_logic.install';
  }

  /**
   * An entity installed from update YAML takes the exported record instead.
   */
  public function testImportPrefersTheExportedRecord(): void {
    $uuid = '9f3b2b7e-4a1c-4d55-9c0e-0a1b2c3d4e5f';
    $this->syncStorage->write('system.menu.exported', [
      'uuid' => $uuid,
      'langcode' => 'en',
      'status' => TRUE,
      'dependencies' => [],
      'id' => 'exported',
      'label' => 'Exported',
      'description' => '',
      'locked' => FALSE,
    ]);

    $this->assertTrue(_icms_core_logic_import_config_entity('system.menu.exported', 'menu', '9050'));
    $menu = Menu::load('exported');
    $this->assertNotNull($menu);
    $this->assertSame($uuid, $menu->uuid());
    $this->assertSame('Exported', $menu->label());

    // Existing config is left alone, config without any source is skipped.
    $this->assertFalse(_icms_core_logic_import_config_entity('system.menu.exported', 'menu', '9050'));
    $this->assertFalse(_icms_core_logic_import_config_entity('system.menu.nowhere', 'menu', '9050'));
    $this->assertNull(Menu::load('nowhere'));
  }

}
