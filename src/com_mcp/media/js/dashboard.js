/**
 * @package     MCP Server for Joomla
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */
((document, Joomla) => {
  'use strict';

  const root = document.getElementById('mcp-dashboard');

  if (!root) {
    return;
  }

  const text = (key, fallback) => (Joomla && Joomla.Text ? Joomla.Text._(key, fallback) : fallback);

  // Fill the token into the snippets. The token never leaves the browser.
  // When the server strips the Authorization header, the snippets use X-Joomla-Token instead.
  const token = document.getElementById('mcp-token');
  let useXToken = false;
  const fill = () => {
    const value = token.value.trim() || 'YOUR_API_TOKEN';
    const header = useXToken ? 'X-Joomla-Token' : 'Authorization';
    const headerValue = useXToken ? value : `Bearer ${value}`;

    root.querySelectorAll('[data-mcp-template]').forEach((code) => {
      code.textContent = code.dataset.mcpTemplate
        .split('{URL}').join(root.dataset.endpoint)
        .split('{HEADER}').join(header)
        .split('{VALUE}').join(headerValue);
    });
  };

  token.addEventListener('input', fill);
  fill();

  // Copy buttons
  root.querySelectorAll('[data-mcp-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
      const target = root.querySelector(button.dataset.mcpCopy);
      const value = target.value !== undefined ? target.value : target.textContent;

      try {
        await navigator.clipboard.writeText(value);
      } catch (e) {
        const area = document.createElement('textarea');
        area.value = value;
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
      }

      const label = button.innerHTML;
      button.textContent = text('COM_MCP_COPIED', 'Copied');
      setTimeout(() => { button.innerHTML = label; }, 1500);
    });
  });

  // Does the web server pass the Authorization header on to PHP?
  const item = document.getElementById('mcp-auth-check');
  const icon = item.querySelector('span');
  const detail = item.querySelector('[data-mcp-detail]');
  const fix = item.querySelector('[data-mcp-fix]');
  const result = (state, message) => {
    icon.className = {
      ok: 'icon-check text-success',
      warning: 'icon-warning text-warning',
      error: 'icon-times text-danger',
    }[state];
    detail.textContent = message;
    fix.classList.toggle('d-none', state === 'ok');
  };

  // Reads the response, but reports a redirect or an HTML page as such: a security or redirect
  // extension sending /api requests elsewhere is a common cause and looks like invalid JSON
  const readJson = async (response) => {
    const type = response.headers.get('content-type') || '';

    if (response.redirected) {
      throw new Error(`${text('COM_MCP_CHECK_REDIRECTED', 'the request was redirected to')} ${response.url}`);
    }

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    if (!type.includes('json')) {
      throw new Error(text('COM_MCP_CHECK_NOT_JSON', 'an HTML page was returned instead of JSON'));
    }

    return response.json();
  };

  fetch(root.dataset.health, {
    headers: {
      Accept: 'application/json',
      Authorization: 'Bearer mcp-health-check',
      'X-Joomla-Token': 'mcp-health-check',
    },
    credentials: 'omit',
    cache: 'no-store',
  })
    .then(readJson)
    .then((data) => {
      if (data.authorization_header) {
        result('ok', text('COM_MCP_CHECK_AUTH_OK', 'OK'));
      } else if (data.x_joomla_token) {
        useXToken = true;
        fill();
        // OAuth clients can only use the Authorization header, so this is an error when OAuth is on
        result(
          root.dataset.oauth ? 'error' : 'warning',
          text('COM_MCP_CHECK_AUTH_XTOKEN', 'Authorization is stripped; using X-Joomla-Token instead.')
            + (root.dataset.oauth ? ` ${text('COM_MCP_CHECK_AUTH_OAUTH_BROKEN', 'OAuth sign-in cannot work until this is fixed.')}` : ''),
        );
      } else {
        result('error', text('COM_MCP_CHECK_AUTH_FAIL', 'The Authorization header does not reach PHP.'));
      }
    })
    .catch((error) => result('error', `${text('COM_MCP_CHECK_AUTH_UNREACHABLE', 'The endpoint could not be reached')} (${error.message})`));

  // Can clients discover the OAuth authorization server?
  const oauthItem = document.getElementById('mcp-oauth-check');

  if (oauthItem && root.dataset.oauth) {
    const oauthIcon = oauthItem.querySelector('span');
    const oauthDetail = oauthItem.querySelector('[data-mcp-detail]');

    fetch(root.dataset.oauth, { headers: { Accept: 'application/json' }, credentials: 'omit', cache: 'no-store' })
      .then(readJson)
      .then((data) => {
        if (!data.issuer || !data.token_endpoint) {
          throw new Error('invalid metadata');
        }

        oauthIcon.className = 'icon-check text-success';
        oauthDetail.textContent = text('COM_MCP_CHECK_OAUTH_OK', 'OK');
      })
      .catch((error) => {
        oauthIcon.className = 'icon-times text-danger';
        oauthDetail.textContent = `${text('COM_MCP_CHECK_OAUTH_FAIL', 'OAuth metadata could not be loaded')} (${error.message})`;
      });
  }
})(document, window.Joomla);
