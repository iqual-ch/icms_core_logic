<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\ParagraphsType;
use PHPUnit\Framework\Attributes\Group;

/**
 * Update 9068 moves paragraph reference fields to the Paragraphs widget.
 *
 * Projects launched before ICMS-302 still edit nested paragraphs with Inline
 * Entity Form (ICMS-857).
 */
#[Group('icms_core_logic')]
class ParagraphsWidgetUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'text',
    'node',
    'entity_reference_revisions',
    'paragraphs',
    'inline_entity_form',
    'maxlength',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
    require_once dirname(__DIR__, 3) . '/icms_core_logic.install';

    ParagraphsType::create(['id' => 'text', 'label' => 'Text'])->save();
    ParagraphsType::create(['id' => 'button', 'label' => '~Button'])->save();
    ParagraphsType::create(['id' => 'link', 'label' => 'Link'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    $this->createReferenceField('paragraph', 'text', 'field_buttons', 'paragraph', ['button']);
    $this->createReferenceField('paragraph', 'text', 'field_links', 'paragraph', ['button', 'link']);
    $this->createReferenceField('paragraph', 'text', 'field_teaser', 'paragraph', ['link']);
    $this->createReferenceField('node', 'page', 'field_paragraphs', 'paragraph', ['text']);
    $this->createReferenceField('node', 'page', 'field_related', 'node', ['page'], 'entity_reference');

    EntityFormDisplay::create([
      'targetEntityType' => 'paragraph',
      'bundle' => 'text',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->setComponent('field_buttons', [
        'type' => 'inline_entity_form_complex',
        'weight' => 5,
        'region' => 'content',
        'settings' => ['form_mode' => 'default', 'override_labels' => FALSE],
        'third_party_settings' => ['maxlength' => ['maxlength_js' => 10, 'maxlength_js_label' => 'Left: @remaining', 'maxlength_js_enforce' => FALSE]],
      ])
      ->setComponent('field_links', [
        'type' => 'inline_entity_form_simple',
        'weight' => 6,
        'settings' => [
          'form_mode' => 'default',
          'override_labels' => TRUE,
          'label_singular' => 'Link item',
          'label_plural' => 'Link items',
        ],
      ])
      ->setComponent('field_teaser', [
        'type' => 'paragraphs',
        'weight' => 7,
        'settings' => ['title' => 'Teaser', 'title_plural' => 'Teasers', 'edit_mode' => 'closed'],
      ])
      ->save();

    EntityFormDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->setComponent('field_paragraphs', ['type' => 'inline_entity_form_complex', 'weight' => 1])
      ->setComponent('field_related', ['type' => 'inline_entity_form_complex', 'weight' => 2])
      ->save();
  }

  /**
   * Every paragraph reference field gets the widget; the rest is kept.
   */
  public function testWidgetsSwitched(): void {
    icms_core_logic_update_9068();

    $paragraph_display = EntityFormDisplay::load('paragraph.text.default');
    $buttons = $paragraph_display->getComponent('field_buttons');
    $this->assertSame('paragraphs', $buttons['type']);
    $this->assertSame(5, $buttons['weight']);
    $this->assertSame('content', $buttons['region']);
    $this->assertSame(['maxlength' => ['maxlength_js' => 10, 'maxlength_js_label' => 'Left: @remaining', 'maxlength_js_enforce' => FALSE]], $buttons['third_party_settings']);
    $this->assertSame('Button', $buttons['settings']['title']);
    $this->assertSame('open', $buttons['settings']['edit_mode']);
    $this->assertSame('dropdown', $buttons['settings']['add_mode']);
    $this->assertSame(['collapse_edit_all' => 'collapse_edit_all', 'duplicate' => 'duplicate'], $buttons['settings']['features']);

    $links = $paragraph_display->getComponent('field_links');
    $this->assertSame('paragraphs', $links['type']);
    $this->assertSame('Link item', $links['settings']['title']);
    $this->assertSame('Link items', $links['settings']['title_plural']);

    // A Paragraphs widget a project configured itself is not touched.
    $teaser = $paragraph_display->getComponent('field_teaser');
    $this->assertSame('Teaser', $teaser['settings']['title']);
    $this->assertSame('closed', $teaser['settings']['edit_mode']);

    $node_display = EntityFormDisplay::load('node.page.default');
    $this->assertSame('paragraphs', $node_display->getComponent('field_paragraphs')['type']);
    $this->assertSame('Text', $node_display->getComponent('field_paragraphs')['settings']['title']);
    // References to other entity types keep their widget.
    $this->assertSame('inline_entity_form_complex', $node_display->getComponent('field_related')['type']);

    // The form display no longer depends on Inline Entity Form.
    $this->assertNotContains('inline_entity_form', $paragraph_display->getDependencies()['module']);

    // A second run finds nothing to do.
    $this->assertSame('All paragraph reference fields already use the Paragraphs widget.', icms_core_logic_update_9068());
  }

  /**
   * Creates an entity reference (revisions) field.
   */
  protected function createReferenceField(string $entity_type, string $bundle, string $name, string $target_type, array $target_bundles, string $type = 'entity_reference_revisions'): void {
    if (!FieldStorageConfig::loadByName($entity_type, $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $entity_type,
        'type' => $type,
        'cardinality' => -1,
        'settings' => ['target_type' => $target_type],
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'settings' => [
        'handler' => 'default:' . $target_type,
        'handler_settings' => ['target_bundles' => array_combine($target_bundles, $target_bundles)],
      ],
    ])->save();
  }

}
