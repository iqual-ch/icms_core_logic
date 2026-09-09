<?php

declare(strict_types=1);

namespace Drupal\icms_core_logic;

use Symfony\Component\HttpFoundation\Request;

/**
 * Describes the split of the site into a frontend and a Drupal backend domain.
 *
 * In the subdomain routing mode (see docs/routing.md) the Nuxt frontend serves
 * the public site on the primary domain while Drupal is reachable on its own
 * hostname, usually a "backend." subdomain of it. The hosting environment
 * exposes both as DRUPAL_FRONTEND_URL and DRUPAL_BACKEND_URL; without them the
 * site runs on a single domain and this service is inert.
 */
final class BackendDomain {

  public const ENV_FRONTEND_URL = 'DRUPAL_FRONTEND_URL';
  public const ENV_BACKEND_URL = 'DRUPAL_BACKEND_URL';
  public const ENV_COOKIE_DOMAIN = 'DRUPAL_COOKIE_DOMAIN';

  public function __construct(
    private readonly ?string $frontendUrl,
    private readonly ?string $backendUrl,
    private readonly ?string $cookieDomain = NULL,
  ) {}

  /**
   * Creates the service from the environment variables.
   */
  public static function fromEnvironment(): self {
    return new self(
      self::env(self::ENV_FRONTEND_URL),
      self::env(self::ENV_BACKEND_URL),
      self::env(self::ENV_COOKIE_DOMAIN),
    );
  }

  /**
   * Reads a non-empty environment variable, without a trailing slash.
   */
  private static function env(string $name): ?string {
    $value = $_SERVER[$name] ?? getenv($name);
    if (!is_string($value) || trim($value) === '') {
      return NULL;
    }
    return rtrim(trim($value), '/');
  }

  /**
   * Whether frontend and backend are served on different hosts.
   */
  public function isEnabled(): bool {
    $frontend = $this->getFrontendHost();
    $backend = $this->getBackendHost();
    return $frontend !== NULL && $backend !== NULL && $frontend !== $backend;
  }

  public function getFrontendUrl(): ?string {
    return $this->frontendUrl;
  }

  public function getBackendUrl(): ?string {
    return $this->backendUrl;
  }

  public function getFrontendHost(): ?string {
    return self::host($this->frontendUrl);
  }

  public function getBackendHost(): ?string {
    return self::host($this->backendUrl);
  }

  /**
   * Whether the request was made to the backend domain.
   *
   * Requests the frontend proxies to Drupal (GraphQL) carry the frontend host
   * via X-Forwarded-Host and are not backend requests.
   */
  public function isBackendRequest(Request $request): bool {
    return $this->isEnabled() && strtolower($request->getHost()) === $this->getBackendHost();
  }

  /**
   * Builds the frontend URL for a path (with query string).
   */
  public function getFrontendUrlFor(string $pathAndQuery): string {
    return $this->frontendUrl . '/' . ltrim($pathAndQuery, '/');
  }

  /**
   * The session cookie domain shared by frontend and backend.
   *
   * The frontend forwards the browser's cookies to Drupal, so a session opened
   * on the backend domain must be valid on the frontend domain too: the cookie
   * is scoped to the closest common parent domain (e.g. ".example.com" for
   * "www.example.com" and "backend.example.com"). DRUPAL_COOKIE_DOMAIN
   * overrides the derived value.
   */
  public function getCookieDomain(): ?string {
    if ($this->cookieDomain !== NULL) {
      return '.' . ltrim($this->cookieDomain, '.');
    }
    if (!$this->isEnabled()) {
      return NULL;
    }
    return self::commonDomain($this->getFrontendHost(), $this->getBackendHost());
  }

  /**
   * The closest common parent domain of two hosts, as a cookie domain.
   *
   * Returns NULL when the hosts share fewer than two labels (a cookie domain
   * needs an embedded dot) or when one of them is an IP address.
   */
  public static function commonDomain(string $a, string $b): ?string {
    if (filter_var($a, FILTER_VALIDATE_IP) || filter_var($b, FILTER_VALIDATE_IP)) {
      return NULL;
    }
    $labelsA = array_reverse(explode('.', strtolower($a)));
    $labelsB = array_reverse(explode('.', strtolower($b)));
    $common = [];
    foreach ($labelsA as $i => $label) {
      if (!isset($labelsB[$i]) || $labelsB[$i] !== $label) {
        break;
      }
      $common[] = $label;
    }
    if (count($common) < 2) {
      return NULL;
    }
    return '.' . implode('.', array_reverse($common));
  }

  private static function host(?string $url): ?string {
    if ($url === NULL) {
      return NULL;
    }
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) && $host !== '' ? strtolower($host) : NULL;
  }

}
