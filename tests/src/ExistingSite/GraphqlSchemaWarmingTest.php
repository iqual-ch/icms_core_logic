<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\ExistingSite;

use Drupal\language\Config\LanguageConfigOverride;
use GraphQL\Language\Printer;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests `drush icms:warm-graphql-schema`.
 */
#[Group('icms_core_logic')]
class GraphqlSchemaWarmingTest extends ExistingSiteBase {

  /**
   * A field whose description ends up in the generated schema.
   */
  private const FIELD_CONFIG = 'field.field.node.icms_page.field_icms_paragraphs';

  private const DESCRIPTION = 'Schema warming test description';

  private ?LanguageConfigOverride $override = NULL;

  private array $originalOverride = [];

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->override) {
      $this->originalOverride ? $this->override->setData($this->originalOverride)->save() : $this->override->delete();
      \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
    }
    \Drupal::service('cache.graphql.ast')->deleteAll();
    parent::tearDown();
  }

  /**
   * Every language gets its own schema, generated in that language.
   */
  public function testWarmsEveryLanguageInItsOwnTranslation(): void {
    $languageManager = \Drupal::languageManager();
    $default = $languageManager->getDefaultLanguage()->getId();
    $others = array_diff(array_keys($languageManager->getLanguages()), [$default]);
    if (!$others) {
      $this->markTestSkipped('Needs a second language.');
    }
    $translated = reset($others);

    // Translate the field description in one non-default language only.
    $this->override = $languageManager->getLanguageConfigOverride($translated, self::FIELD_CONFIG);
    $this->originalOverride = $this->override->get() ?? [];
    $this->override->set('description', self::DESCRIPTION)->save();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    $astCache = \Drupal::service('cache.graphql.ast');
    $astCache->deleteAll();

    $process = new Process([DRUPAL_ROOT . '/../vendor/bin/drush', 'icms:warm-graphql-schema'], DRUPAL_ROOT);
    $process->setTimeout(600)->run();
    $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());

    $schemaId = \Drupal::entityTypeManager()->getStorage('graphql_server')->load('graphql')->get('schema');
    foreach ($languageManager->getLanguages() as $language) {
      $langcode = $language->getId();
      $cache = $astCache->get("schema:$schemaId:graphql:$langcode");
      $this->assertNotFalse($cache, "Schema AST cached for $langcode.");
      $sdl = Printer::doPrint($cache->data);
      if ($langcode === $translated) {
        $this->assertStringContainsString(self::DESCRIPTION, $sdl, "Schema for $langcode uses its own translation.");
      }
      else {
        $this->assertStringNotContainsString(self::DESCRIPTION, $sdl, "Schema for $langcode does not leak the $translated translation.");
      }
    }
  }

}
