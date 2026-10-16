/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 *
 * Preview of the module for the Appearance page: the module's own styles and script, with a canned
 * answer that holds everything a theme colours. The page that frames it sends the theme with
 * postMessage ({type: "mcpask-theme", css}) and may switch between the conversation and the
 * buttons ({type: "mcpask-view", view: "chat" | "launcher"}).
 */
(() => {
  'use strict';

  const query = new URLSearchParams(window.location.search);
  // Where the module's files are: next to this component's in an installed site
  const assets = new URL(query.get('assets') || '../../mod_mcp_ask/', window.location.href);
  const image = new URL('images/ufo.png', assets).pathname;
  const view = query.get('view') === 'launcher' ? 'launcher' : 'chat';

  const strings = {
    title: '詢問官網內容', placeholder: '想了解什麼？', followup: '繼續追問…', empty: '想了解什麼？', send: '送出', close: '關閉',
    newChat: '新對話', resume: '回到對話', sources: '資料來源', here: '在此詢問', more: '顯示全部 %s 筆', others: '其他 %s 筆', less: '收合',
    searching: '正在搜尋官網內容…', note: '回答由 AI 產生，可能有誤，請以來源頁面為準。', noAnswer: '沒有收到回答，請再試一次。',
    error: '發生錯誤，請再試一次。', unconfigured: '尚未設定。', helpful: '有幫助', notHelpful: '沒幫助', why: '哪裡不清楚或有錯？（選填）',
    thanks: '謝謝您的回饋。', previous: '上一張', next: '下一張', calloutNote: '說明', calloutTip: '提示', calloutWarning: '注意',
  };

  const launcher = (mode) => {
    const root = document.createElement('div');
    const ai = '<svg class="mcp-ask__ai" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4h10a4 4 0 0 1 4 4v5a4 4 0 0 1-4 4h-5.2l-4.3 3.4V17H7a4 4 0 0 1-4-4V8a4 4 0 0 1 4-4z"/><path class="mcp-ask__spark" d="M10.4 6.9c.38 2.07 1.45 3.14 3.5 3.5-2.05.37-3.12 1.44-3.5 3.5-.37-2.06-1.44-3.13-3.5-3.5 2.06-.36 3.13-1.43 3.5-3.5z"/><path class="mcp-ask__spark mcp-ask__spark--small" d="M16.1 6.2c.18.95.66 1.43 1.6 1.6-.94.18-1.42.66-1.6 1.6-.17-.94-.65-1.42-1.6-1.6.95-.17 1.43-.65 1.6-1.6z"/></svg>';
    const arrow = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';

    root.className = 'mcp-ask' + (mode !== 'icon' ? ' mcp-ask--' + mode : '');
    root.dataset.mcpAsk = '';
    root.dataset.mode = mode;
    root.dataset.state = 'idle';
    root.dataset.siteRoot = '';
    root.dataset.ufo = new URL('images/ufo.png', assets).href;
    root.dataset.strings = JSON.stringify(strings);
    root.innerHTML = '<form class="mcp-ask__form" role="search">'
      + `<button type="button" class="mcp-ask__toggle" aria-label="${strings.title}">${ai}${mode === 'float' ? `<svg class="mcp-ask__send" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>` : ''}</button>`
      + `<input class="mcp-ask__input" type="text" autocomplete="off" maxlength="500" placeholder="${strings.placeholder}" aria-label="${strings.placeholder}">`
      + '<span class="mcp-ask-dots" aria-hidden="true"><i></i><i></i><i></i></span>'
      + `<button type="submit" class="mcp-ask__submit" aria-label="${strings.send}">${arrow}</button>`
      + '</form><span class="mcp-ask__status" role="status"></span>';

    return root;
  };

  document.getElementById('preview-icon').append(launcher('icon'));
  document.getElementById('preview-box').append(launcher('box'));
  document.getElementById('preview-float').append(launcher('float'));

  const answer = [
    '要開始使用，請依下列步驟完成設定 [1]。',
    '',
    '## 設定步驟',
    '',
    '1. 登入**管理後台**，點選右上角的「設定」。[1]',
    `   ![設定畫面](${image})`,
    '2. 在「通知」頁籤選擇提醒方式，例如 `Email` 或 LINE。[2]',
    '3. 按「儲存」完成設定。[1]',
    '',
    '| 方案 | 適用 | 提醒方式 |',
    '|---|---|---|',
    '| 基本版 | 小型團隊 | Email |',
    '| 專業版 | 中大型企業 | Email、LINE |',
    '',
    '> [!TIP]',
    '> 設定前可以先複製一份既有範本再修改。',
    '',
    '> [!WARNING]',
    '> 儲存後會立即套用到所有使用者。',
    '',
    '更多說明請見[使用手冊](/)。',
  ].join('\n');

  window.McpAsk = window.McpAsk || {};
  window.McpAsk.transport = async function* () {
    yield { event: 'sources', data: { items: [
      { title: '如何設定通知與提醒？', url: '/', section: '設定步驟', site: '網站名稱' },
      { title: '通知方式說明', url: '/', section: '', site: '網站名稱' },
      { title: '方案比較', url: '/', section: '', site: '網站名稱' },
    ] } };
    yield { event: 'delta', data: { text: answer } };
    yield { event: 'done', data: { ref: 'demo' } };
  };

  const theme = document.createElement('style');
  theme.id = 'mcpask-theme';

  const load = (tag, attributes) => new Promise((resolve) => {
    const element = document.createElement(tag);
    Object.assign(element, attributes);
    element.onload = resolve;
    element.onerror = resolve;
    document.head.append(element);
  });

  window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin || !event.data || typeof event.data !== 'object') {
      return;
    }

    if (event.data.type === 'mcpask-theme') {
      // Only custom properties of the module, which the page that sends them built from checked colours
      theme.textContent = String(event.data.css || '').replace(/[<>]/g, '');
    } else if (event.data.type === 'mcpask-view') {
      const url = new URL(window.location.href);
      url.searchParams.set('view', event.data.view === 'launcher' ? 'launcher' : 'chat');
      window.location.replace(url);
    }
  });

  (async () => {
    try {
      sessionStorage.clear();
    } catch (error) {
      // Nothing kept to clear
    }

    await load('link', { rel: 'stylesheet', href: new URL('css/ask.css', assets).href });
    document.head.append(theme);
    await load('script', { src: new URL('js/ask.js', assets).href });

    if (window.parent !== window) {
      window.parent.postMessage({ type: 'mcpask-preview-ready' }, window.location.origin);
    }

    if (view === 'chat') {
      const input = document.querySelector('.mcp-ask--box .mcp-ask__input');
      input.value = '要怎麼設定通知？';
      window.setTimeout(() => input.closest('form').requestSubmit(), 100);

      // Once the answer is in, from its top: the question, the answer and its sources at a glance
      window.setTimeout(() => {
        const log = document.querySelector('.mcp-ask-chat__log');

        if (log) {
          log.scrollTop = 0;
        }
      }, 1800);
    }
  })();
})();
