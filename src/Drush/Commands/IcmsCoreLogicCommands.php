<?php

namespace Drupal\icms_core_logic\Drush\Commands;

use Drupal\Core\File\FileSystemInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
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
  ) {
    parent::__construct();
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
