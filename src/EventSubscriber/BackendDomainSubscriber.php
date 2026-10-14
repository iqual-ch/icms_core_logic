<?php

declare(strict_types=1);

namespace Drupal\icms_core_logic\EventSubscriber;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\icms_core_logic\BackendDomain;
use Drupal\icms_core_logic\SiteShell;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Makes Drupal behave as the backend of a site served on another domain.
 *
 * - Content pages requested on the backend domain redirect to the frontend,
 *   which renders them (Drupal's own path, alias and language prefix kept) —
 *   unless the user gets the site shell (see SiteShell).
 * - Backend responses may be framed by the frontend (the blökkli editor loads
 *   Drupal forms in iframes) and are kept out of search engines.
 *
 * Frontend domain, redirect target and framing origin are those of the
 * request's own host (see BackendDomain).
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
    private readonly ?SiteShell $siteShell = NULL,
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
    // Editors stay in the backend and see the page in the site shell.
    if ($this->siteShell?->applies($request)) {
      return;
    }
    $target = $this->getFrontendTarget($request);
    if ($target === NULL) {
      return;
    }
    $response = new TrustedRedirectResponse($this->backendDomain->getFrontendUrlFor($request, $target), 302);
    $response->addCacheableDependency((new CacheableMetadata())->addCacheContexts(['url']));
    $event->setResponse($response);
  }

  /**
   * Adjusts headers of responses served on the backend domain.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $frontend = $this->backendDomain->getFrontendUrl($event->getRequest());
    if ($frontend === NULL) {
      return;
    }
    $headers = $event->getResponse()->headers;
    // The frontend may embed backend pages (blökkli forms). Browsers enforce
    // the CSP frame-ancestors directive and ignore X-Frame-Options when both
    // are present, so core's X-Frame-Options: SAMEORIGIN stays as a fallback
    // for user agents without CSP support.
    self::mergeFrameAncestors($headers, ["'self'", $frontend]);
    // The public site is the frontend; the backend must not be indexed.
    if (!$headers->has('X-Robots-Tag')) {
      $headers->set('X-Robots-Tag', 'noindex, nofollow');
    }
  }

  /**
   * Adds sources to the frame-ancestors directive of the response's CSP.
   *
   * A policy without the directive gets it appended, a policy that has one
   * keeps its sources and gains the missing ones ('none' cannot be combined
   * and is dropped); without any policy one is set. Report-only policies are
   * left alone.
   *
   * @param \Symfony\Component\HttpFoundation\ResponseHeaderBag $headers
   *   The response headers.
   * @param string[] $sources
   *   The frame-ancestors sources to allow.
   */
  public static function mergeFrameAncestors(ResponseHeaderBag $headers, array $sources): void {
    $policies = $headers->all('Content-Security-Policy');
    if ($policies === []) {
      $headers->set('Content-Security-Policy', 'frame-ancestors ' . implode(' ', $sources));
      return;
    }
    $merged = FALSE;
    foreach ($policies as $i => $policy) {
      $directives = array_values(array_filter(array_map('trim', explode(';', (string) $policy)), fn(string $directive): bool => $directive !== ''));
      foreach ($directives as $j => $directive) {
        if (!preg_match('/^frame-ancestors(?:\s+(.*))?$/i', $directive, $match)) {
          continue;
        }
        $existing = preg_split('/\s+/', trim($match[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $existing = array_values(array_filter($existing, fn(string $source): bool => strcasecmp($source, "'none'") !== 0));
        foreach ($sources as $source) {
          if (!in_array($source, $existing, TRUE)) {
            $existing[] = $source;
          }
        }
        $directives[$j] = 'frame-ancestors ' . implode(' ', $existing);
        $merged = TRUE;
      }
      $policies[$i] = implode('; ', $directives);
    }
    if (!$merged) {
      $policies[0] .= '; frame-ancestors ' . implode(' ', $sources);
    }
    $headers->set('Content-Security-Policy', $policies);
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
