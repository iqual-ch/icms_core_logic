<?php

declare(strict_types=1);

namespace Drupal\icms_core_logic;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\Entity\ConfigDependencyManager;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\ConfigImporterFactory;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\ImportStorageTransformer;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorableConfigBase;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageCopyTrait;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

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

  /**
   * The transformed export, once transformed by this instance.
   *
   * It is the transformer's live storage (the config_import table), kept
   * because transforming takes seconds on a real site. Later transforms of
   * the unchanged sync directory write the same content to it. Update hooks
   * fetch the service anew, so after a container rebuild a new instance
   * transforms again.
   */
  private ?StorageInterface $export = NULL;

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
    private LockBackendInterface $persistentLock,
    private EventDispatcherInterface $eventDispatcher,
    private TypedConfigManagerInterface $typedConfigManager,
  ) {}

  /**
   * Installs modules, taking their config from the export once it has them.
   *
   * When the exported core.extension lists every module to install, including
   * the dependencies installed along, they are installed in syncing mode like
   * the config importer does: their simple config is written from the export.
   * The config a normal install would create (their config/install and
   * config/optional, other modules' optional config depending on them) is
   * then imported from the export, as far as the export has it. Config the
   * project deleted from its export is not created. Otherwise the modules are
   * installed normally. Installed modules are skipped; during a config import,
   * the modules are installed in its syncing mode and it creates their config.
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

    $export = $this->getExport();
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
   * Without one, or when the exported record depends on config or extensions
   * the site lacks, the entity is created from the given record and gets
   * exported afterwards. Config missing from the sync directory skips the
   * export, so config that only config_split adds to it is not found.
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

    // Not exported at all, e.g. on the developer's first run: no need to
    // transform the export.
    $export = $this->syncStorage->exists($config_name) ? $this->getExport() : NULL;
    $exported = $export?->read($config_name);
    if ($export && is_array($exported) && $exported) {
      // An exported record with unmet dependencies is unusable: the hook's own
      // record is created instead, as before the project exported it.
      $this->importFromExport($this->getImportable([$config_name], $export), $export);
      if ($this->activeStorage->exists($config_name)) {
        return TRUE;
      }
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
   * Forgets the transformed export, so the next call reads it again.
   *
   * @internal
   *   For tests that change the export between calls.
   */
  public function resetExport(): void {
    $this->export = NULL;
  }

  /**
   * The export as the config import reads it, transformed once.
   */
  private function getExport(): StorageInterface {
    // The transformer's storage, kept: later transforms of the unchanged
    // export write the same content to it.
    return $this->export ??= $this->importTransformer->transform($this->syncStorage);
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
   * The exported config a normal install of the modules would create.
   *
   * That is the modules' config/install and config/optional, and the
   * config/optional of other installed modules that depends on them, as far
   * as the export has it and the site lacks it. Other exported config the
   * site lacks is left alone: later update hooks may create it themselves.
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
      foreach ([InstallStorage::CONFIG_INSTALL_DIRECTORY, InstallStorage::CONFIG_OPTIONAL_DIRECTORY] as $directory) {
        array_push($shipped, ...$this->getShippedConfig($module, $directory)->listAll());
      }
    }
    foreach (array_diff(array_keys($this->moduleHandler->getModuleList()), $modules) as $module) {
      $optional = $this->getShippedConfig($module, InstallStorage::CONFIG_OPTIONAL_DIRECTORY);
      foreach ($optional->readMultiple($optional->listAll()) as $name => $record) {
        if (array_intersect($this->getDependencies($record, 'module'), $modules) || array_intersect($this->getDependencies($record, 'config'), $shipped)) {
          $shipped[] = $name;
        }
      }
    }

    $names = array_diff(array_intersect(array_unique($shipped), $export->listAll()), $this->activeStorage->listAll());
    return $this->getImportable(array_values($names), $export);
  }

  /**
   * The config a module ships in a directory.
   */
  private function getShippedConfig(string $module, string $directory): StorageInterface {
    // Lists nothing when the directory does not exist.
    return new FileStorage($this->moduleExtensionList->getPath($module) . '/' . $directory);
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
      $this->loggerFactory->get('icms_core_logic')->warning('Not importing @names from the config export: they depend on config or extensions the site lacks.', ['@names' => implode(', ', $skipped)]);
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
   * import does. Config the importer refuses or fails to save is written
   * from the exported records directly, so a config export after the update
   * does not drop it either.
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
    $importer = $this->configImporterFactory->get($comparer->createChangelist());
    $lock_available = $this->persistentLock->lockMayBeAvailable(ConfigImporter::LOCK_NAME);
    try {
      $importer->import();
      // The importer logs failed saves as errors and carries on.
      $errors = $importer->getErrors();
    }
    catch (ConfigImporterException $e) {
      $errors = [$e->getMessage()];
    }
    finally {
      // The importer releases its lock when it finishes, not when it fails.
      if ($lock_available && !$this->persistentLock->lockMayBeAvailable(ConfigImporter::LOCK_NAME)) {
        $this->persistentLock->release(ConfigImporter::LOCK_NAME);
      }
    }

    $missing = array_values(array_filter($names, fn (string $name): bool => !$this->activeStorage->exists($name)));
    if ($errors || $missing) {
      $written = $this->writeFromExport($missing, $export);
      $this->loggerFactory->get('icms_core_logic')->warning('Importing @names from the config export failed; wrote @written from their exported records instead. @errors', [
        '@names' => implode(', ', $names),
        '@written' => $written ? implode(', ', $written) : 'nothing',
        '@errors' => implode(' ', array_map('strval', $errors)),
      ]);
    }
  }

  /**
   * Writes missing config and its translations from the exported records.
   *
   * The fallback of importFromExport(): in dependency order, saved in syncing
   * mode, keeping the exported UUIDs.
   *
   * @param string[] $names
   *   The config names.
   * @param \Drupal\Core\Config\StorageInterface $export
   *   The export.
   *
   * @return string[]
   *   The config names written.
   */
  private function writeFromExport(array $names, StorageInterface $export): array {
    $records = $export->readMultiple($names);
    $sorted = (new ConfigDependencyManager())->setData($records)->sortAll();
    $written = [];
    foreach ($sorted as $name) {
      if ($this->activeStorage->exists($name)) {
        continue;
      }
      try {
        $entity_type_id = $this->configManager->getEntityTypeIdByName($name);
        if ($entity_type_id) {
          $entity = $this->entityTypeManager->getStorage($entity_type_id)->createFromStorageRecord($records[$name]);
          assert($entity instanceof ConfigEntityInterface);
          $entity->setSyncing(TRUE)->save();
        }
        else {
          $this->configManager->getConfigFactory()->getEditable($name)->setData($records[$name])->save(TRUE);
        }
        foreach ($export->getAllCollectionNames() as $collection) {
          $data = $export->createCollection($collection)->read($name);
          if ($data) {
            $this->getCollectionConfig($name, $collection)->setData($data)->save();
          }
        }
        $written[] = $name;
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('icms_core_logic')->error('Could not write @name from the config export; the config import brings it. @message', [
          '@name' => $name,
          '@message' => $e->getMessage(),
        ]);
      }
    }
    return $written;
  }

  /**
   * A config object of a collection, as ConfigImporter::importConfig() makes it.
   *
   * The collection's override service (e.g. language overrides) saves it with
   * its events, so locale records imported translations as customized.
   */
  private function getCollectionConfig(string $name, string $collection): StorableConfigBase {
    $override = $this->configManager->getConfigCollectionInfo()->getOverrideService($collection);
    if ($override) {
      return $override->createConfigObject($name, $collection);
    }
    return new Config($name, $this->activeStorage->createCollection($collection), $this->eventDispatcher, $this->typedConfigManager);
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
