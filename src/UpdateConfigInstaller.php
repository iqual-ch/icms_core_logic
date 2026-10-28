<?php

declare(strict_types=1);

namespace Drupal\icms_core_logic;

use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\ConfigImporterFactory;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\ImportStorageTransformer;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageCopyTrait;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Installs modules and config entities from update hooks like a config import.
 *
 * Update hooks run on every environment, while the config export comes from
 * the developer's run of them. A config entity an update hook creates gets a
 * fresh UUID on every other environment, and the config import that follows
 * (drush deploy) deletes and recreates it to match the exported UUID. That
 * drops its config translations or makes the import fail (ICMS-816).
 *
 * Once the export holds the result, config is therefore taken from the export,
 * with the exported UUIDs and translations, the way the config importer
 * creates it. Before that, on the developer's first run, modules and entities
 * are installed normally so the result can be exported. The update leaves the
 * site matching the export for that config, so a config export right after
 * it (make update runs no config import) does not remove it from the export.
 *
 * The export is read through the import transformer, like drush config:import
 * does, so config_split and config_ignore apply. Default config of a module
 * that the project ignores with config_ignore is not created by an install
 * from the export.
 */
final class UpdateConfigInstaller {

  use DependencySerializationTrait;
  use StorageCopyTrait;

  public function __construct(
    private ModuleInstallerInterface $moduleInstaller,
    private ModuleHandlerInterface $moduleHandler,
    private ModuleExtensionList $moduleExtensionList,
    private ConfigInstallerInterface $configInstaller,
    private ConfigManagerInterface $configManager,
    private EntityTypeManagerInterface $entityTypeManager,
    private StorageInterface $activeStorage,
    private StorageInterface $syncStorage,
    private ImportStorageTransformer $importTransformer,
    private ConfigImporterFactory $configImporterFactory,
    private LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Installs modules, taking their config from the export once it has them.
   *
   * When the exported core.extension lists every module to install, including
   * the dependencies installed along, they are installed in syncing mode like
   * the config importer does: their simple config is written from the export.
   * Their config entities and optional config, and exported config depending
   * on them, are then imported from the export. Config the project deleted
   * from its export is not created. Otherwise the modules are installed
   * normally. Installed modules are skipped; during a config import, the
   * modules are installed in its syncing mode and it creates their config.
   *
   * @param string[] $modules
   *   The modules to install.
   *
   * @return bool
   *   TRUE when the modules' config was taken from the export; FALSE when
   *   they were installed normally or were all installed already.
   */
  public function installModules(array $modules): bool {
    if ($this->configInstaller->isSyncing()) {
      $this->callAndReInjectIfNeeded(fn () => $this->moduleInstaller->install($modules));
      return FALSE;
    }

    $to_install = $this->getModulesToInstall($modules);
    if (!$to_install) {
      return FALSE;
    }

    $export = $this->importTransformer->transform($this->syncStorage);
    $exported_modules = $export->read('core.extension')['module'] ?? [];
    if (array_diff($to_install, array_keys($exported_modules))) {
      $this->callAndReInjectIfNeeded(fn () => $this->moduleInstaller->install($modules));
      return FALSE;
    }

    // As ConfigImporter::doSyncStep() and ::processExtension() do. The kernel
    // carries both settings over to the container the install rebuilds.
    $source_storage = $this->configInstaller->getSourceStorage();
    $this->configInstaller->setSyncing(TRUE)->setSourceStorage($export);
    try {
      $this->callAndReInjectIfNeeded(fn () => $this->moduleInstaller->install($modules));
    }
    finally {
      // Re-injected, this is the config installer of the current container.
      $this->restoreConfigInstaller($source_storage);
    }

    try {
      $this->importFromExport($this->getModuleConfig($to_install, $export), $export);
    }
    finally {
      // The config importer resets the config installer too.
      $this->restoreConfigInstaller($source_storage);
    }
    return TRUE;
  }

  /**
   * Creates a missing config entity, from its exported record if there is one.
   *
   * The exported record is imported like the config importer does, with its
   * UUID and translations, so the config import that follows sees no change.
   * Without one, the entity is created from the given record and gets
   * exported afterwards.
   *
   * @param string $config_name
   *   The config name, e.g. "linkit.linkit_profile.content".
   * @param array|null $record
   *   The record to create the entity from when the export lacks it, in the
   *   format of an exported YAML file (a missing UUID is generated). NULL to
   *   only create it from the export.
   *
   * @return bool
   *   TRUE when the entity was created; FALSE when it already existed or
   *   there was no record to create it from.
   *
   * @throws \InvalidArgumentException
   *   When the name is not one of a config entity type.
   */
  public function importConfigEntity(string $config_name, ?array $record = NULL): bool {
    $entity_type_id = $this->configManager->getEntityTypeIdByName($config_name);
    if (!$entity_type_id) {
      throw new \InvalidArgumentException("$config_name is not a config entity.");
    }
    if ($this->activeStorage->exists($config_name)) {
      return FALSE;
    }

    $export = $this->importTransformer->transform($this->syncStorage);
    $exported = $export->read($config_name);
    if (is_array($exported) && $exported) {
      $this->importFromExport($this->getImportable([$config_name], $export), $export);
      if ($this->activeStorage->exists($config_name)) {
        return TRUE;
      }
      // It depends on config the site lacks, which the importer refuses. The
      // exported record still keeps the UUID.
      $record = $exported;
    }
    if (!$record) {
      return FALSE;
    }

    $this->entityTypeManager->getStorage($entity_type_id)
      ->createFromStorageRecord($record)
      ->save();
    return TRUE;
  }

  /**
   * The given modules and their dependencies that are not installed yet.
   *
   * Unknown modules are kept, so the install reports them.
   *
   * @param string[] $modules
   *   The modules to install.
   *
   * @return string[]
   *   The module names.
   */
  private function getModulesToInstall(array $modules): array {
    // Rescanned like ModuleInstaller::install() does.
    $list = $this->moduleExtensionList->reset()->getList();
    $to_install = [];
    foreach ($modules as $module) {
      $to_install[] = $module;
      if (isset($list[$module])) {
        array_push($to_install, ...array_keys($list[$module]->requires));
      }
    }
    return array_values(array_filter(
      array_unique($to_install),
      fn (string $module): bool => !$this->moduleHandler->moduleExists($module),
    ));
  }

  /**
   * The exported config a config import would create for installed modules.
   *
   * That is the config missing from the site that ships with the modules
   * (config/install, config/optional) or depends on them, directly or through
   * other such config.
   *
   * @param string[] $modules
   *   The installed modules.
   * @param \Drupal\Core\Config\StorageInterface $export
   *   The export.
   *
   * @return string[]
   *   The config names.
   */
  private function getModuleConfig(array $modules, StorageInterface $export): array {
    $shipped = [];
    foreach ($modules as $module) {
      $path = $this->moduleExtensionList->getPath($module);
      foreach ([InstallStorage::CONFIG_INSTALL_DIRECTORY, InstallStorage::CONFIG_OPTIONAL_DIRECTORY] as $directory) {
        if (is_dir("$path/$directory")) {
          array_push($shipped, ...(new FileStorage("$path/$directory"))->listAll());
        }
      }
    }

    $records = $export->readMultiple(array_values(array_diff($export->listAll(), $this->activeStorage->listAll())));
    $selected = [];
    do {
      $added = FALSE;
      foreach ($records as $name => $record) {
        if (isset($selected[$name])) {
          continue;
        }
        if (in_array($name, $shipped, TRUE)
          || array_intersect($this->getDependencies($record, 'module'), $modules)
          || array_intersect($this->getDependencies($record, 'config'), array_keys($selected))) {
          $selected[$name] = TRUE;
          $added = TRUE;
        }
      }
    } while ($added);

    return $this->getImportable(array_keys($selected), $export);
  }

  /**
   * The given exported config whose dependencies the site meets.
   *
   * The config importer refuses config that depends on extensions that are
   * not installed or on config that does not exist after the import.
   *
   * @param string[] $names
   *   The config names.
   * @param \Drupal\Core\Config\StorageInterface $export
   *   The export.
   *
   * @return string[]
   *   The importable config names.
   */
  private function getImportable(array $names, StorageInterface $export): array {
    $extension = $this->activeStorage->read('core.extension') ?: [];
    $modules = array_keys($extension['module'] ?? []);
    $themes = array_keys($extension['theme'] ?? []);
    $active = $this->activeStorage->listAll();
    $records = $export->readMultiple($names);

    do {
      $removed = FALSE;
      $available = [...$active, ...array_keys($records)];
      foreach ($records as $name => $record) {
        [$owner] = explode('.', $name, 2);
        if (($owner !== 'core' && !in_array($owner, [...$modules, ...$themes], TRUE))
          || array_diff($this->getDependencies($record, 'module'), $modules, ['core'])
          || array_diff($this->getDependencies($record, 'theme'), $themes)
          || array_diff($this->getDependencies($record, 'config'), $available)) {
          unset($records[$name]);
          $removed = TRUE;
        }
      }
    } while ($removed);

    $skipped = array_diff($names, array_keys($records));
    if ($skipped) {
      $this->loggerFactory->get('icms_core_logic')->notice('Left @names to the config import: they depend on config or extensions the site lacks.', ['@names' => implode(', ', $skipped)]);
    }
    return array_keys($records);
  }

  /**
   * The dependencies of a config record of one type, enforced ones included.
   *
   * @return string[]
   *   The names of the dependencies.
   */
  private function getDependencies(array $record, string $type): array {
    $dependencies = $record['dependencies'] ?? [];
    return [...($dependencies[$type] ?? []), ...($dependencies['enforced'][$type] ?? [])];
  }

  /**
   * Imports config from the export with the config importer.
   *
   * Only the given config and its translations are imported; the importer
   * orders them by dependency and saves them in syncing mode, as a config
   * import does. When it refuses the import, the config import that follows
   * the updates brings the config.
   *
   * @param string[] $names
   *   The config names.
   * @param \Drupal\Core\Config\StorageInterface $export
   *   The export.
   */
  private function importFromExport(array $names, StorageInterface $export): void {
    if (!$names) {
      return;
    }

    // The active config with the given config added from the export.
    $source = new MemoryStorage();
    self::replaceStorageContents($this->activeStorage, $source);
    foreach ([StorageInterface::DEFAULT_COLLECTION, ...$export->getAllCollectionNames()] as $collection) {
      $target = $source->createCollection($collection);
      foreach ($export->createCollection($collection)->readMultiple($names) as $name => $data) {
        $target->write($name, $data);
      }
    }

    $comparer = new StorageComparer($source, $this->activeStorage);
    try {
      $this->configImporterFactory->get($comparer->createChangelist())->import();
    }
    catch (ConfigImporterException $e) {
      $this->loggerFactory->get('icms_core_logic')->warning('Could not import @names from the config export; the config import brings them. @message', [
        '@names' => implode(', ', $names),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Resets syncing on the config installer, keeping the caller's source.
   */
  private function restoreConfigInstaller(?StorageInterface $source_storage): void {
    $this->configInstaller->setSyncing(FALSE);
    if ($source_storage) {
      $this->configInstaller->setSourceStorage($source_storage);
    }
  }

  /**
   * Runs a module install and re-injects the services it replaced.
   *
   * A module install rebuilds the container; the services injected before
   * then belong to the old one (e.g. an entity type manager without the new
   * module's entity types). Same as ConfigImporter::callAndReInjectIfNeeded().
   */
  private function callAndReInjectIfNeeded(callable $callable): void {
    $container_id = spl_object_id(\Drupal::getContainer());
    try {
      $callable();
    }
    finally {
      if ($container_id !== spl_object_id(\Drupal::getContainer())) {
        $this->__sleep();
        $this->__wakeup();
      }
    }
  }

}
