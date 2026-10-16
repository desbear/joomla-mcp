/**
 * @package     MCP Server for Joomla
 * @subpackage  mod_mcp_ask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 *
 * Ask the Site on another website. One line in that website's pages:
 *
 *   <script src="https://(the Joomla site)/media/mod_mcp_ask/js/embed.js" data-key="pk_…" async></script>
 *
 * It puts a frame of the Joomla site in a corner of the window: the size of a button, the open
 * search box, or the whole window for the conversation. What is asked and answered stays in the
 * frame; this page tells it where the visitor is, changes page when a source of this website is
 * opened, and opens LiveChat here when the visitor asks for a person.
 *
 * Optional attributes: data-lang (a language tag), data-user (a token the website's server signed
 * for its signed-in visitor), data-title (the frame's name for screen readers).
 *
 * window.McpAsk: setContext({...}) what this page is about (a few short values), setUser(token,
 * name) or setUser(null), open(), close().
 */
((window, document) => {
  'use strict';

  const script = document.currentScript || document.querySelector('script[src*="mod_mcp_ask/js/embed.js"][data-key]');

  if (!script || window.McpAskEmbed) {
    return;
  }

  const key = String(script.dataset.key || '');

  if (!/^pk_[0-9a-f]{24}$/.test(key)) {
    return;
  }

  window.McpAskEmbed = true;

  const source = new URL(script.src, document.baseURI);
  const base = source.href.replace(/media\/mod_mcp_ask\/js\/embed\.js.*$/, '');
  const frameOrigin = source.origin;
  const frame = document.createElement('iframe');
  const address = new URL(`${base}index.php`);

  address.search = new URLSearchParams({
    option: 'com_mcpask',
    task: 'embed.page',
    format: 'raw',
    key,
    host: window.location.origin,
    ...(script.dataset.lang ? { lang: script.dataset.lang } : {}),
  }).toString();

  frame.src = address.href;
  frame.title = script.dataset.title || 'Ask';
  frame.setAttribute('allow', 'clipboard-write');
  frame.setAttribute('allowtransparency', 'true');

  // Hidden until the page in it says it is ready, so nothing shows of a frame that is refused
  Object.assign(frame.style, {
    position: 'fixed',
    zIndex: '2147483000',
    inset: 'auto 0 0 auto',
    width: '0',
    height: '0',
    maxWidth: '100vw',
    maxHeight: '100dvh',
    border: '0',
    margin: '0',
    padding: '0',
    background: 'transparent',
    colorScheme: 'normal',
    visibility: 'hidden',
  });

  let ready = false;
  let side = 'right';
  let mode = 'button';
  let button = 96;
  let context = {};
  let user = script.dataset.user ? { token: script.dataset.user, name: '' } : null;
  const waiting = [];

  const post = (type, data = {}) => {
    if (frame.contentWindow) {
      frame.contentWindow.postMessage({ ...data, mcpAsk: type }, frameOrigin);
    }
  };

  // Calls made before the frame is ready wait for it
  const whenReady = (callback) => (ready ? callback() : waiting.push(callback));

  const sendContext = () => post('context', { url: window.location.href, title: document.title, context });

  // ---------- Size ----------

  const place = () => {
    const full = mode === 'full';
    const wide = Math.min(window.innerWidth, 480);

    Object.assign(frame.style, {
      top: full ? '0' : 'auto',
      bottom: '0',
      left: full || side === 'left' ? '0' : 'auto',
      right: full || side === 'right' ? '0' : 'auto',
      width: full ? '100vw' : `${mode === 'bar' ? Math.min(window.innerWidth, wide + (button > 96 ? button - 40 : 0)) : button}px`,
      height: full ? '100dvh' : `${mode === 'bar' ? 260 + Math.max(0, button - 96) : button}px`,
    });
  };

  window.addEventListener('resize', () => ready && place());

  // ---------- LiveChat, when the visitor asks for a person ----------

  // The same as in the frame (ask.js): LiveChat already on this page is kept and driven through
  // the API it has; otherwise it is loaded now
  const liveChatApi = () => {
    const widget = window.LiveChatWidget;

    if (widget && widget._h) {
      return {
        availability: () => ((widget.get('state') || {}).availability === 'online' ? 'online' : 'offline'),
        variables: (values) => widget.call('set_session_variables', values),
        name: (name) => widget.call('set_customer_name', name),
        email: (email) => widget.call('set_customer_email', email),
        open: () => widget.call('maximize'),
        onChat: (callback) => widget.on('customer_status_changed', (data) => data && data.status === 'chatting' && callback()),
        onMessage: (callback) => widget.on('form_submitted', (data) => data && data.type === 'ticket' && callback()),
      };
    }

    const api = window.LC_API;

    if (api && typeof api.is_loaded === 'function' && api.is_loaded()) {
      const chain = (name, callback) => {
        const before = api[name];
        api[name] = (...args) => {
          if (typeof before === 'function') {
            before(...args);
          }

          callback();
        };
      };

      return {
        availability: () => (api.agents_are_available() ? 'online' : 'offline'),
        variables: (values) => (api.update_custom_variables || api.set_custom_variables).call(api, Object.entries(values).map(([name, value]) => ({ name, value }))),
        name: (name) => api.set_visitor_name(name),
        email: (email) => api.set_visitor_email(email),
        open: () => api.open_chat_window(),
        onChat: (callback) => chain('on_chat_started', callback),
        onMessage: (callback) => chain('on_ticket_created', callback),
      };
    }

    return null;
  };

  let liveChat = null;
  let liveChatHeard = false;

  const loadLiveChat = (license, group) => {
    if (liveChat) {
      return liveChat;
    }

    liveChat = new Promise((resolve, reject) => {
      let polling = 0;
      const fail = (error) => {
        window.clearTimeout(timer);
        window.clearInterval(polling);
        liveChat = null;
        reject(error);
      };
      const timer = window.setTimeout(() => fail(new Error('LiveChat did not load')), 15000);
      const check = () => {
        const api = liveChatApi();

        if (api) {
          window.clearTimeout(timer);
          window.clearInterval(polling);
          resolve(api);
        }

        return Boolean(api);
      };

      if (check()) {
        return;
      }

      if (window.LiveChatWidget || window.LC_API || (window.__lc && window.__lc.license) || document.querySelector('script[src*="cdn.livechatinc.com/tracking.js"]')) {
        polling = window.setInterval(check, 300);

        return;
      }

      window.__lc = { license, integration_name: 'manual_onboarding', product_name: 'livechat', ...(group > 0 ? { group } : {}) };

      const widget = { _q: [], _h: null, _v: '2.0' };
      const queue = (args) => (widget._h ? widget._h.apply(null, args) : widget._q.push(args));

      ['on', 'once', 'off', 'call'].forEach((name) => {
        widget[name] = (...args) => queue([name, args]);
      });
      widget.get = (...args) => {
        if (!widget._h) {
          throw new Error('LiveChat is not ready');
        }

        return queue(['get', args]);
      };
      widget.init = () => {
        const tag = document.createElement('script');
        tag.async = true;
        tag.src = 'https://cdn.livechatinc.com/tracking.js';
        tag.addEventListener('error', () => fail(new Error('LiveChat was blocked')));
        document.head.append(tag);
      };
      window.LiveChatWidget = widget;
      widget.once('ready', check);
      widget.init();
    });

    return liveChat;
  };

  const handoff = async (data) => {
    try {
      const api = await loadLiveChat(Number(data.license) || 0, Number(data.group) || 0);
      const variables = data.variables && typeof data.variables === 'object' ? data.variables : {};
      const customer = data.customer && typeof data.customer === 'object' ? data.customer : {};

      if (data.first) {
        const clean = Object.fromEntries(Object.entries(variables).slice(0, 10).map(([name, value]) => [String(name).slice(0, 50), String(value).slice(0, 1000)]));

        if (Object.keys(clean).length) {
          api.variables(clean);
        }

        if (customer.name) {
          api.name(String(customer.name).slice(0, 100));
        }

        if (customer.email) {
          api.email(String(customer.email).slice(0, 200));
        }

        if (!liveChatHeard) {
          liveChatHeard = true;
          api.onChat(() => post('handoff-event', { event: 'chatting' }));
          api.onMessage(() => post('handoff-event', { event: 'message' }));
        }
      }

      const availability = api.availability();

      api.open();
      post('handoff-result', { id: data.id, availability });
    } catch (error) {
      post('handoff-result', { id: data.id, error: String(error && error.message ? error.message : error) });
    }
  };

  // ---------- What the frame says ----------

  window.addEventListener('message', (event) => {
    const data = event.data;

    if (event.source !== frame.contentWindow || event.origin !== frameOrigin || !data || typeof data.mcpAsk !== 'string') {
      return;
    }

    switch (data.mcpAsk) {
      case 'ready':
        ready = true;
        side = data.side === 'left' ? 'left' : 'right';
        place();
        frame.style.visibility = 'visible';
        sendContext();

        if (user) {
          post('user', user);
        }

        waiting.splice(0).forEach((callback) => callback());
        break;
      case 'size':
        mode = ['button', 'bar', 'full'].includes(data.mode) ? data.mode : 'button';

        // A pet needs more room than the round button
        if (mode === 'button') {
          button = Math.min(300, Math.max(96, Number(data.button) || 96));
        }

        place();
        // Answered once the new size is laid out
        window.requestAnimationFrame(() => post('sized', { mode }));
        break;
      case 'navigate': {
        let url;

        try {
          url = new URL(String(data.url || ''), window.location.href);
        } catch (error) {
          return;
        }

        if (!['http:', 'https:'].includes(url.protocol)) {
          return;
        }

        // A page of this website: go there; any other site: a new tab
        if (url.origin === window.location.origin) {
          window.location.assign(url.href);
        } else {
          window.open(url.href, '_blank', 'noopener');
        }

        break;
      }
      case 'handoff':
        handoff(data);
        break;
      default:
    }
  });

  // A page that changes address without loading (single-page applications) says so too
  ['pushState', 'replaceState'].forEach((name) => {
    const original = window.history[name];

    window.history[name] = function change(...args) {
      const result = original.apply(this, args);
      whenReady(sendContext);

      return result;
    };
  });
  window.addEventListener('popstate', () => whenReady(sendContext));

  // ---------- For this website's own scripts ----------

  window.McpAsk = {
    setContext(values) {
      context = values && typeof values === 'object' ? { ...values } : {};
      whenReady(sendContext);
    },
    setUser(token, name = '') {
      user = typeof token === 'string' && token ? { token, name: String(name || '') } : null;
      whenReady(() => post('user', user || { token: null }));
    },
    open() {
      whenReady(() => post('open'));
    },
    close() {
      whenReady(() => post('close'));
    },
  };

  const attach = () => document.body.append(frame);

  if (document.body) {
    attach();
  } else {
    document.addEventListener('DOMContentLoaded', attach);
  }
})(window, document);
