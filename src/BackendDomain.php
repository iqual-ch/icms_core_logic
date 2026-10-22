<?php

declare(strict_types=1);

namespace Drupal\icms_core_logic;

use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * Describes the split of a site into a frontend domain and its Drupal backend.
 *
 * Drupal is served on "backend.<domain>", the frontend on "<domain>" or
 * "www.<domain>" (see docs/routing.md), derived per request, so a site can
 * serve several domains. The frontend is "www.<domain>" only if that is listed
 * in $settings['icms_frontend_hosts'].
 */
final class BackendDomain {

  /**
   * The subdomain label of the Drupal backend.
   */
  public const SUBDOMAIN = 'backend';

  /**
   * The subdomain label of a www frontend.
   */
  public const WWW = 'www';

  public function __construct(
    private readonly Settings $settings,
  ) {}

  /**
   * Whether the request was made to a backend domain.
   *
   * Requests the frontend proxies to Drupal (GraphQL) carry the frontend host
   * via X-Forwarded-Host and are not backend requests.
   */
  public function isBackendRequest(Request $request): bool {
    return self::stripLabel(strtolower($request->getHost()), self::SUBDOMAIN) !== NULL;
  }

  /**
   * The "<domain>" of a request on "backend.<domain>" or "www.<domain>".
   *
   * NULL for any other host.
   */
  public function getSiteDomain(Request $request): ?string {
    $host = strtolower($request->getHost());
    return self::stripLabel($host, self::SUBDOMAIN) ?? self::stripLabel($host, self::WWW);
  }

  /**
   * The frontend host of a backend request: "<domain>" or "www.<domain>".
   *
   * NULL for requests that were not made to a backend domain.
   */
  public function getFrontendHost(Request $request): ?string {
    $domain = self::stripLabel(strtolower($request->getHost()), self::SUBDOMAIN);
    if ($domain === NULL) {
      return NULL;
    }
    $known = $this->getFrontendHosts();
    $www = self::WWW . '.' . $domain;
    return in_array($www, $known, TRUE) && !in_array($domain, $known, TRUE) ? $www : $domain;
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
   * The frontend forwards the browser's cookies to Drupal, so ".<domain>"
   * covers both. NULL for any other host (see getSiteDomain()).
   */
  public function getCookieDomain(Request $request): ?string {
    $domain = $this->getSiteDomain($request);
    return $domain === NULL ? NULL : '.' . $domain;
  }

  /**
   * The lowercase hosts in $settings['icms_frontend_hosts'].
   *
   * @return string[]
   *   Hostnames without port.
   */
  private function getFrontendHosts(): array {
    $hosts = $this->settings->get('icms_frontend_hosts', []);
    return is_array($hosts) ? array_map('strtolower', array_filter($hosts, 'is_string')) : [];
  }

  /**
   * The rest of a host after a leading label, NULL if it does not start so.
   */
  private static function stripLabel(string $host, string $label): ?string {
    $prefix = $label . '.';
    return str_starts_with($host, $prefix) && strlen($host) > strlen($prefix) ? substr($host, strlen($prefix)) : NULL;
  }

}
