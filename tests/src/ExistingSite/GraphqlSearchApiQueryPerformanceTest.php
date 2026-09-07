<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\ExistingSite;

use Drupal\Core\Database\Database;
use Drupal\Core\Render\RenderContext;
use Drupal\graphql\Entity\ServerInterface;
use Drupal\graphql\GraphQL\Execution\ExecutionResult;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Utility\Utility;
use GraphQL\Server\OperationParams;
use PHPUnit\Framework\Attributes\Group;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Performance tripwires for the searchApiQuery GraphQL field.
 *
 * These tests assert structural budgets instead of wall-clock times (which
 * are flaky in CI): a facets/count-only search query — the query the
 * frontend's FacetedSearchUI runs on every listing page — must never touch
 * the node entity storage. graphql_search_api_query 1.2.3
 * (https://drupal.org/i/3616707) defers result entity loading and facet
 * filtering to the resolvers that need them; this test guards that behavior
 * against module updates and local overrides.
 */
#[Group('icms_core_logic')]
class GraphqlSearchApiQueryPerformanceTest extends ExistingSiteBase {

  /**
   * Maximum number of database queries for a facets/count-only search query.
   *
   * Regression tripwire with generous headroom, not a benchmark. A per-result
   * entity load or render shows up as dozens of additional queries.
   */
  protected const FACETS_QUERY_BUDGET = 60;

  /**
   * A facets/count-only query must not load or render result entities.
   */
  public function testFacetsOnlyQueryDoesNotTouchNodeStorage(): void {
    $index = Index::load('content_search');
    $this->assertNotNull($index, 'The content_search index exists.');

    // Make sure at least two matching nodes are indexed, so the facet output
    // and the (pre-fix) per-result work are actually exercised. icms_page
    // uses the editorial workflow, so the nodes must be published through
    // their moderation state — a bare "status" would leave them as drafts,
    // which the entity_status processor excludes from the index.
    $nodes = [
      $this->createNode([
        'type' => 'icms_page',
        'title' => 'GraphQL performance test page A',
        'moderation_state' => 'published',
      ]),
      $this->createNode([
        'type' => 'icms_page',
        'title' => 'GraphQL performance test page B',
        'moderation_state' => 'published',
      ]),
    ];
    // Index only the test nodes; a bare indexItems() would work through the
    // whole tracker backlog of the site the test runs against.
    $itemIds = array_map(
      fn ($node) => Utility::createCombinedId('entity:node', $node->id() . ':' . $node->language()->getId()),
      $nodes,
    );
    $items = $index->loadItemsMultiple($itemIds);
    $this->assertCount(2, $items, 'The test nodes load through the node datasource.');
    $this->assertEqualsCanonicalizing($itemIds, $index->indexSpecificItems($items), 'The test nodes are indexed.');

    // Drop the per-request and persistent entity caches: a regression that
    // re-loads result entities must surface as node storage queries instead
    // of being served from the entity cache.
    \Drupal::entityTypeManager()->getStorage('node')->resetCache();

    $server = $this->getGraphqlServer();
    // The unique alias busts the GraphQL results cache so the query is
    // actually executed instead of served from cache.graphql.results.
    $query = <<<GQL
    query perf_{$this->randomMachineName()} {
      searchApiQuery(indexId: "content_search", facets: ["type", "topics"]) {
        count
        facets {
          field
          label
          count
          filter {
            id
            label
          }
        }
      }
    }
    GQL;

    Database::startLog('icms_graphql_perf');
    $result = $this->executeGraphqlOperation($server, $query);
    $queries = Database::getLog('icms_graphql_perf');

    $this->assertSame([], $result->errors, 'The query executed without errors.');
    $data = $result->data['searchApiQuery'] ?? NULL;
    $this->assertNotNull($data);
    $this->assertGreaterThanOrEqual(2, $data['count'], 'The indexed test nodes are found.');
    $typeFacets = array_column(
      array_filter($data['facets'], fn (array $facet) => $facet['field'] === 'type'),
      'count',
      NULL,
    );
    $this->assertNotEmpty($typeFacets, 'The type facet is computed.');

    // The core assertion: no query may touch the node entity storage tables.
    // Entity loads always hit node_field_data; dedicated field tables start
    // with "node__". The search itself only reads search_api_db_* tables.
    $nodeStorageQueries = array_values(array_filter(
      array_column($queries, 'query'),
      fn (string $sql) => (bool) preg_match('/\bnode(_field_data|_field_revision|_revision|__[a-z0-9_]+)\b/', $sql),
    ));
    $this->assertSame([], $nodeStorageQueries, sprintf(
      'A facets/count-only search query must not load result entities, but %d node storage queries ran.',
      count($nodeStorageQueries),
    ));

    // Secondary tripwire against N+1 explosions (e.g. per-facet-value work).
    $this->assertLessThanOrEqual(static::FACETS_QUERY_BUDGET, count($queries), sprintf(
      'A facets/count-only search query ran %d database queries (budget: %d).',
      count($queries),
      static::FACETS_QUERY_BUDGET,
    ));
  }

  /**
   * Loads the default GraphQL server.
   */
  protected function getGraphqlServer(): ServerInterface {
    $server = \Drupal::entityTypeManager()->getStorage('graphql_server')->load('graphql');
    assert($server instanceof ServerInterface);
    return $server;
  }

  /**
   * Executes a GraphQL operation in an isolated render context.
   */
  protected function executeGraphqlOperation(ServerInterface $server, string $query, array $variables = []): ExecutionResult {
    $params = OperationParams::create([
      'query' => $query,
      'variables' => $variables,
    ]);
    return \Drupal::service('renderer')->executeInRenderContext(
      new RenderContext(),
      fn () => $server->executeOperation($params),
    );
  }

}
