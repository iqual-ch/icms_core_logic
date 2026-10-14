<?php

declare(strict_types=1);

namespace Drupal\icms_core_logic\EventSubscriber;

use Drupal\Core\EventSubscriber\HttpExceptionSubscriberBase;
use Drupal\Core\Url;
use Drupal\icms_core_logic\SiteShell;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Shows the site shell instead of a backend 404 for frontend-only pages.
 *
 * Pages only the frontend serves (search, listings — the routes it registers
 * in frontend_routing.settings) do not exist on the backend domain. For an
 * editor they are rendered in the shell like core renders its custom 404 page:
 * through a sub-request to the shell route. Everything else Drupal does not
 * know stays a real 404 — a backend path that does not exist, a frontend path
 * that does not exist — so nothing ends up in a redirect loop.
 */
final class SiteShellExceptionSubscriber extends HttpExceptionSubscriberBase {

  public function __construct(
    private readonly SiteShell $siteShell,
    private readonly HttpKernelInterface $httpKernel,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function getHandledFormats(): array {
    return ['html'];
  }

  /**
   * {@inheritdoc}
   *
   * Before the custom 404 page (CustomPageExceptionHtmlSubscriber, -50).
   */
  protected static function getPriority(): int {
    return -40;
  }

  /**
   * Handles a 404 by rendering the frontend page in the shell.
   */
  public function on404(ExceptionEvent $event): void {
    $request = $event->getRequest();
    if (!$event->isMainRequest() || $request->getMethod() !== 'GET' || !$this->siteShell->applies($request)) {
      return;
    }
    if (!$this->siteShell->isFrontendPath($request->getPathInfo())) {
      return;
    }

    $url = Url::fromRoute('icms_core_logic.site_shell', [], ['query' => ['path' => $request->getRequestUri()]])->toString(TRUE)->getGeneratedUrl();
    $subRequest = Request::create($url, 'GET', [], $request->cookies->all(), [], $request->server->all());
    if ($request->hasSession()) {
      $subRequest->setSession($request->getSession());
    }
    $response = $this->httpKernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
    // The page exists on the frontend: this is not a 404.
    $response->setStatusCode(200);
    $event->allowCustomResponseCode();
    $event->setResponse($response);
  }

}
