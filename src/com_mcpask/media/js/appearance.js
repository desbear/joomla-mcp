/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 *
 * Appearance page: colours described in words (the AI service proposes them) or chosen one by one,
 * shown at once in the preview of the module, then saved for every module that uses the site's theme.
 */
(() => {
  'use strict';

  const root = document.getElementById('mcpask-appearance');
  const options = Joomla.getOptions('com_mcpask.appearance');

  if (!root || !options) {
    return;
  }

  const t = (key, ...args) => {
    let text = Joomla.Text._('COM_MCPASK_' + key) || key;
    args.forEach((arg) => { text = text.replace('%s', arg); });
    return text;
  };
  const escape = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const HEX = /^#[0-9a-f]{6}$/;

  const frame = document.getElementById('mcpask-theme-preview');
  const inputs = [...root.querySelectorAll('[data-colour]')];
  const status = document.getElementById('mcpask-theme-status');
  let saved = { ...options.defaults, ...options.saved };
  let dirty = false;

  const current = () => Object.fromEntries(inputs.map((input) => [input.dataset.colour, input.value.toLowerCase()]));

  // The same CSS the site gets (Theme::css): the module's variables, each a checked colour
  const css = (colours) => {
    const lines = Object.entries(options.colours)
      .filter(([name]) => HEX.test(colours[name] || ''))
      .map(([name, colour]) => `  ${colour.variable}: ${colours[name]};`);

    return lines.length ? `.mcp-ask,\n.mcp-ask-chat {\n${lines.join('\n')}\n}\n` : '';
  };

  const preview = () => {
    frame.contentWindow?.postMessage({ type: 'mcpask-theme', css: css(current()) }, window.location.origin);
  };

  const show = (colours) => {
    inputs.forEach((input) => {
      if (HEX.test(colours[input.dataset.colour] || '')) {
        input.value = colours[input.dataset.colour];
      }
    });
    preview();
  };

  const message = (html, type) => {
    document.getElementById('mcpask-theme-message').innerHTML = `<div class="alert alert-${type}">${html}</div>`;
  };

  const call = async (task, fields) => {
    const body = new FormData();
    body.append('task', 'index.' + task);
    body.append(options.token, '1');
    Object.entries(fields).forEach(([name, value]) => body.append(name, value));

    const response = await fetch(options.url, { method: 'POST', body, credentials: 'same-origin' });
    const json = await response.json().catch(() => ({ success: false, message: response.status + ' ' + response.statusText }));

    if (!json.success) {
      throw new Error(json.message || response.statusText);
    }

    return json.data;
  };

  window.addEventListener('message', (event) => {
    if (event.origin === window.location.origin && event.data && event.data.type === 'mcpask-preview-ready') {
      preview();
    }
  });

  inputs.forEach((input) => input.addEventListener('input', () => {
    dirty = true;
    preview();
  }));

  const generate = async (description) => {
    const button = root.querySelector('#mcpask-theme-ask button[type="submit"]');

    if (!description.trim()) {
      return;
    }

    button.disabled = true;
    status.textContent = t('WORKING');

    try {
      const theme = await call('themeGenerate', { description, current: JSON.stringify(current()) });

      show(theme.colours);
      dirty = true;
      status.textContent = theme.note || '';

      if (theme.adjusted && theme.adjusted.length) {
        const labels = theme.adjusted.map((name) => root.querySelector(`[data-colour="${name}"]`)?.closest('label')?.textContent.trim() || name);
        message(escape(t('THEME_ADJUSTED', labels.join('、'))), 'info');
      } else {
        document.getElementById('mcpask-theme-message').innerHTML = '';
      }
    } catch (error) {
      status.textContent = '';
      message(escape(error.message), 'danger');
    } finally {
      button.disabled = false;
    }
  };

  document.getElementById('mcpask-theme-ask').addEventListener('submit', (event) => {
    event.preventDefault();
    generate(document.getElementById('mcpask-theme-description').value);
  });

  root.querySelectorAll('[data-theme-example]').forEach((button) => button.addEventListener('click', () => {
    document.getElementById('mcpask-theme-description').value = button.dataset.themeExample;
    generate(button.dataset.themeExample);
  }));

  document.getElementById('mcpask-theme-save').addEventListener('click', async () => {
    try {
      const result = await call('themeSave', { colours: JSON.stringify(current()) });

      saved = { ...options.defaults, ...result.colours };
      show(saved);
      dirty = false;
      message(escape(t('THEME_SAVED')), 'success');
    } catch (error) {
      message(escape(error.message), 'danger');
    }
  });

  document.getElementById('mcpask-theme-revert').addEventListener('click', () => {
    show(saved);
    dirty = false;
  });

  document.getElementById('mcpask-theme-reset').addEventListener('click', async () => {
    if (!window.confirm(t('THEME_RESET_CONFIRM'))) {
      return;
    }

    try {
      await call('themeSave', { colours: '{}' });
      saved = { ...options.defaults };
      show(saved);
      dirty = false;
      message(escape(t('THEME_SAVED')), 'success');
    } catch (error) {
      message(escape(error.message), 'danger');
    }
  });

  root.querySelectorAll('[data-preview-view]').forEach((button) => button.addEventListener('click', () => {
    root.querySelectorAll('[data-preview-view]').forEach((other) => other.classList.toggle('active', other === button));
    frame.contentWindow?.postMessage({ type: 'mcpask-view', view: button.dataset.previewView }, window.location.origin);
  }));

  window.addEventListener('beforeunload', (event) => {
    if (dirty) {
      event.preventDefault();
      event.returnValue = t('THEME_UNSAVED');
    }
  });
})();
