/**
 * @package     MCP Server for Joomla
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */
((document, Joomla) => {
  'use strict';

  const root = document.getElementById('mcpask-quality');
  const form = document.getElementById('mcpask-rule');

  if (!root) {
    return;
  }

  const text = (key, ...values) => values.reduce(
    // Placeholders as PHP writes them: %s, %d, or numbered (%1$s)
    (result, value) => result.replace(/%(?:\d+\$)?[sd]/, value),
    Joomla.Text._(`COM_MCPASK_${key}`),
  );

  const call = async (task, fields = {}) => {
    const body = new URLSearchParams({ task: `index.${task}`, [root.dataset.token]: '1', ...fields });
    const response = await fetch(root.dataset.url, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const json = await response.json();

    if (!json.success) {
      throw new Error(json.message || response.statusText);
    }

    return json.data;
  };

  const element = (tag, className, content) => {
    const node = document.createElement(tag);

    node.className = className || '';

    if (content !== undefined) {
      node.textContent = content;
    }

    return node;
  };

  // Deleting works from the list even without the editor
  root.querySelectorAll('[data-mcpask-rule-delete]').forEach((button) => {
    button.addEventListener('click', async () => {
      if (window.confirm(text('RULE_DELETE_CONFIRM'))) {
        await call('ruleDelete', { id: button.dataset.mcpaskRuleDelete });
        window.location.reload();
      }
    });
  });

  if (!form) {
    return;
  }

  const question = form.elements.question;
  const variants = form.elements.variants;
  const note = form.elements.note;
  const language = form.elements.language;
  const state = form.elements.state;
  const search = document.getElementById('mcpask-rule-search');
  const results = document.getElementById('mcpask-rule-results');
  const chosen = document.getElementById('mcpask-rule-pages');
  const message = document.getElementById('mcpask-rule-message');
  const answer = document.getElementById('mcpask-rule-answer');
  let pages = [];
  let timer = 0;

  const say = (content, kind = '') => {
    message.className = `mt-2 mb-0 ${kind}`;
    message.textContent = content;
  };

  const page = (item) => `${item.source}:${item.item_id}`;

  const paintPages = () => {
    chosen.replaceChildren(...pages.map((item) => {
      const row = element('li', 'list-group-item d-flex justify-content-between align-items-start gap-2');
      const label = element('div', 'ms-2 me-auto');
      const remove = element('button', 'btn btn-sm btn-link text-danger p-0', text('RULE_REMOVE'));

      label.append(element('span', '', item.title || page(item)));

      if (item.site) {
        label.append(element('span', 'text-muted small', `（${item.site}）`));
      }

      remove.type = 'button';
      remove.addEventListener('click', () => {
        pages = pages.filter((other) => page(other) !== page(item));
        paintPages();
      });
      row.append(label, remove);

      return row;
    }));
    chosen.hidden = !pages.length;
  };

  const fill = (rule) => {
    form.elements.id.value = rule.id || 0;
    question.value = rule.question || '';
    variants.value = rule.variants || '';
    note.value = rule.note || '';
    language.value = rule.language || '*';
    state.checked = rule.state === undefined ? true : Boolean(rule.state);
    pages = (rule.pages || []).map(({ source, item_id: itemId, title, site }) => ({ source, item_id: itemId, title, site }));
    paintPages();
    answer.hidden = true;
    say('');
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    (question.value ? search : question).focus({ preventScroll: true });
  };

  // Pages are found by words of their title, as they are indexed
  search.addEventListener('input', () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(async () => {
      const words = search.value.trim();

      if (!words) {
        results.replaceChildren();

        return;
      }

      const found = await call('lookup', { words });

      results.replaceChildren(...(found.length ? found.map((item) => {
        const row = element('button', 'list-group-item list-group-item-action d-flex justify-content-between gap-2');

        row.type = 'button';
        row.append(element('span', '', item.title), element('span', 'text-muted small text-nowrap', item.site || text('RULE_ADD')));
        row.disabled = pages.some((other) => page(other) === page(item));
        row.addEventListener('click', () => {
          if (pages.length < 5 && !pages.some((other) => page(other) === page(item))) {
            pages.push(item);
            paintPages();
          }

          search.value = '';
          results.replaceChildren();
          search.focus();
        });

        return row;
      }) : [element('div', 'list-group-item text-muted', text('RULE_SEARCH_NONE'))]));
    }, 250);
  });

  root.querySelectorAll('[data-mcpask-rule-for]').forEach((button) => {
    button.addEventListener('click', () => fill({ question: button.dataset.mcpaskRuleFor }));
  });

  root.querySelectorAll('[data-mcpask-rule-edit]').forEach((button) => {
    button.addEventListener('click', () => fill(JSON.parse(button.dataset.mcpaskRuleEdit)));
  });

  form.querySelector('[data-mcpask-rule="reset"]').addEventListener('click', () => fill({}));

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (!question.value.trim() || (!pages.length && !note.value.trim())) {
      say(text('RULE_NEEDS'), 'text-danger');

      return;
    }

    say(text('WORKING'));

    try {
      await call('ruleSave', {
        id: form.elements.id.value,
        question: question.value.trim(),
        variants: variants.value,
        documents: JSON.stringify(pages.map(({ source, item_id: itemId }) => ({ source, item_id: itemId }))),
        note: note.value.trim(),
        language: language.value,
        state: state.checked ? 1 : 0,
      });
      say(text('RULE_SAVED'), 'text-success');
      window.setTimeout(() => window.location.reload(), 900);
    } catch (error) {
      say(error.message, 'text-danger');
    }
  });

  // The question as a visitor would ask it, answered with the rules as they are saved
  form.querySelector('[data-mcpask-rule="try"]').addEventListener('click', async (event) => {
    const button = event.currentTarget;

    if (!question.value.trim()) {
      say(text('RULE_NEEDS'), 'text-danger');

      return;
    }

    button.disabled = true;
    say(text('RULE_TRYING'));

    try {
      const result = await call('ruleTry', { question: question.value.trim(), language: language.value });

      document.getElementById('mcpask-rule-answer-meta').textContent = result.hints.length
        ? text('RULE_TRY_HINTS', result.hints.join('」「'))
        : text('RULE_TRY_NO_HINT');
      document.getElementById('mcpask-rule-answer-text').textContent = result.text;
      document.getElementById('mcpask-rule-answer-sources').replaceChildren(...result.sources.map((source) => {
        const item = element('li');
        const link = element('a', '', source.title);

        link.href = source.url;
        link.target = '_blank';
        link.rel = 'noopener';
        item.append(link);

        if (source.site) {
          item.append(element('span', 'text-muted', `（${source.site}）`));
        }

        return item;
      }));
      answer.hidden = false;
      say('');
    } catch (error) {
      say(error.message, 'text-danger');
    }

    button.disabled = false;
  });

  paintPages();
})(document, window.Joomla);
