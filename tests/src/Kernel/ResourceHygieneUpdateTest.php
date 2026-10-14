<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The ICMS-799 update hooks for the database log and purging.
 *
 * Existing projects get a bounded watchdog table, no purge of the sitemap tags
 * on every cron run and the processor the scaffolded purge cron needs.
 */
#[Group('icms_core_logic')]
class ResourceHygieneUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'dblog',
    'purge',
    'purge_processor_cron',
    'purge_queuer_coretags',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['dblog', 'purge', 'purge_queuer_coretags']);
    require_once dirname(__DIR__, 3) . '/icms_core_logic.install';
  }

  /**
   * A large row limit is lowered; smaller or unlimited ones are kept.
   */
  public function testDblogRowLimit(): void {
    $this->config('dblog.settings')->set('row_limit', 1000000)->save();
    icms_core_logic_update_9062();
    $this->assertSame(10000, $this->config('dblog.settings')->get('row_limit'));

    $this->config('dblog.settings')->set('row_limit', 1000)->save();
    icms_core_logic_update_9062();
    $this->assertSame(1000, $this->config('dblog.settings')->get('row_limit'));

    $this->config('dblog.settings')->set('row_limit', 0)->save();
    icms_core_logic_update_9062();
    $this->assertSame(0, $this->config('dblog.settings')->get('row_limit'));
  }

  /**
   * The sitemap tags are blacklisted once, the rest of the list is kept.
   */
  public function testSitemapTagsBlacklisted(): void {
    $this->config('purge_queuer_coretags.settings')
      ->set('blacklist', ['4xx-response', 'routes'])
      ->save();

    icms_core_logic_update_9063();
    icms_core_logic_update_9063();

    $this->assertSame(
      ['4xx-response', 'routes', 'simple_sitemap'],
      $this->config('purge_queuer_coretags.settings')->get('blacklist'),
    );
  }

  /**
   * The drush queue work processor is enabled next to the existing ones.
   */
  public function testQueueWorkProcessorEnabled(): void {
    $processors = $this->container->get('purge.processors');
    $processors->setPluginsEnabled(['drush_purge_invalidate', 'cron']);
    $processors->reload();

    icms_core_logic_update_9064();
    $processors->reload();

    $enabled = $processors->getPluginsEnabled();
    sort($enabled);
    $this->assertSame(['cron', 'drush_purge_invalidate', 'drush_purge_queue_work'], $enabled);
  }

}
