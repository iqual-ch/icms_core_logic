<?php

declare(strict_types=1);

namespace Drupal\icms_core_logic;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\icms_core_logic\Session\SessionConfiguration;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Lets the session cookie of a backend domain cover its frontend domain.
 *
 * @see \Drupal\icms_core_logic\Session\SessionConfiguration
 */
final class IcmsCoreLogicServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if (!$container->hasDefinition('session_configuration')) {
      return;
    }
    $container->getDefinition('session_configuration')
      ->setClass(SessionConfiguration::class)
      ->addArgument(new Reference('icms_core_logic.backend_domain'));
  }

}
