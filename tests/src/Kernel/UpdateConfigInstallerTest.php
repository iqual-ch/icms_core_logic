<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\icms_core_logic\UpdateConfigInstaller;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\system\Entity\Menu;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Update hooks take config from the project's export (ICMS-816).
 *
 * A config entity an update hook creates gets a fresh UUID on every
 * environment, so the config import after the hooks deletes and recreates it
 * to match the export, dropping its config translations. The navigation module
 * is the case found on a client: its menus lost their French labels.
 */
#[Group('icms_core_logic')]
class UpdateConfigInstallerTest extends KernelTestBase {

  /**
   * The service under test.
   */
  protected const SERVICE = 'icms_core_logic.update_config_installer';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'language'];

  /**
   * The project's config sync directory.
   */
  protected StorageInterface $syncStorage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->syncStorage = $this->container->get('config.storage.sync');
    require_once dirname(__DIR__, 3) . '/icms_core_logic.install';
  }

  /**
   * {@inheritdoc}
   *
   * Registers the service as icms_core_logic.services.yml defines it, without
   * enabling icms_core_logic and its dependencies.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $services = Yaml::decode((string) file_get_contents(dirname(__DIR__, 3) . '/icms_core_logic.services.yml'));
    $definition = $services['services'][self::SERVICE];
    $container->register(self::SERVICE, $definition['class'])
      ->setArguments(array_map(static fn (string $id) => new Reference(substr($id, 1)), $definition['arguments']))
      ->setPublic(TRUE);
  }

  /**
   * Baseline: a plain install makes the import drop the menu translation.
   */
  public function testPlainInstallLosesTranslationOnImport(): void {
    $exported_uuid = $this->exportNavigationAndUninstall();

    $this->container->get('module_installer')->install(['navigation']);
    $this->writeActiveTranslation();
    $this->assertNotSame($exported_uuid, Menu::load('content')->uuid());
    $this->assertContains('system.menu.content', $this->changelist('delete'));
    $this->assertContains('system.menu.content', $this->changelist('create'));

    $this->configImporter()->import();
    $this->assertSame($exported_uuid, Menu::load('content')->uuid());
    $this->assertFalse($this->activeTranslation(), 'The import deleted the translation with the menu.');
  }

  /**
   * An exported module is installed like the config import installs it.
   */
  public function testExportedModuleLeavesConfigEntitiesToImport(): void {
    $exported_uuid = $this->exportNavigationAndUninstall();

    $this->assertTrue($this->service()->installModules(['navigation']));
    $this->assertTrue($this->container->get('module_handler')->moduleExists('navigation'));
    $this->assertFalse($this->container->get('config.installer')->isSyncing(), 'Syncing is reset in the current container.');
    $this->assertNull(Menu::load('content'), 'Config entities are left to the import.');
    $this->assertSame(99, $this->config('navigation.settings')->get('logo.max.height'), 'Simple config comes from the export.');

    $this->writeActiveTranslation();
    $this->configImporter()->import();
    $this->assertSame($exported_uuid, Menu::load('content')->uuid());
    $this->assertSame(['label' => 'Contenu'], $this->activeTranslation());
    $this->assertNull(Menu::load('navigation-user-links'), 'A menu the project deleted stays deleted.');
    $comparer = new StorageComparer($this->syncStorage, $this->container->get('config.storage'));
    $this->assertFalse($comparer->createChangelist()->hasChanges(), 'Nothing left to import.');

    // Later installs create their config entities again.
    $this->container->get('module_installer')->install(['contact']);
    $this->assertTrue($this->container->get('config.storage')->exists('contact.form.personal'));
  }

  /**
   * A module the export does not list yet is installed normally.
   */
  public function testModuleNotExportedIsInstalledNormally(): void {
    $this->copyConfig($this->container->get('config.storage'), $this->syncStorage);

    $this->assertFalse($this->service()->installModules(['navigation']));
    $this->assertTrue($this->container->get('module_handler')->moduleExists('navigation'));
    $this->assertNotNull(Menu::load('content'));
    $this->assertFalse($this->service()->installModules(['navigation']), 'Installed modules are skipped.');
  }

  /**
   * A dependency the export lacks makes the whole install a normal one.
   */
  public function testModuleWithDependencyNotExportedIsInstalledNormally(): void {
    $this->copyConfig($this->container->get('config.storage'), $this->syncStorage);
    $extension = $this->syncStorage->read('core.extension');
    $extension['module']['navigation'] = 0;
    $this->syncStorage->write('core.extension', $extension);

    $this->assertFalse($this->service()->installModules(['navigation']));
    $this->assertNotNull(Menu::load('content'));
  }

  /**
   * The services the install replaced are re-injected.
   */
  public function testServicesAreReinjectedAfterInstall(): void {
    $this->copyConfig($this->container->get('config.storage'), $this->syncStorage);
    $service = $this->service();
    $this->container->get('entity_type.manager')->getDefinitions();

    $service->installModules(['field']);
    $this->assertTrue($service->importConfigEntity('field.storage.user.field_weight', [
      'id' => 'user.field_weight',
      'field_name' => 'field_weight',
      'entity_type' => 'user',
      'type' => 'integer',
      'settings' => ['unsigned' => FALSE, 'size' => 'normal'],
    ]), 'The entity type manager knows the field module.');
    $this->assertNotNull(FieldStorageConfig::loadByName('user', 'field_weight'));
  }

  /**
   * Syncing is reset when the install fails.
   */
  public function testSyncingIsResetAfterFailedInstall(): void {
    $this->copyConfig($this->container->get('config.storage'), $this->syncStorage);
    $extension = $this->syncStorage->read('core.extension');
    $extension['module']['icms_core_logic_update_test'] = 0;
    $this->syncStorage->write('core.extension', $extension);

    // The test module fails in hook_install(), after the container rebuild.
    $container_id = spl_object_id(\Drupal::getContainer());
    try {
      $this->service()->installModules(['icms_core_logic_update_test']);
      $this->fail('The install of the failing module failed.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('icms_core_logic_update_test failed to install.', $e->getMessage());
    }
    $this->assertNotSame($container_id, spl_object_id(\Drupal::getContainer()));
    $this->assertFalse(\Drupal::service('config.installer')->isSyncing());
  }

  /**
   * Config entities are created from the exported record first.
   */
  public function testImportConfigEntity(): void {
    $uuid = '9f3b2b7e-4a1c-4d55-9c0e-0a1b2c3d4e5f';
    $this->syncStorage->write('system.menu.exported', $this->menuRecord('exported', 'Exported') + ['uuid' => $uuid]);
    $service = $this->service();

    $this->assertTrue($service->importConfigEntity('system.menu.exported', $this->menuRecord('exported', 'Own')));
    $this->assertSame($uuid, Menu::load('exported')->uuid());
    $this->assertSame('Exported', Menu::load('exported')->label());

    $this->assertTrue($service->importConfigEntity('system.menu.own', $this->menuRecord('own', 'Own')));
    $this->assertSame('Own', Menu::load('own')->label());
    $this->assertNotEmpty(Menu::load('own')->uuid());

    // Existing config is left alone, config without any record is skipped.
    $this->assertFalse($service->importConfigEntity('system.menu.own', $this->menuRecord('own', 'Changed')));
    $this->assertSame('Own', Menu::load('own')->label());
    $this->assertFalse($service->importConfigEntity('system.menu.nowhere'));
    $this->assertNull(Menu::load('nowhere'));
  }

  /**
   * The helper of the released update hooks prefers the export too.
   */
  public function testImportHelperPrefersTheExportedRecord(): void {
    $uuid = '9f3b2b7e-4a1c-4d55-9c0e-0a1b2c3d4e5f';
    $this->syncStorage->write('system.menu.exported', $this->menuRecord('exported', 'Exported') + ['uuid' => $uuid]);

    $this->assertTrue(_icms_core_logic_import_config_entity('system.menu.exported', 'menu', '9050'));
    $this->assertSame($uuid, Menu::load('exported')->uuid());
    $this->assertSame('Exported', Menu::load('exported')->label());

    $this->assertFalse(_icms_core_logic_import_config_entity('system.menu.exported', 'menu', '9050'));
    $this->assertFalse(_icms_core_logic_import_config_entity('system.menu.nowhere', 'menu', '9050'));
    $this->assertNull(Menu::load('nowhere'));
  }

  /**
   * Simulates the developer's export of navigation, then another environment.
   *
   * The developer's update installed navigation, the export holds it with a
   * French menu label, a changed setting and without one of its menus. The
   * other environment does not have navigation yet.
   *
   * @return string
   *   The exported UUID of system.menu.content.
   */
  protected function exportNavigationAndUninstall(): string {
    $installer = $this->container->get('module_installer');
    $installer->install(['navigation']);
    $this->copyConfig($this->container->get('config.storage'), $this->syncStorage);
    $this->syncStorage->createCollection('language.fr')->write('system.menu.content', ['label' => 'Contenu']);
    $settings = $this->syncStorage->read('navigation.settings');
    $settings['logo']['max']['height'] = 99;
    $this->syncStorage->write('navigation.settings', $settings);
    $this->syncStorage->delete('system.menu.navigation-user-links');

    $this->container->get('module_installer')->uninstall(['navigation']);
    $this->assertNull(Menu::load('content'));
    return $this->syncStorage->read('system.menu.content')['uuid'];
  }

  /**
   * Writes the French menu label to the active config.
   *
   * Locale does so on install from the interface translation, so the active
   * label matches the export before the import.
   */
  protected function writeActiveTranslation(): void {
    $this->container->get('config.storage')->createCollection('language.fr')->write('system.menu.content', ['label' => 'Contenu']);
  }

  /**
   * The French menu label in the active config.
   */
  protected function activeTranslation(): array|false {
    return $this->container->get('config.storage')->createCollection('language.fr')->read('system.menu.content');
  }

  /**
   * The names a config import would process with the given operation.
   */
  protected function changelist(string $op): array {
    $comparer = new StorageComparer($this->syncStorage, $this->container->get('config.storage'));
    return $comparer->createChangelist()->getChangelist($op);
  }

  /**
   * The service from the current container.
   */
  protected function service(): UpdateConfigInstaller {
    return $this->container->get(self::SERVICE);
  }

  /**
   * A menu record as exported.
   */
  protected function menuRecord(string $id, string $label): array {
    return [
      'langcode' => 'en',
      'status' => TRUE,
      'dependencies' => [],
      'id' => $id,
      'label' => $label,
      'description' => '',
      'locked' => FALSE,
    ];
  }

}
