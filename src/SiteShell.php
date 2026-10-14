<?php

declare(strict_types=1);

namespace Drupal\icms_core_logic;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shows the frontend inside the Drupal backend for editors.
 *
 * With the backend on its own domain, an editor's "site" is the frontend.
 * Instead of sending editors there, the backend keeps its navigation and top
 * bar and renders the frontend page in an iframe filling the content area: one
 * shell, Drupal's own toolbar, no re-implementation in the frontend. Anonymous
 * visitors are still redirected to the frontend (BackendDomainSubscriber).
 *
 * Which frontend is framed follows from the request's host (BackendDomain).
 */
final class SiteShell {

  public function __construct(
    private readonly BackendDomain $backendDomain,
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether an account gets the shell: anyone using Drupal's toolbar.
   */
  public function isEligible(AccountInterface $account): bool {
    return $account->hasPermission('access navigation') || $account->hasPermission('access toolbar');
  }

  /**
   * Whether the shell replaces the frontend redirect for this request.
   */
  public function applies(Request $request): bool {
    return $request->getRequestFormat() === 'html'
      && $this->backendDomain->isBackendRequest($request)
      && $this->isEligible($this->currentUser);
  }

  /**
   * Whether a path is a page only the frontend serves (search, listings).
   *
   * The frontend registers those routes in frontend_routing.settings (the
   * aliases of each key, per language, without the language prefix — see
   * docs/routing.md). Anything else Drupal does not know is a real 404.
   *
   * @param string $path
   *   The requested path, with or without language prefix and query string.
   */
  public function isFrontendPath(string $path): bool {
    $path = '/' . trim(explode('?', $path, 2)[0], '/');
    $path = preg_replace('#^/[a-z]{2}(?=/|$)#', '', $path) ?: '/';
    foreach ($this->getFrontendAliases() as $alias) {
      if (preg_match(self::aliasPattern($alias), $path)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Builds the shell for a frontend path (with query string).
   */
  public function build(Request $request, string $pathAndQuery): array {
    return [
      '#theme' => 'icms_site_shell',
      '#url' => $this->backendDomain->getFrontendUrlFor($request, $pathAndQuery),
      '#frontend_origin' => $this->backendDomain->getFrontendUrl($request),
      '#attached' => ['library' => ['icms_core_logic/site_shell']],
      '#cache' => ['contexts' => ['url', 'user.permissions']],
    ];
  }

  /**
   * The frontend's route aliases, all languages.
   *
   * @return string[]
   *   The aliases, e.g. "/suche" or "/news/:slug".
   */
  private function getFrontendAliases(): array {
    $aliases = [];
    foreach ((array) ($this->configFactory->get('frontend_routing.settings')->get('keys') ?: []) as $key) {
      foreach ((array) ($key['aliases'] ?? []) as $alias) {
        if (is_string($alias) && $alias !== '') {
          $aliases[] = $alias;
        }
      }
    }
    return $aliases;
  }

  /**
   * The regex matching a Nuxt route alias: params take a segment or the rest.
   */
  private static function aliasPattern(string $alias): string {
    $parts = preg_split('#(:[A-Za-z0-9_]+(?:\([^)]*\))?[?*+]?)#', '/' . trim($alias, '/'), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    $pattern = '';
    foreach ($parts as $i => $part) {
      $pattern .= $i % 2 ? (preg_match('#\*|\+|\(\.\*\)#', $part) ? '.*' : '[^/]+') : preg_quote($part, '#');
    }
    return '#^' . $pattern . '/?$#';
  }

}
