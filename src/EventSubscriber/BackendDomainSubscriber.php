<?php

declare(strict_types=1);

namespace Drupal\icms_core_logic\EventSubscriber;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\icms_core_logic\BackendDomain;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Makes Drupal behave as the backend of a site served on another domain.
 *
 * - Content pages requested on the backend domain redirect to the frontend,
 *   which renders them (Drupal's own path, alias and language prefix kept).
 * - Backend responses may be framed by the frontend (the blökkli editor loads
 *   Drupal forms in iframes) and are kept out of search engines.
 */
final class BackendDomainSubscriber implements EventSubscriberInterface {

  /**
   * Routes whose pages the frontend renders.
   *
   * @var string[]
   */
  public const FRONTEND_ROUTES = [
    'entity.node.canonical',
    'entity.taxonomy_term.canonical',
  ];

  public function __construct(
    private readonly BackendDomain $backendDomain,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // After routing (and its access checks) so the route is known.
      KernelEvents::REQUEST => ['onRequest', 30],
      // After core's FinishResponseSubscriber has set X-Frame-Options.
      KernelEvents::RESPONSE => ['onResponse', -10],
    ];
  }

  /**
   * Redirects frontend pages requested on the backend domain.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if ($request->getRequestFormat() !== 'html' || !$this->backendDomain->isBackendRequest($request)) {
      return;
    }
    $target = $this->getFrontendTarget($request);
    if ($target === NULL) {
      return;
    }
    $response = new TrustedRedirectResponse($this->backendDomain->getFrontendUrlFor($target), 302);
    $response->addCacheableDependency((new CacheableMetadata())->addCacheContexts(['url']));
    $event->setResponse($response);
  }

  /**
   * Adjusts headers of responses served on the backend domain.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest() || !$this->backendDomain->isBackendRequest($event->getRequest())) {
      return;
    }
    $headers = $event->getResponse()->headers;
    // Allow the frontend to embed backend pages (blökkli forms) — Drupal's
    // default X-Frame-Options: SAMEORIGIN would block that.
    $headers->remove('X-Frame-Options');
    if (!$headers->has('Content-Security-Policy')) {
      $headers->set('Content-Security-Policy', "frame-ancestors 'self' " . $this->backendDomain->getFrontendUrl());
    }
    // The public site is the frontend; the backend must not be indexed.
    if (!$headers->has('X-Robots-Tag')) {
      $headers->set('X-Robots-Tag', 'noindex, nofollow');
    }
  }

  /**
   * The frontend path (with query string) for a request, if it is a page.
   */
  private function getFrontendTarget(Request $request): ?string {
    $query = $request->getQueryString();
    $suffix = $query !== NULL && $query !== '' ? '?' . $query : '';
    // The front page, with or without language prefix: let the frontend
    // negotiate the language and resolve its own front page.
    if (preg_match('#^/([a-z]{2}(-[a-zA-Z]{2,4})?/?)?$#', $request->getPathInfo())) {
      return ltrim($request->getPathInfo(), '/') . $suffix;
    }
    $routeName = $this->routeMatch->getRouteName();
    if ($routeName === NULL || !in_array($routeName, self::FRONTEND_ROUTES, TRUE)) {
      return NULL;
    }
    // Prefer the entity's own URL (alias and language prefix) over the
    // requested path, which may be a system path like /node/1.
    foreach ($this->routeMatch->getParameters()->all() as $parameter) {
      if ($parameter instanceof EntityInterface) {
        return ltrim($parameter->toUrl()->toString(TRUE)->getGeneratedUrl(), '/') . $suffix;
      }
    }
    return ltrim($request->getPathInfo(), '/') . $suffix;
  }

}
