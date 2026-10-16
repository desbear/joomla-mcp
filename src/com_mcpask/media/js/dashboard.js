/**
 * @package     MCP Server for Joomla
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */
((document, Joomla) => {
  'use strict';

  const root = document.getElementById('mcpask-dashboard');

  if (!root) {
    return;
  }

  const text = (key, ...values) => values.reduce(
    // Placeholders as PHP writes them: %s, %d, or numbered (%1$s)
    (result, value) => result.replace(/%(?:\d+\$)?[sd]/, value),
    Joomla.Text._(`COM_MCPASK_${key}`),
  );
  const status = document.getElementById('mcpask-message');
  const progress = root.querySelector('.progress');
  const bar = root.querySelector('.progress-bar');
  const buttons = [...root.querySelectorAll('[data-mcpask]')];

  const call = async (task, fields = {}) => {
    const body = new URLSearchParams({ task: `index.${task}`, [root.dataset.token]: '1', ...fields });
    const response = await fetch(root.dataset.url, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const json = await response.json();

    if (!json.success) {
      throw new Error(json.message || response.statusText);
    }

    return json.data;
  };

  // Repeats a reading of other sites until nothing remains; a site that fails does not stop the others
  const readSites = async (task) => {
    const note = document.getElementById(`mcpask-${task}-message`);
    const errors = new Map();

    for (;;) {
      const sites = Object.values(await call(task));

      sites.filter((site) => site.error).forEach((site) => errors.set(site.name, site.error));
      note.textContent = sites.map((site) => text('PEERS_PROGRESS', site.name, site.stored, site.total)).join(' ');

      // A site that failed is not tried again in this round
      if (!sites.some((site) => !site.error && site.remaining > 0)) {
        if (errors.size) {
          throw new Error([...errors.values()].join(' '));
        }

        return null;
      }
    }
  };

  const tasks = {
    // Pages are indexed a few at a time, so no request runs into the server's time limit
    async sync() {
      let after = 0;
      let stage = 0;
      let total = 0;
      let seen = 0;

      progress.hidden = false;

      for (;;) {
        const result = await call('sync', { after, stage });

        total = result.total || total;
        seen += result.indexed + result.unchanged + result.empty;
        after = result.last;
        stage = result.stage || 0;
        bar.style.width = `${total ? Math.min(100, Math.round((seen / total) * 100)) : 0}%`;
        status.textContent = text('SYNC_PROGRESS', seen, total);

        if (result.error) {
          throw new Error(result.error);
        }

        if (result.done) {
          return text('SYNC_DONE', seen);
        }
      }
    },

    // Other sites are read a little at a time as well
    peers: () => readSites('peers'),

    async clear() {
      if (!window.confirm(text('CLEAR_CONFIRM'))) {
        return '';
      }

      await call('clear');

      return null;
    },

    async test() {
      const result = await call('test');

      return text('TEST_OK', result.dimensions, result.reply);
    },
  };

  buttons.forEach((button) => {
    button.addEventListener('click', async () => {
      const task = button.dataset.mcpask;
      // Each card reports below its own buttons
      const message = document.getElementById(`mcpask-${task}-message`) || status;

      buttons.forEach((other) => { other.disabled = true; });
      message.className = 'mt-2 mb-0';
      message.textContent = text('WORKING');

      try {
        const result = await tasks[task]();

        if (result === null || task === 'sync') {
          // Show the new numbers
          window.setTimeout(() => window.location.reload(), result ? 1200 : 0);
        }

        message.textContent = result || '';
        message.classList.add('text-success');
      } catch (error) {
        message.textContent = task === 'peers' ? error.message : text(task === 'test' ? 'TEST_FAILED' : 'SYNC_FAILED', error.message);
        message.classList.add('text-danger');
      }

      buttons.forEach((other) => { other.disabled = false; });
    });
  });

  // ---------- Websites: progress that is stored, shown wherever and whenever the page is open ----------

  const board = document.getElementById('mcpask-websites');

  if (board) {
    const startButton = root.querySelector('[data-mcpask-websites="start"]');
    const stopButton = root.querySelector('[data-mcpask-websites="stop"]');
    const note = document.getElementById('mcpask-websites-message');
    const SKIPS = { 'no text': 'WEBSITES_SKIP_NO_TEXT', 'no connection': 'WEBSITES_SKIP_NO_CONNECTION', 'robots.txt': 'WEBSITES_SKIP_ROBOTS' };
    const BADGES = { DONE: 'bg-success', RUNNING: 'bg-primary', PENDING: 'bg-warning text-dark', ERROR: 'bg-danger', NEW: 'bg-secondary' };
    let reading = false;
    let timer = 0;

    const element = (tag, className, content) => {
      const node = document.createElement(tag);

      node.className = className || '';

      if (content !== undefined) {
        node.textContent = content;
      }

      return node;
    };

    // Dates are stored in UTC; shown in the browser's own time
    const when = (date) => (date ? new Date(`${date.replace(' ', 'T')}Z`).toLocaleString() : '');

    const stateOf = (site, running) => {
      if (site.error) {
        return 'ERROR';
      }

      if (!site.synced) {
        return 'NEW';
      }

      if (site.remaining > 0) {
        return running ? 'RUNNING' : 'PENDING';
      }

      return 'DONE';
    };

    const render = (status) => {
      const rows = status.sites.map((site) => {
        const state = stateOf(site, status.running || reading);
        const row = element('div', 'border-bottom py-2');
        const head = element('div', 'd-flex justify-content-between align-items-start gap-2');
        const left = element('div');
        const right = element('div', 'text-end small text-nowrap');
        const percent = site.total ? Math.round((site.done / site.total) * 100) : (state === 'DONE' ? 100 : 0);
        const progress = element('div', 'progress mt-2');
        const bar = element('div', `progress-bar${state === 'RUNNING' ? ' progress-bar-striped progress-bar-animated' : ''}${state === 'ERROR' ? ' bg-danger' : ''}${state === 'DONE' ? ' bg-success' : ''}`);

        left.append(element('strong', '', site.name), ' ', element('span', `badge ${BADGES[state]}`, text(`WEBSITES_STATE_${state}`)));

        if (site.mode) {
          left.append(' ', element('span', 'badge bg-light text-dark border', text(`WEBSITES_MODE_${site.mode.toUpperCase()}`)));
        }

        left.append(element('div', 'small text-muted text-break', site.url));
        right.append(element('div', 'fw-bold', text('WEBSITES_STORED', site.stored)));

        if (site.synced) {
          right.append(element('div', 'text-muted', text('WEBSITES_LAST', when(site.synced))));
        }

        head.append(left, right);
        progress.style.height = '6px';
        progress.setAttribute('role', 'progressbar');
        progress.setAttribute('aria-valuenow', String(percent));
        progress.setAttribute('aria-valuemin', '0');
        progress.setAttribute('aria-valuemax', '100');
        bar.style.width = `${percent}%`;
        progress.append(bar);
        row.append(head, progress);

        if (site.total) {
          const line = element('div', 'small text-muted mt-1', `${text('WEBSITES_PROGRESS_LINE', site.done, site.total)} (${percent}%)`);

          if (site.remaining > 0 && !site.error) {
            line.append(' · ', text('WEBSITES_LEFT', site.remaining));
          }

          row.append(line);
        }

        if (site.error) {
          row.append(element('div', 'small text-danger mt-1', site.error));
        }

        const skipped = Object.entries(site.skipped || {});

        if (skipped.length) {
          const reasons = skipped.map(([reason, count]) => `${SKIPS[reason] ? text(SKIPS[reason]) : reason} ×${count}`).join('、');

          row.append(element('div', 'small text-muted mt-1', text('WEBSITES_SKIPPED', reasons)));
        }

        return row;
      });

      board.replaceChildren(...rows);

      if (startButton) {
        startButton.disabled = reading;
        stopButton.hidden = !reading;
      }
    };

    const pending = (status) => status.sites.some((site) => !site.error && site.remaining > 0);

    // While nothing is being done from here, look now and then, more often while something is going on
    const watch = (status) => {
      window.clearTimeout(timer);

      if (reading) {
        return;
      }

      timer = window.setTimeout(async () => {
        try {
          const next = await call('websitesStatus');

          render(next);
          watch(next);
        } catch (error) {
          watch(status);
        }
      }, status.running || pending(status) ? 5000 : 30000);
    };

    const wait = (ms) => new Promise((resolve) => { window.setTimeout(resolve, ms); });

    // Read a round at a time until nothing is left; the server remembers, so a reload carries on
    const read = async () => {
      let status = null;

      reading = true;
      window.clearTimeout(timer);
      note.className = 'mt-2 mb-0';
      note.textContent = text('WEBSITES_READING');

      try {
        while (reading) {
          status = await call('websites');
          render(status);

          if (status.busy) {
            // The scheduled task (or another window) is reading: wait for it
            note.textContent = text('WEBSITES_BUSY');
            await wait(5000);
          } else if (!status.wanted || !reading) {
            // Stopped from here (or elsewhere) with pages left, or really finished
            note.textContent = text(pending(status) ? 'WEBSITES_STOPPED' : 'WEBSITES_FINISHED');
            note.classList.toggle('text-success', !pending(status));
            break;
          } else {
            note.textContent = text('WEBSITES_READING');
          }
        }
      } catch (error) {
        note.textContent = error.message;
        note.classList.add('text-danger');
      }

      reading = false;

      if (status) {
        render(status);
        watch(status);
      }
    };

    const initial = JSON.parse(board.dataset.status || '{"sites":[]}');

    render(initial);

    if (startButton) {
      startButton.addEventListener('click', read);
      stopButton.addEventListener('click', async () => {
        reading = false;
        stopButton.disabled = true;

        try {
          await call('websitesStop');
        } finally {
          stopButton.disabled = false;
          note.className = 'mt-2 mb-0';
          note.textContent = text('WEBSITES_STOPPED');
        }
      });
    }

    // A reading started here and not finished goes on after a reload
    if (initial.wanted && startButton && pending(initial)) {
      read();
    } else {
      watch(initial);
    }
  }
})(document, window.Joomla);
