<?php

declare(strict_types=1);

namespace Drupal\icms_core_logic\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\icms_core_logic\SiteShell;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders the frontend in the site shell for pages only the frontend serves.
 *
 * Entity pages get the shell through hook_ENTITY_TYPE_view_alter(); this
 * controller covers the frontend's own routes (search, listings), reached
 * from SiteShellExceptionSubscriber for backend 404s.
 */
final class SiteShellController extends ControllerBase {

  public function __construct(
    private readonly SiteShell $siteShell,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('icms_core_logic.site_shell'), $container->get('request_stack'));
  }

  /**
   * Shows the frontend page given as ?path= (a frontend path with query).
   *
   * A path the frontend does not serve is a 404 here as everywhere else.
   */
  public function page(Request $request): array {
    $path = (string) $request->query->get('path', '/');
    if (!str_starts_with($path, '/') || str_starts_with($path, '//') || !$this->siteShell->isFrontendPath($path)) {
      throw new NotFoundHttpException();
    }
    return $this->siteShell->build($request, $path);
  }

  /**
   * Access: only editors, only on the backend domain.
   */
  public function access(): AccessResultInterface {
    $request = $this->requestStack->getCurrentRequest();
    return AccessResult::allowedIf($request !== NULL && $this->siteShell->applies($request))
      ->addCacheContexts(['user.permissions', 'url.site', 'request_format']);
  }

}
