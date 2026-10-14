<?php

declare(strict_types=1);

namespace Drupal\icms_core_logic\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\icms_core_logic\BackendDomain;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Points Drupal-generated public URLs at the frontend domain.
 *
 * The sitemap lists the public site, but its generation runs on whichever
 * host triggered it: the backend domain when an editor regenerates it from
 * the UI or cron runs over HTTP. simple_sitemap's base URL is therefore set
 * to the frontend of the backend domain the request came in on; outside of a
 * backend request (Drush, where the primary route is the URI) nothing is
 * overridden.
 */
final class BackendDomainConfigOverride implements ConfigFactoryOverrideInterface {

  public function __construct(
    private readonly BackendDomain $backendDomain,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names) {
    if (!in_array('simple_sitemap.settings', $names, TRUE)) {
      return [];
    }
    $request = $this->requestStack->getCurrentRequest();
    $url = $request ? $this->backendDomain->getFrontendUrl($request) : NULL;
    return $url === NULL ? [] : ['simple_sitemap.settings' => ['base_url' => $url]];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix() {
    return 'icms_core_logic_backend_domain';
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name) {
    $metadata = new CacheableMetadata();
    if ($name === 'simple_sitemap.settings') {
      $metadata->addCacheContexts(['url.site']);
    }
    return $metadata;
  }

}
