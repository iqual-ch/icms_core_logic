<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Unit;

use Drupal\icms_core_logic\BackendDomain;
use Drupal\icms_core_logic\Config\BackendDomainConfigOverride;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the sitemap base URL override on the backend domain.
 */
#[Group('icms_core_logic')]
class BackendDomainConfigOverrideTest extends UnitTestCase {

  /**
   * Creates the override for a current request.
   */
  private function override(?Request $request): BackendDomainConfigOverride {
    $stack = new RequestStack();
    if ($request) {
      $stack->push($request);
    }
    return new BackendDomainConfigOverride(new BackendDomain(), $stack);
  }

  /**
   * On a backend request the sitemap lists the frontend domain.
   */
  public function testOverridesTheSitemapBaseUrlOnTheBackend(): void {
    $override = $this->override(Request::create('https://backend.www.example.com/admin/config/search/simplesitemap'));
    $this->assertSame(
      ['simple_sitemap.settings' => ['base_url' => 'https://www.example.com']],
      $override->loadOverrides(['system.site', 'simple_sitemap.settings']),
    );
    $this->assertSame([], $override->loadOverrides(['system.site']));
    $this->assertContains('url.site', $override->getCacheableMetadata('simple_sitemap.settings')->getCacheContexts());
  }

  /**
   * Elsewhere (frontend requests, Drush) nothing is overridden.
   */
  public function testLeavesOtherContextsAlone(): void {
    $this->assertSame([], $this->override(Request::create('https://www.example.com/sitemap.xml'))->loadOverrides(['simple_sitemap.settings']));
    $this->assertSame([], $this->override(NULL)->loadOverrides(['simple_sitemap.settings']));
  }

}
