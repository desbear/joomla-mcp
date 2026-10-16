/**
 * @package     MCP Server for Joomla
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */
// Runs in the head, before the first paint. When this page was reached by choosing a source in the
// conversation, opt this document in to the cross-document view transition that ask.js started.
(() => {
  try {
    const key = 'mcp-ask:navigate';
    const time = Number(sessionStorage.getItem(key));

    sessionStorage.removeItem(key);

    if (!time || Date.now() - time > 10000) {
      return;
    }

    const style = document.createElement('style');

    // A site with a strict Content Security Policy only accepts styles that carry its nonce
    style.nonce = (document.querySelector('[nonce]') || {}).nonce || '';
    style.textContent = '@view-transition { navigation: auto; }';
    document.head.append(style);

    window.addEventListener('pagereveal', (event) => {
      if (event.viewTransition) {
        event.viewTransition.types.add('mcp-ask-nav');
      }
    }, { once: true });
  } catch (error) {
    // No storage or no view transitions: the page simply loads as usual
  }
})();
