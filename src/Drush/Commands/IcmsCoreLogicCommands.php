<?php

namespace Drupal\icms_core_logic\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageDefault;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use GraphQL\Server\OperationParams;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * ICMS Core Logic Drush commands.
 */
final class IcmsCoreLogicCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructs an IcmsCoreLogicCommands object.
   */
  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LanguageDefault $languageDefault,
    #[Autowire(service: 'string_translation')]
    private readonly TranslationManager $translation,
  ) {
    parent::__construct();
  }

  /**
   * Runs the ICMS post-deploy steps.
   *
   * Wired into the Platform.sh post_deploy hook via scripts/post-deploy.sh.
   * Schema warming is best effort; a failed purge fails the command.
   */
  #[CLI\Command(name: 'icms:post-deploy')]
  #[CLI\Option(name: 'skip', description: 'Comma-separated steps to skip: warm (GraphQL schema warming), purge (HTTP response purge).')]
  #[CLI\Usage(name: 'icms:post-deploy', description: 'Warms the GraphQL schema for every language, then purges all cached HTTP responses.')]
  #[CLI\Usage(name: 'icms:post-deploy --skip=purge', description: 'Warms the GraphQL schema only.')]
  public function postDeploy(array $options = ['skip' => '']): int {
    $skip = array_filter(array_map('trim', explode(',', (string) $options['skip'])));
    if ($unknown = array_diff($skip, ['warm', 'purge'])) {
      throw new \InvalidArgumentException(sprintf('Unknown post-deploy step(s): %s.', implode(', ', $unknown)));
    }

    $start = microtime(TRUE);
    $exit = self::EXIT_SUCCESS;

    if (!in_array('warm', $skip, TRUE) && $this->step('warm', fn() => $this->warmAllLanguages()) !== self::EXIT_SUCCESS) {
      $this->logger()->warning(dt('GraphQL schema warming failed. The first request per language builds the schema instead.'));
    }
    if (!in_array('purge', $skip, TRUE)) {
      $exit = $this->step('purge', fn() => $this->purgeHttpResponses());
    }

    $message = dt('Post-deploy finished in @duration.', ['@duration' => $this->duration($start)]);
    $exit === self::EXIT_SUCCESS ? $this->logger()->success($message) : $this->logger()->error($message);
    return $exit;
  }

  /**
   * Warms the GraphQL schema caches for all servers and enabled languages.
   *
   * The schema is generated and cached per interface language. A CLI process
   * cannot switch language once services hold translated data, so every
   * language is warmed in its own process.
   */
  #[CLI\Command(name: 'icms:warm-graphql-schema', aliases: ['icms-warm-gql'])]
  #[CLI\Option(name: 'langcode', description: 'Warm a single language only.')]
  #[CLI\Usage(name: 'icms:warm-graphql-schema', description: 'Builds and caches the GraphQL schema for every server and enabled language.')]
  public function warmGraphqlSchema(array $options = ['langcode' => self::REQ]): int {
    return is_string($options['langcode']) ? $this->warmLanguage($options['langcode']) : $this->warmAllLanguages();
  }

  /**
   * Warms every enabled language in a child drush process.
   */
  private function warmAllLanguages(): int {
    if (!$this->moduleHandler->moduleExists('graphql')) {
      $this->logger()->warning(dt('GraphQL is not installed. Nothing to warm.'));
      return self::EXIT_SUCCESS;
    }
    $exit = self::EXIT_SUCCESS;
    foreach ($this->languageManager->getLanguages() as $language) {
      $process = $this->processManager()->drush(Drush::aliasManager()->getSelf(), 'icms:warm-graphql-schema', [], ['langcode' => $language->getId()] + $this->redispatchOptions());
      $process->run($process->showRealtime());
      if (!$process->isSuccessful()) {
        $exit = self::EXIT_FAILURE;
      }
    }
    return $exit;
  }

  /**
   * Builds the schema of every GraphQL server in the given language.
   */
  private function warmLanguage(string $langcode): int {
    $language = $this->languageManager->getLanguage($langcode);
    if (!$language) {
      throw new \InvalidArgumentException(sprintf('Unknown language "%s".', $langcode));
    }
    // What LanguageRequestSubscriber does for an HTTP request in this language.
    $this->languageDefault->set($language);
    if ($this->languageManager instanceof ConfigurableLanguageManagerInterface) {
      $this->languageManager->setConfigOverrideLanguage($language);
    }
    $this->translation->setDefaultLangcode($langcode);
    $this->languageManager->reset();

    $servers = $this->entityTypeManager->getStorage('graphql_server')->loadMultiple();
    if (!$servers) {
      $this->logger()->warning(dt('No GraphQL servers configured. Nothing to warm.'));
      return self::EXIT_SUCCESS;
    }
    foreach ($servers as $server) {
      $start = microtime(TRUE);
      $server->executeOperation(OperationParams::create(['query' => '{ __typename }']));
      $this->logger()->success(dt('Warmed GraphQL schema for server "@server", language "@langcode" (@duration).', [
        '@server' => $server->id(),
        '@langcode' => $langcode,
        '@duration' => $this->duration($start),
      ]));
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Purges all cached HTTP responses (cache tag "http_response").
   */
  private function purgeHttpResponses(): int {
    if (!$this->moduleHandler->moduleExists('purge_drush')) {
      $this->logger()->warning(dt('The purge_drush module is not installed. Skipping the HTTP response purge.'));
      return self::EXIT_SUCCESS;
    }
    $process = $this->processManager()->drush(Drush::aliasManager()->getSelf(), 'p:invalidate', ['tag', 'http_response'], ['yes' => TRUE] + $this->redispatchOptions());
    $process->run($process->showRealtime());
    return $process->isSuccessful() ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * Runs a callback and logs its outcome and duration as a named step.
   */
  private function step(string $name, callable $callback): int {
    $start = microtime(TRUE);
    $exit = $callback();
    $context = ['@step' => $name, '@duration' => $this->duration($start)];
    if ($exit === self::EXIT_SUCCESS) {
      $this->logger()->success(dt('Step "@step" finished in @duration.', $context));
    }
    else {
      $this->logger()->error(dt('Step "@step" failed after @duration.', $context));
    }
    return $exit;
  }

  /**
   * Global CLI options to pass on to child drush processes.
   */
  private function redispatchOptions(): array {
    return array_diff_key(Drush::redispatchOptions(), ['skip' => 1, 'langcode' => 1]);
  }

  private function duration(float $start): string {
    return sprintf('%.1f s', microtime(TRUE) - $start);
  }

  /**
   * Generates the icms_project_translations module for project-specific translations.
   */
  #[CLI\Command(name: 'icms:generate-translations-module', aliases: ['icms-gen-trans'])]
  #[CLI\Usage(name: 'icms:generate-translations-module', description: 'Creates the icms_project_translations module structure.')]
  public function generateTranslationsModule(): int {
    $moduleName = 'icms_project_translations';
    $moduleLabel = 'ICMS Project Translations';
    $moduleDescription = 'Container module for project-specific translations and texts.';

    // Determine the custom modules directory.
    $customModulesPath = DRUPAL_ROOT . '/modules/custom';

    // Check if custom directory exists, create if not.
    if (!is_dir($customModulesPath)) {
      $this->logger()->warning('Custom modules directory does not exist. Creating it at: @path', ['@path' => $customModulesPath]);
      $this->fileSystem->mkdir($customModulesPath, 0755, TRUE);
    }

    $modulePath = $customModulesPath . '/' . $moduleName;

    // Check if module already exists.
    if (is_dir($modulePath)) {
      $this->logger()->error('Module @module already exists at @path', [
        '@module' => $moduleName,
        '@path' => $modulePath,
      ]);
      return self::EXIT_FAILURE;
    }

    // Create Symfony Filesystem instance for easier file operations.
    $filesystem = new Filesystem();

    try {
      // Create module directory.
      $this->fileSystem->mkdir($modulePath, 0755, TRUE);
      $this->logger()->success(dt('Created module directory: @path', ['@path' => $modulePath]));

      // Create the .info.yml file.
      $infoContent = <<<YAML
name: '$moduleLabel'
type: module
description: '$moduleDescription'
package: Custom
core_version_requirement: ^10 || ^11

YAML;

      $infoFilePath = $modulePath . '/' . $moduleName . '.info.yml';
      file_put_contents($infoFilePath, $infoContent);
      $this->logger()->success(dt('Created info file: @file', ['@file' => $infoFilePath]));

      // Create empty translations directory.
      $translationsPath = $modulePath . '/translations';
      $this->fileSystem->mkdir($translationsPath, 0755, TRUE);
      $this->logger()->success(dt('Created translations directory: @path', ['@path' => $translationsPath]));

      // Create .gitkeep file to ensure directory is tracked.
      $gitkeepTranslations = $translationsPath . '/.gitkeep';
      file_put_contents($gitkeepTranslations, '');
      $this->logger()->success(dt('Created .gitkeep in translations directory'));

      // Create empty texts directory.
      $textsPath = $modulePath . '/texts';
      $this->fileSystem->mkdir($textsPath, 0755, TRUE);
      $this->logger()->success(dt('Created texts directory: @path', ['@path' => $textsPath]));

      // Create .gitkeep file to ensure directory is tracked.
      $gitkeepTexts = $textsPath . '/.gitkeep';
      file_put_contents($gitkeepTexts, '');
      $this->logger()->success(dt('Created .gitkeep in texts directory'));

      // Create README.md with usage instructions.
      $readmeContent = <<<'MARKDOWN'
# ICMS Project Translations

This module serves as a container for project-specific translations and texts.

## Purpose

This module provides a dedicated location for:
- Custom interface translations
- Custom translations from the texts module

## Usage

This module is automatically generated and should be enabled in your project:

```bash
drush en icms_project_translations
```

### Export translations

Export customized translations to version control:

```bash
ddev drush @self locale:export de --types=customized > drupal/docroot/modules/custom/icms_project_translations/translations/de.po
```

- `@self`: Source environment (use `@prod` for production)
- `de`: Language code to export
- `--types=customized`: Export only custom translations (excludes contrib defaults)

### Import translations

Import translations from `.po` files into the site:

```bash
ddev drush locale:import de docroot/modules/custom/icms_project_translations/translations/de.po --type=customized --override=all
```

- `--type=customized`: Mark as customized translations
- `--override=all`: Override existing translations

## Directory Structure

- **translations/**: Place custom translation files here (e.g., `.po` files)
- **texts/**: Place custom translations from the texts module here.


MARKDOWN;

      $readmeFilePath = $modulePath . '/README.md';
      file_put_contents($readmeFilePath, $readmeContent);
      $this->logger()->success(dt('Created README.md: @file', ['@file' => $readmeFilePath]));

      // Final success message.
      $this->logger()->success('');
      $this->logger()->success(dt('Successfully generated @module module!', ['@module' => $moduleName]));
      $this->logger()->success(dt('Location: @path', ['@path' => $modulePath]));
      $this->logger()->success('');
      $this->logger()->success(dt('Next steps:'));
      $this->logger()->success(dt('  1. Enable the module: drush en @module', ['@module' => $moduleName]));
      $this->logger()->success(dt('  2. Add your translation files to @translations', ['@translations' => $translationsPath]));
      $this->logger()->success(dt('  3. Add your text resources to @texts', ['@texts' => $textsPath]));
      $this->logger()->success('');

      return self::EXIT_SUCCESS;
    }
    catch (\Exception $e) {
      $this->logger()->error(dt('Failed to generate module: @message', ['@message' => $e->getMessage()]));
      return self::EXIT_FAILURE;
    }
  }

}
