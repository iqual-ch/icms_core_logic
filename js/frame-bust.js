/**
 * @file
 * Leaves the site shell when a Drupal page is loaded inside it.
 *
 * The shell frames the frontend; a link to a Drupal page followed inside
 * that frame (admin, user, node forms) would nest Drupal's navigation in its
 * own iframe. Pages framed by the backend origin itself navigate the top
 * window instead. blökkli's iframe pages are not attached to this library.
 */

(() => {
  try {
    if (window.top === window) {
      return;
    }
    const ancestors = window.location.ancestorOrigins;
    const parentOrigin =
      ancestors && ancestors.length
        ? ancestors[0]
        : document.referrer
          ? new URL(document.referrer).origin
          : '';
    if (parentOrigin === window.location.origin) {
      window.top.location.replace(window.location.href);
    }
  } catch (e) {
    // Cross-origin access denied: not our shell.
  }
})();
