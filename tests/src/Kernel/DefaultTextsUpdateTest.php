<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Kernel;

use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\texts\TextsInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * The default texts import in update hooks keeps the project's texts.
 *
 * The update to 1.7 imported the whole texts file with override on, which
 * reset every text editors had changed (ICMS-848), and aborted on a site with
 * a locked language, so the new texts were never created. A text the update
 * does not import is created by the first frontend request, in the site's
 * default language with the English fallback of the frontend (ICMS-862). A
 * key used in two contexts comes from a second texts file (ICMS-864).
 */
#[Group('icms_core_logic')]
#[RunTestsInSeparateProcesses]
class DefaultTextsUpdateTest extends EntityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
    'content_translation',
    'locale',
    'options',
    'texts',
    'texts_sync',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('texts');
    require_once dirname(__DIR__, 3) . '/icms_core_logic.install';
  }

  /**
   * A text editors changed keeps its wording, a missing text is created.
   */
  public function testUpdateKeepsChangedTexts(): void {
    $this->addLanguages(['de', 'fr', 'it']);
    $this->createText('load_more', 'listing', [
      'en' => 'Load more',
      'de' => 'Mehr laden',
      'fr' => 'Voir plus',
      'it' => 'Carica altro',
    ]);

    icms_core_logic_update_9056();

    $this->assertSame('Voir plus', $this->loadText('load_more', 'listing')->getTranslation('fr')->getTranslationText());
    $spam = $this->loadText('spam_protection_error', 'webform');
    foreach (['en', 'de', 'fr', 'it'] as $langcode) {
      $this->assertTrue($spam->hasTranslation($langcode), "spam_protection_error has $langcode.");
    }
    $this->assertSame('Vielen Dank!', $this->loadText('confirmation_title', 'webform')->getTranslation('de')->getTranslationText());
  }

  /**
   * A site that lacks or has locked a shipped language still gets the texts.
   */
  public function testUpdateOnSiteWithoutAllLanguages(): void {
    $this->addLanguages(['de', 'fr']);
    ConfigurableLanguage::load('fr')->set('locked', TRUE)->save();

    icms_core_logic_update_9056();

    $spam = $this->loadText('spam_protection_error', 'webform');
    $this->assertSame('Der Spam-Schutz konnte diese Übermittlung nicht überprüfen. Bitte versuchen Sie es erneut.', $spam->getTranslation('de')->getTranslationText());
    $this->assertFalse($spam->hasTranslation('fr'));
    $this->assertFalse($spam->hasTranslation('it'));
  }

  /**
   * After the update, the frontend finds every default text translated.
   *
   * The frontend requests each text with its English fallback, and the
   * translator creates a missing one in the site's default language with that
   * fallback. Texts the site already has keep their wording.
   */
  public function testFrontendRequestAfterUpdateFindsTranslatedTexts(): void {
    // The update message is translated into the default language.
    $this->installSchema('locale', ['locales_source', 'locales_target', 'locales_location']);
    $this->installConfig(['system', 'language']);
    $this->addLanguages(['de', 'fr', 'it']);
    $this->config('system.site')->set('default_langcode', 'fr')->save();
    $this->container->get('kernel')->rebuildContainer();
    $this->container = \Drupal::getContainer();
    $changed = ['fr' => 'Voir plus', 'de' => 'Weitere laden'];
    $this->createText('load_more', 'listing', $changed);

    icms_core_logic_update_9069();

    $translator = $this->container->get('texts.translator');
    $files = glob(dirname(__DIR__, 3) . '/texts/*.yml');
    $this->assertGreaterThan(1, count($files), 'The core ships more than one texts file.');
    foreach ($files as $file) {
      foreach (Yaml::parseFile($file) as $key => $text) {
        foreach (['fr', 'de'] as $langcode) {
          $translation = $translator->trans($key, [], [
            'context' => $text['context'],
            'default_translation' => $text['default']['en'],
            'langcode' => $langcode,
          ]);
          $expected = "{$text['context']}.$key" === 'listing.load_more' ? $changed[$langcode] : $text['default'][$langcode];
          $this->assertSame($expected, $translation, "{$text['context']}.$key in $langcode.");
        }
      }
    }
    $this->assertSame('Navigation par ancres', $this->loadText('nav_aria_label', 'anchor_navigation')->getTranslation('fr')->getTranslationText());
  }

  /**
   * A site that already has the core texts gets the teaser link label.
   *
   * Page teasers use teaser.link_label, which only the contenthub bundle
   * shipped, so sites without it never had the text (ICMS-864). It shares its
   * key with teaser_card.link_label and therefore comes from a second file.
   */
  public function testUpdateImportsTeaserLinkLabel(): void {
    // The update message is translated into the default language.
    $this->installSchema('locale', ['locales_source', 'locales_target', 'locales_location']);
    $this->installConfig(['system', 'language']);
    $this->addLanguages(['de', 'fr']);
    $this->config('system.site')->set('default_langcode', 'de')->save();
    $this->container->get('kernel')->rebuildContainer();
    $this->container = \Drupal::getContainer();
    $this->createText('link_label', 'teaser_card', ['en' => 'Read more', 'de' => 'Weiterlesen']);

    icms_core_logic_update_9070();

    $teaser = $this->loadText('link_label', 'teaser');
    $this->assertSame('Mehr lesen', $teaser->getTranslation('de')->getTranslationText());
    $this->assertSame('En savoir plus', $teaser->getTranslation('fr')->getTranslationText());
    $this->assertSame('Weiterlesen', $this->loadText('link_label', 'teaser_card')->getTranslation('de')->getTranslationText());
  }

  /**
   * Adds site languages next to English.
   *
   * @param string[] $langcodes
   *   The langcodes.
   */
  protected function addLanguages(array $langcodes): void {
    foreach ($langcodes as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }
  }

  /**
   * Creates a text the way the translation UI stores it.
   *
   * @param string $key
   *   The text key.
   * @param string $context
   *   The text context.
   * @param array<string, string> $translations
   *   Text by langcode, the first one is the original language.
   */
  protected function createText(string $key, string $context, array $translations): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('texts');
    $text = $storage->create([
      'key' => $key,
      'context' => $context,
      'langcode' => array_key_first($translations),
      'translation' => reset($translations),
    ]);
    foreach (array_slice($translations, 1, NULL, TRUE) as $langcode => $value) {
      $text->addTranslation($langcode, ['translation' => $value]);
    }
    $text->save();
  }

  /**
   * Loads a text.
   */
  protected function loadText(string $key, string $context): TextsInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('texts');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('key', $key)
      ->condition('context', $context)
      ->execute();
    $this->assertCount(1, $ids, "Text $context.$key exists once.");
    $text = $storage->loadUnchanged(reset($ids));
    $this->assertInstanceOf(TextsInterface::class, $text);
    return $text;
  }

}
