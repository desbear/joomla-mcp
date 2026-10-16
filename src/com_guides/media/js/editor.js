/**
 * @package     Guides
 * @subpackage  com_guides
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 *
 * The guide editor: steps as cards (in order, dragged or moved), taken from sections of articles,
 * from the step library or written; where the guide goes; save, send for review, preview, approve.
 */
(() => {
  'use strict';

  const root = document.getElementById('guides-editor');

  if (!root) {
    return;
  }

  const options = Joomla.getOptions('com_guides.editor') || {};
  const t = (key, ...args) => {
    let text = Joomla.Text._('COM_GUIDES_' + key) || key;
    args.forEach((arg) => { text = text.replace('%s', arg); });
    return text;
  };
  const escape = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const $ = (id) => document.getElementById(id);
  const field = (name) => document.querySelector('[name="guide[' + name + ']"]') || document.querySelector('[name="guide[' + name + '][]"]');

  let id = options.id || 0;
  let state = options.state || 'draft';
  let steps = (options.steps || []).map((step) => ({ ...step, key: Math.random().toString(36).slice(2) }));
  let dirty = false;

  const call = async (task, fields) => {
    const body = new FormData();
    body.append('task', 'guide.' + task);
    body.append(root.dataset.token, '1');
    Object.entries(fields || {}).forEach(([name, value]) => body.append(name, value));

    const response = await fetch(root.dataset.url, { method: 'POST', body, credentials: 'same-origin' });
    const json = await response.json().catch(() => ({ success: false, message: response.status + ' ' + response.statusText }));

    if (!json.success) {
      throw new Error(json.message || response.statusText);
    }

    return json.data;
  };

  const message = (html, type) => {
    const box = $('guides-message');
    box.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible">' + html
      + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  };

  const changed = () => { dirty = true; };

  // ---------------------------------------------------------------- the guide's own fields

  const tagsField = () => document.querySelector('[name="guide[tags][]"]');

  const tagValues = () => {
    const select = tagsField();

    return select ? Array.from(select.selectedOptions).map((option) => parseInt(option.value, 10)).filter((n) => n > 0) : [];
  };

  const addTag = (tagId) => {
    const select = tagsField();

    if (!select) {
      return;
    }

    const fancy = select.closest('joomla-field-fancy-select');

    if (fancy && fancy.choicesInstance) {
      fancy.choicesInstance.setChoiceByValue(String(tagId));
    } else {
      const option = select.querySelector('option[value="' + tagId + '"]');

      if (option) {
        option.selected = true;
      }
    }

    changed();
  };

  const setCategory = (catid) => {
    const select = field('catid');

    if (!select) {
      return;
    }

    if (!select.querySelector('option[value="' + catid + '"]')) {
      return;
    }

    select.value = String(catid);
    select.dispatchEvent(new Event('change', { bubbles: true }));
    changed();
  };

  const value = (name) => {
    const input = field(name);

    return input ? input.value : '';
  };

  // ---------------------------------------------------------------- steps

  const list = $('guides-steps');
  let dragged = null;

  const sourceLabel = (source) => source.title + (source.section ? ' › ' + source.section : '');

  const stepCard = (step, index) => {
    const li = document.createElement('li');
    li.className = 'guides-step card';
    li.dataset.key = step.key;

    const shared = step.id && step.used > 1;
    const sources = step.sources.map((source, n) => '<span class="badge bg-light text-dark border guides-source' + (source.changed ? ' guides-source--changed' : '') + '">'
      + escape(sourceLabel(source))
      + ' <button type="button" class="btn-close btn-close-sm" data-remove-source="' + n + '" aria-label="' + escape(t('SOURCE_REMOVE')) + '"></button></span>').join(' ');

    li.innerHTML = '<div class="card-header d-flex align-items-center gap-2">'
      + '<span class="guides-step__handle icon-menu" draggable="true" title="' + escape(t('STEP_UP')) + '" aria-hidden="true"></span>'
      + '<strong class="text-nowrap">' + escape(t('STEP', index + 1)) + '</strong>'
      + '<input class="form-control form-control-sm" data-field="title" maxlength="255" placeholder="' + escape(t('STEP_TITLE')) + '" value="' + escape(step.title) + '">'
      + '<div class="btn-group btn-group-sm">'
      + '<button type="button" class="btn btn-outline-secondary" data-move="-1" aria-label="' + escape(t('STEP_UP')) + '"' + (index === 0 ? ' disabled' : '') + '><span class="icon-arrow-up" aria-hidden="true"></span></button>'
      + '<button type="button" class="btn btn-outline-secondary" data-move="1" aria-label="' + escape(t('STEP_DOWN')) + '"' + (index === steps.length - 1 ? ' disabled' : '') + '><span class="icon-arrow-down" aria-hidden="true"></span></button>'
      + '<button type="button" class="btn btn-outline-danger" data-remove aria-label="' + escape(t('STEP_REMOVE')) + '"><span class="icon-trash" aria-hidden="true"></span></button>'
      + '</div></div>'
      + '<div class="card-body">'
      + (shared ? '<div class="alert alert-info py-1 px-2 small d-flex justify-content-between align-items-center">' + escape(t('STEP_SHARED', step.used))
        + ' <button type="button" class="btn btn-sm btn-link" data-detach>' + escape(t('STEP_DETACH')) + '</button></div>' : '')
      + '<div class="d-flex justify-content-between align-items-center mb-1"><label class="form-label small m-0">' + escape(t('STEP_BODY')) + '</label>'
      + '<button type="button" class="btn btn-sm btn-link p-0" data-toggle-html>' + escape(t('STEP_HTML')) + '</button></div>'
      + '<div class="guides-step__body form-control" contenteditable="true" data-field="body"></div>'
      + '<textarea class="form-control font-monospace small d-none" rows="8" data-field="html"></textarea>'
      + '<div class="row g-2 mt-1">'
      + '<div class="col-md-6"><input class="form-control form-control-sm" data-field="image" maxlength="1024" placeholder="' + escape(t('STEP_IMAGE_HINT')) + '" aria-label="' + escape(t('STEP_IMAGE')) + '" value="' + escape(step.image) + '"></div>'
      + '<div class="col-md-6"><input class="form-control form-control-sm" data-field="note" maxlength="1000" placeholder="' + escape(t('STEP_NOTE')) + '" value="' + escape(step.note) + '"></div>'
      + '</div>'
      + '<div class="small mt-2"><span class="text-muted">' + escape(t('STEP_SOURCES')) + '</span> ' + (sources || '—') + '</div>'
      + '</div>';

    // The body is HTML of the site; editors see it as it reads, or as HTML
    li.querySelector('[data-field="body"]').innerHTML = step.body;

    return li;
  };

  const render = () => {
    list.innerHTML = '';
    steps.forEach((step, index) => list.append(stepCard(step, index)));
    $('guides-step-count').textContent = steps.length;

    if (!steps.length) {
      list.innerHTML = '<li class="text-muted guides-steps__empty">' + escape(t('NO_STEPS')) + '</li>';
    }

    renderAttachTargets();
  };

  const stepOf = (element) => {
    const card = element.closest('.guides-step');

    return card ? steps.find((step) => step.key === card.dataset.key) : null;
  };

  list.addEventListener('input', (event) => {
    const step = stepOf(event.target);
    const name = event.target.dataset.field;

    if (!step || !name) {
      return;
    }

    if (name === 'body') {
      step.body = event.target.innerHTML;
    } else if (name === 'html') {
      step.body = event.target.value;
    } else {
      step[name] = event.target.value;
    }

    if (name === 'title') {
      renderAttachTargets();
    }

    changed();
  });

  list.addEventListener('click', (event) => {
    const button = event.target.closest('button');
    const step = button && stepOf(button);

    if (!step) {
      return;
    }

    const index = steps.indexOf(step);

    if (button.dataset.move) {
      const to = index + parseInt(button.dataset.move, 10);

      if (to >= 0 && to < steps.length) {
        steps.splice(to, 0, steps.splice(index, 1)[0]);
        render();
        changed();
      }
    } else if (button.hasAttribute('data-remove')) {
      steps.splice(index, 1);
      render();
      changed();
    } else if (button.hasAttribute('data-detach')) {
      // An own copy: changing it no longer changes the other guides
      step.id = 0;
      step.used = 1;
      render();
      changed();
    } else if (button.dataset.removeSource !== undefined) {
      step.sources.splice(parseInt(button.dataset.removeSource, 10), 1);
      render();
      changed();
    } else if (button.hasAttribute('data-toggle-html')) {
      const card = button.closest('.guides-step');
      const visual = card.querySelector('[data-field="body"]');
      const html = card.querySelector('[data-field="html"]');
      const showHtml = html.classList.contains('d-none');

      if (showHtml) {
        html.value = step.body;
      } else {
        visual.innerHTML = step.body;
      }

      html.classList.toggle('d-none', !showHtml);
      visual.classList.toggle('d-none', showHtml);
      button.textContent = t(showHtml ? 'STEP_VISUAL' : 'STEP_HTML');
    }
  });

  // Dragging by the handle
  list.addEventListener('dragstart', (event) => {
    const card = event.target.closest('.guides-step');

    if (!card) {
      return;
    }

    dragged = card.dataset.key;
    card.classList.add('guides-step--dragging');
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', dragged);
    event.dataTransfer.setDragImage(card, 20, 20);
  });

  list.addEventListener('dragover', (event) => {
    if (dragged) {
      event.preventDefault();
    }
  });

  list.addEventListener('drop', (event) => {
    const card = event.target.closest('.guides-step');

    if (!dragged || !card || card.dataset.key === dragged) {
      return;
    }

    event.preventDefault();

    const from = steps.findIndex((step) => step.key === dragged);
    const to = steps.findIndex((step) => step.key === card.dataset.key);
    steps.splice(to, 0, steps.splice(from, 1)[0]);
    render();
    changed();
  });

  list.addEventListener('dragend', () => {
    dragged = null;
    list.querySelectorAll('.guides-step--dragging').forEach((card) => card.classList.remove('guides-step--dragging'));
  });

  const addStep = (step) => {
    steps.push({ id: 0, title: '', body: '', image: '', note: '', used: 1, sources: [], ...step, key: Math.random().toString(36).slice(2) });
    render();
    changed();
    list.lastElementChild?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  };

  $('guides-add-step').addEventListener('click', () => {
    addStep({});
    list.lastElementChild?.querySelector('[data-field="title"]')?.focus();
  });

  // ---------------------------------------------------------------- taking steps from articles

  const results = $('guides-results');
  const sections = $('guides-sections');
  let attachTargets = [];

  function renderAttachTargets() {
    attachTargets = steps.map((step, index) => ({ key: step.key, label: (index + 1) + '. ' + (step.title || '…') }));

    sections.querySelectorAll('select[data-attach]').forEach((select) => {
      select.innerHTML = '<option value="">' + escape(t('ATTACH_NONE')) + '</option>'
        + attachTargets.map((target) => '<option value="' + target.key + '">' + escape(target.label) + '</option>').join('');
    });
  }

  const search = async () => {
    const words = $('guides-search').value.trim();

    if (!words) {
      return;
    }

    results.innerHTML = '<div class="small text-muted">' + escape(t('WORKING')) + '</div>';
    sections.innerHTML = '';

    try {
      const items = await call('search', { words, text: $('guides-search-text').checked ? 1 : 0 });

      results.innerHTML = items.length ? '' : '<div class="small text-muted">' + escape(t('NO_RESULTS')) + '</div>';
      items.forEach((item) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'list-group-item list-group-item-action small';
        button.innerHTML = '<div class="fw-bold">' + escape(item.title) + '</div><div class="text-muted">' + escape(item.category)
          + (item.source_type === 'flexicontent' ? ' · FLEXIcontent' : '')
          + (item.used ? ' · ' + escape(t('USED_N', item.used)) : '') + '</div>';
        button.addEventListener('click', () => showSections(item, button));
        results.append(button);
      });
    } catch (error) {
      results.innerHTML = '<div class="alert alert-danger small">' + escape(error.message) + '</div>';
    }
  };

  const showSections = async (item, button) => {
    results.querySelectorAll('.active').forEach((active) => active.classList.remove('active'));
    button.classList.add('active');
    sections.innerHTML = '<div class="small text-muted">' + escape(t('WORKING')) + '</div>';

    try {
      const data = await call('sections', { source_type: item.source_type, item_id: item.item_id });

      sections.innerHTML = '<div class="small fw-bold mb-1"><a href="' + escape(data.url) + '" target="_blank" rel="noopener">' + escape(data.title) + '</a></div>';

      if (!data.sections.length) {
        sections.insertAdjacentHTML('beforeend', '<div class="small text-muted">' + escape(t('NO_SECTIONS')) + '</div>');
      }

      data.sections.forEach((section) => {
        const box = document.createElement('div');
        const source = { source_type: data.source_type, item_id: data.item_id, section: section.section, title: data.title, hash: section.hash };

        box.className = 'guides-section';
        box.innerHTML = '<div class="small fw-bold">' + escape(section.section || t('INTRO_SECTION')) + '</div>'
          + '<div class="small text-muted guides-section__text">' + escape(section.text) + '</div>'
          + '<div class="d-flex gap-1 mt-1">'
          + '<button type="button" class="btn btn-sm btn-outline-primary text-nowrap" data-add>' + escape(t('ADD_AS_STEP')) + '</button>'
          + '<select class="form-select form-select-sm" data-attach aria-label="' + escape(t('ATTACH_TO')) + '"></select>'
          + '</div>';
        box.querySelector('[data-add]').addEventListener('click', () => addStep({ title: section.section || data.title, body: section.html, sources: [source] }));
        box.querySelector('[data-attach]').addEventListener('change', (event) => {
          const step = steps.find((one) => one.key === event.target.value);

          if (step && !step.sources.some((one) => one.item_id === source.item_id && one.section === source.section)) {
            step.sources.push(source);
            render();
            changed();
          }

          event.target.value = '';
        });
        sections.append(box);
      });

      renderAttachTargets();
    } catch (error) {
      sections.innerHTML = '<div class="alert alert-danger small">' + escape(error.message) + '</div>';
    }
  };

  $('guides-search-go').addEventListener('click', search);
  $('guides-search').addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      search();
    }
  });

  // ---------------------------------------------------------------- the step library

  const library = $('guides-library-results');
  let libraryTimer = 0;

  const useStep = async (stepId, copy) => {
    try {
      const step = await call('library', { step_id: stepId });
      const used = Object.keys(step.guides || {}).length;

      addStep({
        id: copy ? 0 : step.id,
        title: step.title,
        body: step.body,
        image: step.image,
        note: step.note,
        used: copy ? 1 : used + 1,
        sources: (step.sources || []).map((source) => ({ ...source, item_id: parseInt(source.item_id, 10) })),
      });
    } catch (error) {
      message(escape(error.message), 'danger');
    }
  };

  $('guides-library').addEventListener('input', (event) => {
    window.clearTimeout(libraryTimer);
    libraryTimer = window.setTimeout(async () => {
      const words = event.target.value.trim();

      if (!words) {
        library.innerHTML = '';
        return;
      }

      try {
        const rows = await call('library', { words });
        const inGuide = new Set(steps.map((step) => step.id).filter(Boolean));

        library.innerHTML = rows.length ? '' : '<div class="small text-muted">' + escape(t('NO_RESULTS')) + '</div>';
        rows.forEach((row) => {
          const item = document.createElement('div');
          item.className = 'list-group-item small';
          item.innerHTML = '<div class="fw-bold">' + escape(row.title) + '</div>'
            + '<div class="text-muted">' + escape(row.product) + (row.product ? ' · ' : '') + escape(t('USED_GUIDES_N', row.used)) + '</div>'
            + '<div class="d-flex gap-1 mt-1">'
            + '<button type="button" class="btn btn-sm btn-outline-primary" data-use' + (inGuide.has(parseInt(row.id, 10)) ? ' disabled' : '') + '>' + escape(t('USE_STEP')) + '</button>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-copy>' + escape(t('COPY_STEP')) + '</button></div>';
          item.querySelector('[data-use]').addEventListener('click', () => useStep(row.id, false));
          item.querySelector('[data-copy]').addEventListener('click', () => useStep(row.id, true));
          library.append(item);
        });
      } catch (error) {
        library.innerHTML = '<div class="alert alert-danger small">' + escape(error.message) + '</div>';
      }
    }, 300);
  });

  // ---------------------------------------------------------------- placement

  const suggestions = $('guides-suggestions');

  $('guides-suggest').addEventListener('click', async () => {
    suggestions.innerHTML = '<div class="small text-muted">' + escape(t('WORKING')) + '</div>';

    const sources = steps.flatMap((step) => step.sources.map((source) => ({ source_type: source.source_type, item_id: source.item_id })));

    try {
      const data = await call('placement', { sources: JSON.stringify(sources), title: value('title'), summary: value('summary') });

      suggestions.innerHTML = '';

      if (data.mode === 'topic') {
        if (!data.categories.length) {
          suggestions.innerHTML = '<div class="small text-muted">' + escape(t('SUGGEST_NONE')) + '</div>';
        }

        data.categories.forEach((category) => {
          const row = document.createElement('div');
          row.className = 'd-flex justify-content-between align-items-center gap-2 small border-bottom py-1';
          row.innerHTML = '<div><div class="fw-bold">' + escape(category.new_child ? t('SUGGEST_CATEGORY_NEW', category.title) : category.title) + '</div>'
            + '<div class="text-muted">' + escape(category.path)
            + (category.new_child ? '' : ' · ' + escape(t('SUGGEST_SCORE', Math.round(category.score * 100))))
            + (category.votes ? ' · ' + escape(t('SUGGEST_VOTES', category.votes)) : '') + '</div></div>'
            + (category.new_child ? '' : '<button type="button" class="btn btn-sm btn-outline-primary">' + escape(t('CHOOSE')) + '</button>');
          row.querySelector('button')?.addEventListener('click', () => setCategory(category.id));
          suggestions.append(row);
        });
      } else {
        if (data.catid && !parseInt(value('catid'), 10)) {
          setCategory(data.catid);
        }

        if (!data.tags.length) {
          suggestions.innerHTML = '<div class="small text-muted">' + escape(t('SUGGEST_NONE')) + '</div>';
        }

        const chosen = new Set(tagValues());

        data.tags.forEach((group) => {
          const box = document.createElement('div');
          box.className = 'mb-2';
          box.innerHTML = '<div class="small fw-bold">' + escape(group.root) + '</div>';

          group.tags.forEach((tag) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm me-1 mb-1 ' + (chosen.has(tag.id) ? 'btn-secondary' : 'btn-outline-primary');
            button.disabled = chosen.has(tag.id);
            button.textContent = (chosen.has(tag.id) ? '✓ ' : '+ ') + tag.title + ' (' + tag.votes + ')';
            button.addEventListener('click', () => {
              addTag(tag.id);
              button.disabled = true;
              button.className = 'btn btn-sm me-1 mb-1 btn-secondary';
              button.textContent = '✓ ' + tag.title + ' (' + tag.votes + ')';
            });
            box.append(button);
          });

          suggestions.append(box);
        });
      }
    } catch (error) {
      suggestions.innerHTML = '<div class="alert alert-danger small">' + escape(error.message) + '</div>';
    }
  });

  // ---------------------------------------------------------------- save, review, preview

  const collect = () => ({
    id,
    title: value('title'),
    summary: value('summary'),
    prerequisites: value('prerequisites'),
    verify: value('verify'),
    language: value('language') || '*',
    product: value('product'),
    target_type: value('target_type') || 'content',
    fc_type: parseInt(value('fc_type'), 10) || 0,
    catid: parseInt(value('catid'), 10) || 0,
    tags: tagValues(),
    next_guide_id: parseInt(value('next_guide_id'), 10) || 0,
    steps: steps.map((step) => ({
      id: step.id || 0,
      title: step.title,
      body: step.body,
      image: step.image,
      note: step.note,
      sources: step.sources,
    })),
  });

  const showState = (next) => {
    state = next;
    $('guides-state').textContent = t('STATE_' + next.toUpperCase());
    $('guides-approve')?.classList.toggle('d-none', !['review', 'stale'].includes(next));
  };

  const busy = (on) => {
    root.querySelectorAll('.guides-bar button').forEach((button) => { button.disabled = on; });
  };

  const save = async () => {
    const data = collect();

    if (!data.title.trim()) {
      message(escape(t('TITLE_NEEDED')), 'warning');
      field('title')?.focus();
      throw new Error('');
    }

    const saved = await call('save', { guide: JSON.stringify(data) });
    const guide = await call('load', { id: saved.id });

    // The steps as kept, with their IDs, so the next save updates them
    steps = guide.steps.map((step) => ({
      id: parseInt(step.id, 10),
      title: step.title,
      body: step.body,
      image: step.image,
      note: step.note,
      used: Object.keys(step.guides || {}).length || 1,
      sources: step.sources.map((source) => ({ ...source, item_id: parseInt(source.item_id, 10) })),
      key: Math.random().toString(36).slice(2),
    }));

    if (!id) {
      id = saved.id;
      window.history.replaceState(null, '', root.dataset.edit + id);
    }

    dirty = false;
    render();
    showState(saved.state);

    return saved;
  };

  $('guides-save').addEventListener('click', async () => {
    busy(true);

    try {
      await save();
      message(escape(t('SAVED')), 'success');
    } catch (error) {
      if (error.message) {
        message(escape(error.message), 'danger');
      }
    } finally {
      busy(false);
    }
  });

  $('guides-submit').addEventListener('click', async () => {
    busy(true);

    try {
      await save();
      const guide = await call('submit', { id });
      showState(guide.state);
      message(escape(t('SUBMITTED_DONE')), 'success');
    } catch (error) {
      if (error.message) {
        message(escape(error.message), 'danger');
      }
    } finally {
      busy(false);
    }
  });

  $('guides-approve')?.addEventListener('click', async () => {
    // Saving would send the guide back to draft: what is approved is what was sent for review
    if (dirty || !window.confirm(t('APPROVE_CONFIRM'))) {
      if (dirty) {
        message(escape(t('PREVIEW_SAVE_FIRST')), 'warning');
      }

      return;
    }

    busy(true);

    try {
      const published = await call('approve', { id });
      showState('published');
      message(escape(t('APPROVED')) + ' <a href="' + escape(published.url) + '" target="_blank" rel="noopener">' + escape(t('PUBLISHED_AT')) + '</a>', 'success');
    } catch (error) {
      message(escape(error.message), 'danger');
    } finally {
      busy(false);
    }
  });

  const dialog = $('guides-preview');

  dialog.querySelectorAll('[data-guides-close]').forEach((button) => button.addEventListener('click', () => dialog.close()));

  $('guides-preview-button').addEventListener('click', async () => {
    if (!id || dirty) {
      message(escape(t('PREVIEW_SAVE_FIRST')), 'warning');
      return;
    }

    const body = $('guides-preview-body');
    body.textContent = t('WORKING');
    dialog.showModal();

    try {
      const data = await call('preview', { id });
      const iframe = document.createElement('iframe');

      // The article as visitors will read it, in a frame that runs no scripts
      iframe.setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox');
      iframe.className = 'guides-frame guides-frame--full';
      iframe.srcdoc = '<!doctype html><meta charset="utf-8"><base target="_blank"><style>'
        + 'body{font:15px/1.6 system-ui,sans-serif;margin:0;padding:16px;color:#222;max-width:46em}img{max-width:100%;height:auto}'
        + 'h2{font-size:1.25em;margin:1.4em 0 .5em}.guide-source,.guide-next{font-size:.9em;color:#555}.guide-note{background:#fff8e1;padding:6px 10px;border-radius:4px}'
        + '</style>' + data.html;
      body.textContent = '';
      body.append(iframe);
    } catch (error) {
      body.innerHTML = '<div class="alert alert-danger">' + escape(error.message) + '</div>';
    }
  });

  // Changes to the guide's own fields
  ['guides-meta', 'guides-placement'].forEach((box) => {
    $(box).addEventListener('input', changed);
    $(box).addEventListener('change', changed);
  });

  window.addEventListener('beforeunload', (event) => {
    if (dirty) {
      event.preventDefault();
      event.returnValue = t('LEAVE_UNSAVED');
    }
  });

  render();
})();
