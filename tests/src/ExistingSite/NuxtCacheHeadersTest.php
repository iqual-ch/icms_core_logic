<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\ExistingSite;

use Drupal\Core\Site\Settings;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the cache metadata headers of the decoupled caching contract.
 *
 * The Nuxt frontend derives the Cache-Control (s-maxage) and Cache-Tag
 * headers of its SSR responses from the X-Nuxt-Expires and cache tag
 * headers Drupal emits on GraphQL responses, and Varnish purges cached
 * objects via the tags in Purge-Cache-Tags (ICMS-86/ICMS-87, regression
 * ICMS-689). This test pins the Drupal side of that contract.
 *
 * @group icms_core_logic
 */
class NuxtCacheHeadersTest extends ExistingSiteBase {

  /**
   * GraphQL responses must carry the headers the frontend consumes.
   */
  public function testGraphqlResponseCarriesNuxtCacheHeaders(): void {
    $response = \Drupal::httpClient()->post($this->baseUrl . '/de/graphql', [
      'headers' => [
        'Content-Type' => 'application/json',
        'x-drupal-graphql-token' => Settings::get('access_graphql.token'),
      ],
      'body' => json_encode(['query' => '{ __typename }']),
      'verify' => FALSE,
      'http_errors' => FALSE,
    ]);

    $this->assertSame(200, $response->getStatusCode());

    // The frontend reads X-Nuxt-Expires to compute s-maxage: 0 means
    // uncacheable, -1 permanent, anything else an expiry timestamp.
    $this->assertTrue($response->hasHeader('X-Nuxt-Expires'), 'X-Nuxt-Expires header is present');
    $expires = $response->getHeaderLine('X-Nuxt-Expires');
    $this->assertMatchesRegularExpression('/^(-1|\d+)$/', $expires);
    $this->assertNotSame('0', $expires, 'GraphQL response is cacheable');

    // Encoded tags for the frontend-internal data cache. Banned tags
    // (graphql_response, http_response) must have been filtered out.
    $this->assertTrue($response->hasHeader('X-Nuxt-Cache-Tags'), 'X-Nuxt-Cache-Tags header is present');
    $nuxtTags = explode(' ', $response->getHeaderLine('X-Nuxt-Cache-Tags'));
    $this->assertNotContains('graphql_response', $nuxtTags);
    $this->assertNotContains('http_response', $nuxtTags);

    // Raw tags for Varnish xkey purging (normalized to xkey in the VCL).
    $this->assertTrue($response->hasHeader('Purge-Cache-Tags'), 'Purge-Cache-Tags header is present');
    $purgeTags = explode(' ', $response->getHeaderLine('Purge-Cache-Tags'));
    $this->assertContains('graphql_response', $purgeTags);
  }

  /**
   * Any cacheable Drupal response must carry Purge-Cache-Tags for Varnish.
   */
  public function testDrupalPageCarriesPurgeCacheTags(): void {
    $this->visit('/de/user/login');
    $this->assertSession()->statusCodeEquals(200);
    $headers = $this->getSession()->getResponseHeaders();
    $this->assertArrayHasKey('Purge-Cache-Tags', $headers);
  }

}
