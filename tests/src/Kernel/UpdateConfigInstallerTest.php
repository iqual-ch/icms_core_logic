<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\MemoryStorage;
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
    // Locale writes the French label on install from the interface
    // translation, so it already matches the export before the import.
    $this->container->get('config.storage')->createCollection('language.fr')->write('system.menu.content', ['label' => 'Contenu']);
    $this->assertNotSame($exported_uuid, Menu::load('content')->uuid());
    $this->assertContains('system.menu.content', $this->changelist('delete'));
    $this->assertContains('system.menu.content', $this->changelist('create'));

    $this->configImporter()->import();
    $this->assertSame($exported_uuid, Menu::load('content')->uuid());
    $this->assertFalse($this->activeTranslation(), 'The import deleted the translation with the menu.');
  }

  /**
   * An exported module is installed with its config from the export.
   *
   * The site then matches the export: a config export right after the update
   * (make update runs no config import) changes nothing, nor does an import.
   */
  public function testExportedModuleIsInstalledFromTheExport(): void {
    // The dependencies are installed along.
    $exported_uuid = $this->exportNavigationAndUninstall(['layout_builder', 'layout_discovery', 'contextual']);

    $this->assertTrue($this->service()->installModules(['navigation']));
    $module_handler = $this->container->get('module_handler');
    $this->assertTrue($module_handler->moduleExists('navigation'));
    $this->assertTrue($module_handler->moduleExists('layout_builder'));
    $this->assertFalse($this->container->get('config.installer')->isSyncing(), 'Syncing is reset in the current container.');
    $this->assertSame(99, $this->config('navigation.settings')->get('logo.max.height'), 'Simple config comes from the export.');
    $this->assertSame($exported_uuid, Menu::load('content')->uuid());
    $this->assertSame(['label' => 'Contenu'], $this->activeTranslation());
    $this->assertNull(Menu::load('navigation-user-links'), 'A menu the project deleted stays deleted.');
    $this->assertSame([], $this->allChanges(), 'The site matches the export.');

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
   * The services a normal install replaced are re-injected.
   */
  public function testServicesAreReinjectedAfterInstall(): void {
    $this->copyConfig($this->container->get('config.storage'), $this->syncStorage);
    $service = $this->service();
    $this->container->get('entity_type.manager')->getDefinitions();

    $this->assertFalse($service->installModules(['field']));
    $this->assertTrue($service->importConfigEntity('field.storage.user.field_weight', $this->fieldStorageRecord()), 'The entity type manager knows the field module.');
    $this->assertNotNull(FieldStorageConfig::loadByName('user', 'field_weight'));
  }

  /**
   * The services an install from the export replaced are re-injected.
   */
  public function testServicesAreReinjectedAfterInstallFromTheExport(): void {
    $this->container->get('module_installer')->install(['field']);
    $this->copyConfig($this->container->get('config.storage'), $this->syncStorage);
    $record = ['uuid' => 'b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e'] + $this->fieldStorageRecord();
    $this->syncStorage->write('field.storage.user.field_weight', $record);
    $this->container->get('module_installer')->uninstall(['field']);
    $service = $this->service();
    $this->container->get('entity_type.manager')->getDefinitions();

    $this->assertTrue($service->installModules(['field']));
    $this->assertTrue($service->importConfigEntity('field.storage.user.field_weight'), 'The entity type manager knows the field module.');
    $this->assertSame($record['uuid'], FieldStorageConfig::loadByName('user', 'field_weight')->uuid());
    $this->assertSame([], $this->allChanges(), 'Saved as exported.');
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
    \Drupal::state()->set('icms_core_logic_update_test.fail_install', TRUE);
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
   * During a config import, the import's syncing state is kept.
   */
  public function testCallerSyncingStateIsKept(): void {
    $this->exportNavigationAndUninstall();

    // Called while a config import installs modules.
    $this->container->get('config.installer')->setSyncing(TRUE)->setSourceStorage($this->syncStorage);
    $this->assertFalse($this->service()->installModules(['navigation']));
    $config_installer = \Drupal::service('config.installer');
    $this->assertTrue($config_installer->isSyncing());
    $this->assertSame($this->syncStorage, $config_installer->getSourceStorage());
    $this->assertNull(Menu::load('content'), 'The running import creates the config entities.');
    $config_installer->setSyncing(FALSE);

    // A source storage set without syncing is kept as well.
    $this->container->get('module_installer')->uninstall(['navigation']);
    $source_storage = new MemoryStorage();
    $this->container->get('config.installer')->setSourceStorage($source_storage);
    $this->assertTrue($this->service()->installModules(['navigation']));
    $config_installer = \Drupal::service('config.installer');
    $this->assertFalse($config_installer->isSyncing());
    $this->assertSame($source_storage, $config_installer->getSourceStorage());
  }

  /**
   * When the config importer refuses, the exported records are written.
   */
  public function testRefusedImportFallsBackToTheExportedRecords(): void {
    $exported_uuid = $this->exportNavigationAndUninstall();
    // Config of an extension the site lacks makes the importer refuse.
    $this->container->get('config.storage')->write('icms_core_logic_missing.settings', ['key' => 'value']);

    $this->assertTrue($this->service()->installModules(['navigation']));
    $this->assertSame($exported_uuid, Menu::load('content')->uuid());
    $this->assertSame(['label' => 'Contenu'], $this->activeTranslation());
    $this->assertNull(Menu::load('navigation-user-links'));
    $this->assertSame(['' => ['delete' => ['icms_core_logic_missing.settings']]], $this->allChanges(), 'The site matches the export but for the stray config.');
  }

  /**
   * When the config importer fails to save config, its record is written.
   */
  public function testFailedSaveFallsBackToTheExportedRecord(): void {
    $this->enableModules(['icms_core_logic_update_test']);
    $exported_uuid = $this->exportNavigationAndUninstall();
    \Drupal::state()->set('icms_core_logic_update_test.fail_save', 'content');

    $this->assertTrue($this->service()->installModules(['navigation']));
    $this->assertNull(\Drupal::state()->get('icms_core_logic_update_test.fail_save'), 'The importer tried to save the menu.');
    $this->assertSame($exported_uuid, Menu::load('content')->uuid());
    $this->assertSame(['label' => 'Contenu'], $this->activeTranslation());
    $this->assertSame([], $this->allChanges(), 'The site matches the export.');
  }

  /**
   * Only the config the install would create is imported.
   */
  public function testOnlyConfigOfTheInstallIsImported(): void {
    $this->exportNavigationAndUninstall();
    // Exported config of a later update, depending on navigation.
    $this->syncStorage->write('system.menu.pending', ['uuid' => '0c1d2e3f-4a5b-4c6d-8e7f-8091a2b3c4d5'] + $this->menuRecord('pending', 'Pending', ['enforced' => ['module' => ['navigation']]]));

    $this->assertTrue($this->service()->installModules(['navigation']));
    $this->assertNotNull(Menu::load('content'));
    $this->assertNull(Menu::load('pending'), 'The later update creates it.');
    $this->assertSame(['' => ['create' => ['system.menu.pending']]], $this->allChanges());
  }

  /**
   * The export is transformed once per service instance.
   */
  public function testExportIsReadOnce(): void {
    $this->copyConfig($this->container->get('config.storage'), $this->syncStorage);
    $service = $this->service();
    $this->assertFalse($service->installModules(['contact']));

    $this->syncStorage->write('system.menu.later', $this->menuRecord('later', 'Later'));
    $this->assertFalse($service->importConfigEntity('system.menu.later'), 'The export read before is used.');
    $service->resetExport();
    $this->assertTrue($service->importConfigEntity('system.menu.later'));
  }

  /**
   * Config entities are created from the exported record first.
   */
  public function testImportConfigEntity(): void {
    $uuid = '9f3b2b7e-4a1c-4d55-9c0e-0a1b2c3d4e5f';
    $this->syncStorage->write('system.menu.exported', $this->menuRecord('exported', 'Exported') + ['uuid' => $uuid]);
    $this->syncStorage->createCollection('language.fr')->write('system.menu.exported', ['label' => 'Exporté']);
    $service = $this->service();

    $this->assertTrue($service->importConfigEntity('system.menu.exported', $this->menuRecord('exported', 'Own')));
    $this->assertSame($uuid, Menu::load('exported')->uuid());
    $this->assertSame('Exported', Menu::load('exported')->label());
    $this->assertSame(['label' => 'Exporté'], $this->container->get('config.storage')->createCollection('language.fr')->read('system.menu.exported'), 'The translation comes along.');

    $this->assertTrue($service->importConfigEntity('system.menu.own', $this->menuRecord('own', 'Own')));
    $this->assertSame('Own', Menu::load('own')->label());
    $this->assertNotEmpty(Menu::load('own')->uuid());

    // Existing config is left alone, config without any record is skipped.
    $this->assertFalse($service->importConfigEntity('system.menu.own', $this->menuRecord('own', 'Changed')));
    $this->assertSame('Own', Menu::load('own')->label());
    $this->assertFalse($service->importConfigEntity('system.menu.nowhere'));
    $this->assertNull(Menu::load('nowhere'));

    // An exported record with unmet dependencies is not used.
    $this->syncStorage->write('system.menu.broken', ['uuid' => '1d2e3f4a-5b6c-4d7e-9f80-91a2b3c4d5e6'] + $this->menuRecord('broken', 'Broken', ['config' => ['system.menu.missing']]));
    $service->resetExport();
    $this->assertTrue($service->importConfigEntity('system.menu.broken', $this->menuRecord('broken', 'Own')));
    $this->assertSame('Own', Menu::load('broken')->label());
    $this->assertNotSame('1d2e3f4a-5b6c-4d7e-9f80-91a2b3c4d5e6', Menu::load('broken')->uuid());

    $this->expectException(\InvalidArgumentException::class);
    $service->importConfigEntity('system.site');
  }

  /**
   * The helper of the released update hooks prefers the export too.
   */
  public function testImportHelperPrefersTheExportedRecord(): void {
    // Relative to config/update of icms_core_logic.
    $directory = '../../tests/fixtures/config_update';

    $this->assertTrue(_icms_core_logic_import_config_entity('system.menu.bundled', 'menu', $directory));
    $this->assertSame('Bundled', Menu::load('bundled')->label());
    $this->assertFalse(_icms_core_logic_import_config_entity('system.menu.bundled', 'menu', $directory), 'Existing config is left alone.');
    Menu::load('bundled')->delete();

    $uuid = '9f3b2b7e-4a1c-4d55-9c0e-0a1b2c3d4e5f';
    $this->syncStorage->write('system.menu.bundled', $this->menuRecord('bundled', 'Exported') + ['uuid' => $uuid]);
    $this->assertTrue(_icms_core_logic_import_config_entity('system.menu.bundled', 'menu', $directory));
    $this->assertSame($uuid, Menu::load('bundled')->uuid());
    $this->assertSame('Exported', Menu::load('bundled')->label());

    $this->assertFalse(_icms_core_logic_import_config_entity('system.menu.nowhere', 'menu', $directory));
    $this->assertNull(Menu::load('nowhere'));
  }

  /**
   * Simulates the developer's export of navigation, then another environment.
   *
   * The developer's update installed navigation, the export holds it with a
   * French menu label, a changed setting and without one of its menus. The
   * other environment does not have navigation yet.
   *
   * @param string[] $dependencies
   *   Dependencies of navigation the other environment lacks too.
   *
   * @return string
   *   The exported UUID of system.menu.content.
   */
  protected function exportNavigationAndUninstall(array $dependencies = []): string {
    $this->container->get('module_installer')->install(['navigation']);
    $this->copyConfig($this->container->get('config.storage'), $this->syncStorage);
    $this->syncStorage->createCollection('language.fr')->write('system.menu.content', ['label' => 'Contenu']);
    $settings = $this->syncStorage->read('navigation.settings');
    $settings['logo']['max']['height'] = 99;
    $this->syncStorage->write('navigation.settings', $settings);
    $this->syncStorage->delete('system.menu.navigation-user-links');

    $this->container->get('module_installer')->uninstall(['navigation', ...$dependencies]);
    $this->assertNull(Menu::load('content'));
    foreach ($dependencies as $module) {
      $this->assertFalse($this->container->get('module_handler')->moduleExists($module));
    }
    return $this->syncStorage->read('system.menu.content')['uuid'];
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
   * Everything a config import would change, by collection and operation.
   */
  protected function allChanges(): array {
    $comparer = new StorageComparer($this->syncStorage, $this->container->get('config.storage'));
    $comparer->createChangelist();
    $changes = [];
    foreach ($comparer->getAllCollectionNames() as $collection) {
      $changes += array_filter([$collection => array_filter($comparer->getChangelist(NULL, $collection))]);
    }
    return $changes;
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
  protected function menuRecord(string $id, string $label, array $dependencies = []): array {
    return [
      'langcode' => 'en',
      'status' => TRUE,
      'dependencies' => $dependencies,
      'id' => $id,
      'label' => $label,
      'description' => '',
      'locked' => FALSE,
    ];
  }

  /**
   * A field storage record as exported, without UUID.
   */
  protected function fieldStorageRecord(): array {
    return [
      'langcode' => 'en',
      'status' => TRUE,
      'dependencies' => ['module' => ['user']],
      'id' => 'user.field_weight',
      'field_name' => 'field_weight',
      'entity_type' => 'user',
      'type' => 'integer',
      'settings' => ['unsigned' => FALSE, 'size' => 'normal'],
      'module' => 'core',
      'locked' => FALSE,
      'cardinality' => 1,
      'translatable' => TRUE,
      'indexes' => [],
      'persist_with_no_fields' => FALSE,
      'custom_storage' => FALSE,
    ];
  }

}
