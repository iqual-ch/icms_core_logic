<?php

declare(strict_types=1);

namespace Drupal\icms_core_logic;

use Symfony\Component\HttpFoundation\Request;

/**
 * Describes the split of a site into a frontend domain and its Drupal backend.
 *
 * The Nuxt frontend serves the public site on a domain of its own; Drupal is
 * reachable on the "backend." subdomain of that domain (see docs/routing.md).
 * Everything is derived from the host of the request at hand, so a site can
 * serve several domains, each paired with its own backend subdomain.
 */
final class BackendDomain {

  /**
   * The subdomain label of the Drupal backend.
   */
  public const SUBDOMAIN = 'backend';

  /**
   * Whether the request was made to a backend domain.
   *
   * Requests the frontend proxies to Drupal (GraphQL) carry the frontend host
   * via X-Forwarded-Host and are not backend requests.
   */
  public function isBackendRequest(Request $request): bool {
    return $this->getFrontendHost($request) !== NULL;
  }

  /**
   * The frontend host of a backend request: its host without "backend.".
   *
   * NULL for requests that were not made to a backend domain.
   */
  public function getFrontendHost(Request $request): ?string {
    $host = strtolower($request->getHost());
    $prefix = self::SUBDOMAIN . '.';
    if (!str_starts_with($host, $prefix) || strlen($host) === strlen($prefix)) {
      return NULL;
    }
    return substr($host, strlen($prefix));
  }

  /**
   * The frontend origin of a backend request, e.g. "https://www.example.com".
   *
   * Scheme and a non-default port are the request's own: frontend and backend
   * are served by the same router.
   */
  public function getFrontendUrl(Request $request): ?string {
    $host = $this->getFrontendHost($request);
    if ($host === NULL) {
      return NULL;
    }
    $scheme = $request->getScheme();
    $port = (int) $request->getPort();
    $default = $scheme === 'https' ? 443 : 80;
    return $scheme . '://' . $host . ($port && $port !== $default ? ':' . $port : '');
  }

  /**
   * Builds the frontend URL of a path (with query string) for a backend request.
   */
  public function getFrontendUrlFor(Request $request, string $pathAndQuery): ?string {
    $url = $this->getFrontendUrl($request);
    return $url === NULL ? NULL : $url . '/' . ltrim($pathAndQuery, '/');
  }

  /**
   * The session cookie domain shared by a frontend domain and its backend.
   *
   * The frontend forwards the browser's cookies to Drupal, so a session opened
   * on the backend domain must be valid on the frontend domain too: the cookie
   * is scoped to the frontend host, which covers "backend.<host>" as well
   * (".www.example.com" for www.example.com and backend.www.example.com).
   * NULL for requests that were not made to a backend domain.
   */
  public function getCookieDomain(Request $request): ?string {
    $host = $this->getFrontendHost($request);
    return $host === NULL ? NULL : '.' . $host;
  }

}
