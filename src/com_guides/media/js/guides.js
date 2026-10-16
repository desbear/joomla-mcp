/**
 * @package     Guides
 * @subpackage  com_guides
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 *
 * The guides list and its review block: preview (the article next to the text each step was
 * taken from), approve and publish, return with a note, delete.
 */
(() => {
  'use strict';

  const root = document.getElementById('guides-list');

  if (!root) {
    return;
  }

  const t = (key, ...args) => {
    let text = Joomla.Text._('COM_GUIDES_' + key) || key;
    args.forEach((arg) => { text = text.replace('%s', arg); });
    return text;
  };

  const call = async (task, fields) => {
    const body = new FormData();
    body.append('task', 'guide.' + task);
    body.append(root.dataset.token, '1');
    Object.entries(fields).forEach(([name, value]) => body.append(name, value));

    const response = await fetch(root.dataset.url, { method: 'POST', body, credentials: 'same-origin' });
    const json = await response.json().catch(() => ({ success: false, message: response.statusText }));

    if (!json.success) {
      throw new Error(json.message || response.statusText);
    }

    return json.data;
  };

  const escape = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // Article HTML is shown in a frame that runs no scripts
  const frame = (html, title) => {
    const iframe = document.createElement('iframe');
    iframe.setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox');
    iframe.title = title;
    iframe.className = 'guides-frame';
    iframe.srcdoc = '<!doctype html><meta charset="utf-8"><base target="_blank"><style>'
      + 'body{font:15px/1.6 system-ui,sans-serif;margin:0;padding:12px;color:#222}img{max-width:100%;height:auto}'
      + 'h2{font-size:1.2em;margin:1.4em 0 .5em}.guide-source,.guide-next{font-size:.9em;color:#555}.guide-note{background:#fff8e1;padding:6px 10px;border-radius:4px}'
      + '</style>' + html;
    iframe.addEventListener('load', () => {
      try {
        iframe.style.height = Math.min(4000, iframe.contentDocument.documentElement.scrollHeight + 8) + 'px';
      } catch (e) {
        iframe.style.height = '60vh';
      }
    });
    return iframe;
  };

  const preview = document.getElementById('guides-preview');
  const decide = document.getElementById('guides-decide');

  document.querySelectorAll('[data-guides-close]').forEach((button) => {
    button.addEventListener('click', () => button.closest('dialog').close());
  });

  const showPreview = async (id, button) => {
    const row = button.closest('[data-guide]');
    const title = row ? (row.querySelector('a')?.textContent || '').trim() : '';
    const article = document.getElementById('guides-preview-article');
    const origins = document.getElementById('guides-preview-origins');

    document.getElementById('guides-preview-title').textContent = title;
    article.textContent = t('WORKING');
    origins.textContent = '';
    preview.showModal();

    try {
      const data = await call('preview', { id });
      article.textContent = '';
      article.append(frame(data.html, title));

      data.steps.forEach((step, index) => {
        const box = document.createElement('section');
        box.className = 'guides-origin';
        box.innerHTML = '<h3 class="h6">' + (index + 1) + '. ' + escape(step.title) + '</h3>';

        if (!step.origins.length) {
          box.insertAdjacentHTML('beforeend', '<p class="small text-warning">' + escape(t('NO_ORIGIN')) + '</p>');
        }

        step.origins.forEach((origin) => {
          box.insertAdjacentHTML('beforeend', '<div class="small fw-bold">' + escape(origin.title) + (origin.section ? ' › ' + escape(origin.section) : '') + '</div>');

          if (origin.missing) {
            box.insertAdjacentHTML('beforeend', '<p class="small text-danger">' + escape(t('ORIGIN_MISSING')) + '</p>');
          } else {
            if (origin.changed) {
              box.insertAdjacentHTML('beforeend', '<p class="small text-danger">' + escape(t('ORIGIN_CHANGED')) + '</p>');
            }

            box.append(frame(origin.html, origin.title));
          }
        });

        origins.append(box);
      });
    } catch (error) {
      article.innerHTML = '<div class="alert alert-danger">' + escape(error.message) + '</div>';
    }
  };

  // Approve (with an optional note) or return (with a note that is needed)
  const ask = (id, approving) => new Promise((resolve) => {
    const note = document.getElementById('guides-decide-note');
    const error = document.getElementById('guides-decide-error');
    const go = document.getElementById('guides-decide-go');
    const form = document.getElementById('guides-decide-form');

    document.getElementById('guides-decide-title').textContent = t(approving ? 'APPROVE' : 'REJECT');
    document.getElementById('guides-decide-label').textContent = t(approving ? 'APPROVE_NOTE_LABEL' : 'REJECT_NOTE_LABEL');
    go.textContent = t(approving ? 'APPROVE' : 'REJECT');
    go.className = 'btn ' + (approving ? 'btn-success' : 'btn-danger');
    note.value = '';
    error.classList.add('d-none');

    const submit = async (event) => {
      event.preventDefault();

      if (!approving && !note.value.trim()) {
        error.textContent = t('REJECT_NOTE_NEEDED');
        error.classList.remove('d-none');
        return;
      }

      go.disabled = true;

      try {
        const data = await call(approving ? 'approve' : 'reject', { id, note: note.value });
        form.removeEventListener('submit', submit);
        resolve(data);
        decide.close();
      } catch (failure) {
        error.textContent = failure.message;
        error.classList.remove('d-none');
      } finally {
        go.disabled = false;
      }
    };

    form.addEventListener('submit', submit);
    decide.addEventListener('close', () => { form.removeEventListener('submit', submit); resolve(null); }, { once: true });
    decide.showModal();
    note.focus();
  });

  const notice = (html, type) => {
    const box = document.createElement('div');
    box.className = 'alert alert-' + type;
    box.innerHTML = html;
    root.prepend(box);
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  };

  root.addEventListener('click', async (event) => {
    const button = event.target.closest('button');

    if (!button) {
      return;
    }

    if (button.dataset.guidesPreview) {
      showPreview(button.dataset.guidesPreview, button);
    } else if (button.dataset.guidesApprove) {
      const done = await ask(button.dataset.guidesApprove, true);

      if (done) {
        notice(escape(t('APPROVED')) + ' <a href="' + escape(done.url) + '" target="_blank" rel="noopener">' + escape(t('PUBLISHED_AT')) + '</a>', 'success');
        button.closest('tr')?.remove();
      }
    } else if (button.dataset.guidesReject) {
      if (await ask(button.dataset.guidesReject, false)) {
        notice(escape(t('REJECTED')), 'info');
        button.closest('tr')?.remove();
      }
    } else if (button.dataset.guidesDelete) {
      if (!window.confirm(t('DELETE_CONFIRM'))) {
        return;
      }

      try {
        await call('delete', { id: button.dataset.guidesDelete });
        button.closest('tr')?.remove();
      } catch (error) {
        notice(escape(error.message), 'danger');
      }
    }
  });
})();
