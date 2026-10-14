<?php

declare(strict_types=1);

namespace Drupal\icms_core_logic\Session;

use Drupal\Core\Session\SessionConfiguration as CoreSessionConfiguration;
use Drupal\icms_core_logic\BackendDomain;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shares the session cookie between a frontend domain and its Drupal backend.
 *
 * Core scopes the session cookie to the request host and derives the cookie
 * name from it. On a backend request both are taken from the frontend host
 * instead, so the cookie a login on backend.www.example.com sets is sent to
 * www.example.com too, where the frontend forwards it to Drupal, which looks
 * it up under the name it uses for that host. A cookie domain configured in
 * services.yml (session.storage.options) wins, as it does in core.
 *
 * @see \Drupal\icms_core_logic\IcmsCoreLogicServiceProvider
 */
class SessionConfiguration extends CoreSessionConfiguration {

  public function __construct(
    $options,
    private readonly BackendDomain $backendDomain,
  ) {
    parent::__construct($options);
  }

  /**
   * {@inheritdoc}
   */
  protected function getCookieDomain(Request $request) {
    if (!isset($this->options['cookie_domain'])) {
      $domain = $this->backendDomain->getCookieDomain($request);
      // Core's validity rules: an embedded dot and no IP address.
      if ($domain !== NULL && count(explode('.', $domain)) > 2 && !is_numeric(str_replace('.', '', $domain))) {
        return $domain;
      }
    }
    return parent::getCookieDomain($request);
  }

  /**
   * {@inheritdoc}
   */
  protected function getUnprefixedName(Request $request) {
    $host = $this->drupalValidTestUa() || isset($this->options['cookie_domain']) ? NULL : $this->backendDomain->getFrontendHost($request);
    if ($host === NULL) {
      return parent::getUnprefixedName($request);
    }
    // What core hashes for a request on the frontend host.
    $session_name = $host . $request->getBasePath() . $this->options['name_suffix'];
    $session_name = preg_replace('#/core$#', '', $session_name);
    return substr(hash('sha256', $session_name), 0, 32);
  }

}
