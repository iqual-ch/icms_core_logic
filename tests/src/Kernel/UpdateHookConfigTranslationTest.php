<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\user\Entity\Role;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;

/**
 * Update hooks keep the base config in its own language (ICMS-839).
 *
 * Drush applies the config translation of the site default language when it
 * loads a config entity, because it negotiates no language. On a site whose
 * default language (French here) is not the language its config is stored in
 * (English), an update hook that loads a config entity with load() and saves
 * it writes the French translation into the base config: the German admin and
 * editor then show French labels.
 */
#[Group('icms_core_logic')]
class UpdateHookConfigTranslationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'text',
    'filter',
    'node',
    'language',
    'views',
    'entity_usage',
    'entity_reference_revisions',
    'paragraphs',
    'paragraphs_library',
    'field_group',
    'blokkli_starterkit',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();

    // The default language drush uses is the stored one, read when the
    // container is built (LanguageServiceProvider::alter()).
    $this->config('system.site')->set('default_langcode', 'fr')->save();
    $this->container->get('kernel')->rebuildContainer();
    $this->container = \Drupal::getContainer();

    require_once dirname(__DIR__, 3) . '/icms_core_logic.install';
  }

  /**
   * Update 9052 keeps the labels of paragraph types and roles.
   *
   * The paragraph types are the "Image du héros" the German editor showed.
   */
  public function testParagraphLibraryUpdateKeepsBaseLabels(): void {
    ParagraphsType::create(['id' => 'icms_layout_hero_image', 'label' => 'Hero Image'])->save();
    Role::create(['id' => 'content_editor', 'label' => 'Content editor'])->save();
    $this->translate('paragraphs.paragraphs_type.icms_layout_hero_image', ['label' => 'Image du héros']);
    $this->translate('user.role.content_editor', ['label' => 'Éditeur de contenu']);
    $this->assertSame('Image du héros', ParagraphsType::load('icms_layout_hero_image')->label(), 'load() applies the French translation.');

    icms_core_logic_update_9052();

    $type = $this->baseConfig('paragraphs.paragraphs_type.icms_layout_hero_image');
    $this->assertTrue($type['third_party_settings']['paragraphs_library']['allow_library_conversion'], 'The update ran.');
    $this->assertSame('Hero Image', $type['label']);
    $role = $this->baseConfig('user.role.content_editor');
    $this->assertContains('administer paragraphs library', $role['permissions'], 'The update ran.');
    $this->assertSame('Content editor', $role['label']);
  }

  /**
   * Update 9053 keeps the labels of the content overview.
   *
   * Saved with the French translation, the German content view also made
   * locale store its French strings as German interface translations (the
   * view's langcode), and locale's config refresh after the module installs
   * of the update then wrote them into every German view.
   */
  public function testContentViewUpdateKeepsBaseLabels(): void {
    View::create([
      'id' => 'content',
      'label' => 'Content',
      'base_table' => 'node_field_data',
      'base_field' => 'nid',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_plugin' => 'default',
          'display_title' => 'Default',
          'position' => 0,
          'display_options' => [
            'title' => 'Content',
            'fields' => [
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'plugin_id' => 'field',
                'entity_type' => 'node',
                'entity_field' => 'title',
                'label' => 'Title',
                'alter' => ['alter_text' => TRUE, 'text' => '{{ status }} {{ title }}'],
              ],
            ],
            'exposed_form' => [
              'type' => 'basic',
              'options' => ['reset_button_label' => 'Reset'],
            ],
          ],
        ],
      ],
    ])->save();
    $this->translate('views.view.content', [
      'label' => 'Contenu',
      'display' => [
        'default' => [
          'display_title' => 'Par défaut',
          'display_options' => [
            'title' => 'Contenu',
            'exposed_form' => ['options' => ['reset_button_label' => 'Réinitialiser']],
          ],
        ],
      ],
    ]);

    icms_core_logic_update_9053();

    $view = $this->baseConfig('views.view.content');
    $options = $view['display']['default']['display_options'];
    $this->assertArrayHasKey('latest_moderation_state', $options['fields'], 'The update ran.');
    $this->assertSame('Content', $view['label']);
    $this->assertSame('Default', $view['display']['default']['display_title']);
    $this->assertSame('Content', $options['title']);
    $this->assertSame('Reset', $options['exposed_form']['options']['reset_button_label']);
  }

  /**
   * The language selection update keeps the labels of the form tabs.
   *
   * Update 9057 and the bundles' language selection updates share the helper.
   */
  public function testLanguageSelectionUpdateKeepsBaseLabels(): void {
    NodeType::create(['type' => 'icms_page', 'name' => 'Page'])->save();
    ContentLanguageSettings::loadByEntityTypeBundle('node', 'icms_page')->save();
    EntityFormDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'icms_page',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->setComponent('langcode', ['type' => 'language_select', 'weight' => 5, 'region' => 'content'])
      ->setThirdPartySetting('field_group', 'group_tabs', [
        'children' => ['group_content'],
        'label' => 'Tabs',
        'region' => 'content',
        'parent_name' => '',
        'weight' => 0,
        'format_type' => 'tabs',
        'format_settings' => [],
      ])
      ->setThirdPartySetting('field_group', 'group_content', [
        'children' => ['langcode'],
        'label' => 'Content',
        'region' => 'content',
        'parent_name' => 'group_tabs',
        'weight' => 0,
        'format_type' => 'tab',
        'format_settings' => [],
      ])
      ->save();
    $this->translate('core.entity_form_display.node.icms_page.default', [
      'third_party_settings' => [
        'field_group' => [
          'group_tabs' => ['label' => 'Onglets'],
          'group_content' => ['label' => 'Contenu'],
        ],
      ],
    ]);

    _icms_core_logic_enable_original_language_selection('node', 'icms_page');

    $groups = $this->baseConfig('core.entity_form_display.node.icms_page.default')['third_party_settings']['field_group'];
    $this->assertSame([], $groups['group_content']['children'], 'The update ran.');
    $this->assertSame('Tabs', $groups['group_tabs']['label']);
    $this->assertSame('Content', $groups['group_content']['label']);
  }

  /**
   * Stores a French config translation.
   */
  protected function translate(string $name, array $data): void {
    $this->container->get('language_manager')->getLanguageConfigOverride('fr', $name)->setData($data)->save();
    // In drush, the translations exist before the update loads anything.
    $this->container->get('config.factory')->reset($name);
    $this->container->get('entity.memory_cache')->deleteAll();
  }

  /**
   * Reads config as it is stored, without translations.
   */
  protected function baseConfig(string $name): array {
    return $this->container->get('config.storage')->read($name);
  }

}
