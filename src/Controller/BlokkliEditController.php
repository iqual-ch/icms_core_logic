<?php

declare(strict_types=1);

namespace Drupal\icms_core_logic\Controller;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\EventSubscriber\RedirectResponseSubscriber;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\icms_core_logic\BlokkliEditor;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends editors from a node's Drupal pages into the blökkli editor.
 */
final class BlokkliEditController extends ControllerBase {

  public function __construct(
    private readonly BlokkliEditor $blokkliEditor,
    private readonly RedirectResponseSubscriber $redirectResponseSubscriber,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('icms_core_logic.blokkli_editor'), $container->get('redirect_response_subscriber'));
  }

  /**
   * Redirects to the frontend's editor for the node.
   *
   * Content listings add ?destination to their operation links, which core
   * applies to any redirect; the editor itself returns to the page. The route
   * is no_cache so that this method runs on every request.
   */
  public function open(NodeInterface $node): TrustedRedirectResponse {
    $this->redirectResponseSubscriber->setIgnoreDestination();
    return new TrustedRedirectResponse($this->blokkliEditor->getUrl($node)->toString());
  }

  /**
   * Access: the node must be a blökkli-enabled type the user may edit.
   */
  public function access(NodeInterface $node): AccessResultInterface {
    return $this->blokkliEditor->access($node);
  }

}
