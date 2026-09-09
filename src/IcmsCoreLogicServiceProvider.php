<?php

declare(strict_types=1);

namespace Drupal\icms_core_logic;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Shares the session cookie between the frontend and the backend domain.
 *
 * Only active when the backend runs on its own domain (see BackendDomain) and
 * services.yml does not configure a cookie domain itself.
 */
final class IcmsCoreLogicServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $cookieDomain = BackendDomain::fromEnvironment()->getCookieDomain();
    if ($cookieDomain === NULL || !$container->hasParameter('session.storage.options')) {
      return;
    }
    $options = $container->getParameter('session.storage.options');
    if (!empty($options['cookie_domain'])) {
      return;
    }
    $options['cookie_domain'] = $cookieDomain;
    $container->setParameter('session.storage.options', $options);
  }

}
