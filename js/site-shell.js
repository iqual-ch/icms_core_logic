/**
 * @file
 * Keeps the Drupal shell in sync with the frontend shown in its iframe.
 *
 * The frontend (see @iqual/nuxt-icms, plugins/siteShell.client.ts) posts its
 * route changes: the shell mirrors the path in the address bar, refreshes
 * the top bar (local tasks of the new page) and drops Drupal's messages,
 * which float above the frame (css/site-shell.css) and belong to the page
 * the shell was opened with.
 */

((Drupal, once) => {

  async function refreshTopBar(path) {
    try {
      const response = await fetch(path, { credentials: 'same-origin' });
      if (!response.ok) {
        return;
      }
      const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
      const fresh = doc.querySelector('.top-bar');
      const current = document.querySelector('.top-bar');
      if (fresh && current) {
        current.replaceWith(fresh);
        Drupal.attachBehaviors(fresh);
        if (Drupal.displace) {
          Drupal.displace();
        }
      }
    } catch (e) {
      // The top bar is a convenience; the iframe navigated regardless.
    }
  }

  function dismissMessages() {
    document.querySelectorAll('.region-highlighted .messages').forEach((message) => {
      message.remove();
    });
  }

  Drupal.behaviors.icmsSiteShell = {
    attach(context) {
      once('icms-site-shell-messages', '.region-highlighted .messages', context).forEach((message) => {
        message.addEventListener('click', () => message.remove());
      });
      once('icms-site-shell', '[data-icms-site-shell]', context).forEach((frame) => {
        const frontendOrigin = frame.dataset.frontendOrigin;
        // The frontend announces the page the shell was opened with as well;
        // only a navigation away from it ends the messages.
        const initialPath = window.location.pathname;
        window.addEventListener('message', (event) => {
          if (event.origin !== frontendOrigin || !event.data || typeof event.data.type !== 'string') {
            return;
          }
          if (event.data.type === 'icms:navigate') {
            const path = event.data.path;
            if (typeof path === 'string' && path.startsWith('/') && !path.startsWith('//')) {
              if (path.split('?')[0] !== initialPath) {
                dismissMessages();
              }
              window.history.replaceState(window.history.state, '', path);
              refreshTopBar(path);
            }
            if (typeof event.data.title === 'string' && event.data.title) {
              document.title = event.data.title;
            }
          }
        });
      });
    },
  };
})(Drupal, once);
