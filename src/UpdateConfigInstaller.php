<?php

declare(strict_types=1);

namespace Drupal\icms_core_logic;

use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;

/**
 * Installs modules and config entities from update hooks like a config import.
 *
 * Update hooks run on every environment, while the config export comes from
 * the developer's run of them. A config entity an update hook creates gets a
 * fresh UUID on every other environment, and the config import that follows
 * (drush deploy) deletes and recreates it to match the exported UUID. That
 * drops its config translations or makes the import fail (ICMS-816).
 *
 * Once the export holds the result, config is therefore taken from the export:
 * modules are installed the way the config importer installs them, config
 * entities are created from their exported record. Before that, on the
 * developer's first run, both happen normally so the result can be exported.
 */
final class UpdateConfigInstaller {

  use DependencySerializationTrait;

  public function __construct(
    private ModuleInstallerInterface $moduleInstaller,
    private ModuleHandlerInterface $moduleHandler,
    private ModuleExtensionList $moduleExtensionList,
    private ConfigInstallerInterface $configInstaller,
    private ConfigManagerInterface $configManager,
    private EntityTypeManagerInterface $entityTypeManager,
    private StorageInterface $activeStorage,
    private StorageInterface $syncStorage,
  ) {}

  /**
   * Installs modules, taking their config from the export once it has them.
   *
   * When the exported core.extension lists every module to install, including
   * the dependencies installed along, they are installed in syncing mode like
   * the config importer does: their simple config is written from the export,
   * their config entities and optional config are left to the config import,
   * which creates them with the exported UUIDs. Otherwise the modules are
   * installed normally. Installed modules are skipped.
   *
   * @param string[] $modules
   *   The modules to install.
   *
   * @return bool
   *   TRUE when the modules were installed in syncing mode, so their config
   *   entities arrive with the config import; FALSE when they were installed
   *   normally or were all installed already.
   */
  public function installModules(array $modules): bool {
    $to_install = $this->getModulesToInstall($modules);
    if (!$to_install) {
      return FALSE;
    }

    $exported = $this->syncStorage->read('core.extension')['module'] ?? [];
    if (array_diff($to_install, array_keys($exported))) {
      $this->callAndReInjectIfNeeded(fn () => $this->moduleInstaller->install($modules));
      return FALSE;
    }

    // As ConfigImporter::doSyncStep() and ::processExtension() do. The kernel
    // carries both settings over to the container the install rebuilds.
    $this->configInstaller->setSyncing(TRUE)->setSourceStorage($this->syncStorage);
    try {
      $this->callAndReInjectIfNeeded(fn () => $this->moduleInstaller->install($modules));
    }
    finally {
      // Re-injected, this is the config installer of the current container.
      $this->configInstaller->setSyncing(FALSE);
    }
    return TRUE;
  }

  /**
   * Creates a missing config entity, from its exported record if there is one.
   *
   * The exported record keeps the project's UUID and values, so the config
   * import that follows sees no change. Without one, the entity is created
   * from the given record and gets exported afterwards.
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
   */
  public function importConfigEntity(string $config_name, ?array $record = NULL): bool {
    if ($this->activeStorage->exists($config_name)) {
      return FALSE;
    }

    $exported = $this->syncStorage->read($config_name);
    $record = is_array($exported) && $exported ? $exported : $record;
    $entity_type_id = $this->configManager->getEntityTypeIdByName($config_name);
    if (!$record || !$entity_type_id) {
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
    $list = $this->moduleExtensionList->getList();
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
