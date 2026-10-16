/**
 * @package     MCP Server for Joomla
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */
// Demo mode: a canned answer in place of the answer service, to try the interface before the back end exists.
// It emits the same events as the real endpoint (see ask.js).
((window, document) => {
  'use strict';

  const api = window.McpAsk = window.McpAsk || {};
  const chinese = document.documentElement.lang.toLowerCase().startsWith('zh');

  const wait = (ms, signal) => new Promise((resolve, reject) => {
    const stop = () => reject(new DOMException('Aborted', 'AbortError'));

    if (signal.aborted) {
      stop();

      return;
    }

    signal.addEventListener('abort', stop, { once: true });
    window.setTimeout(() => {
      signal.removeEventListener('abort', stop);
      resolve();
    }, ms);
  });

  const sources = () => {
    const root = document.querySelector('[data-mcp-ask][data-demo-sources]');
    let items = [];

    try {
      items = JSON.parse(root.dataset.demoSources);
    } catch (error) {
      // Fall back to the current page
    }

    return items.length ? items : [{ title: document.title, url: window.location.href }];
  };

  const answer = (question, count) => {
    const cite = (number) => `[${Math.min(number, count)}]`;

    return chinese
      ? `## 示範回答\n\n這是**示範模式**的固定內容，用來確認介面與動態效果；正式啟用後，這裡會是依官網內容整理的回答 ${cite(1)}。\n\n`
        + `您問的是：「${question}」\n\n### 重點整理\n\n`
        + `- 回答會逐字出現，並在句尾標示來源編號 ${cite(2)}\n`
        + `- 點選編號或下方的來源卡片，會先收合對話，再轉場到該頁面 ${cite(3)}\n`
        + '- 回到任一頁面後，點搜尋圖示可以繼續這段對話\n\n'
        + '可以直接在下方輸入框繼續追問。'
      : `## Demo answer\n\nThis is the fixed text of **demo mode**, for trying the interface and its motion. Once the service is set up, the answer is compiled from this site's content ${cite(1)}.\n\n`
        + `You asked: "${question}"\n\n### Key points\n\n`
        + `- The answer appears as it is written, with numbered references ${cite(2)}\n`
        + `- Choosing a number or a source card puts the conversation away, then moves to that page ${cite(3)}\n`
        + '- On any page, the search icon brings the conversation back\n\n'
        + 'Ask a follow-up in the box below.';
  };

  api.transport = async function* demo(request, signal) {
    const items = sources();
    const text = answer(request.question, items.length);

    yield { event: 'status', data: { text: chinese ? '正在搜尋官網內容…' : 'Searching the site…' } };
    await wait(1400, signal);
    yield { event: 'sources', data: { items } };
    yield {
      event: 'status',
      data: { text: chinese ? `找到 ${items.length} 篇相關內容，整理中…` : `Found ${items.length} pages, writing…` },
    };
    await wait(1200, signal);

    for (let index = 0; index < text.length; index += 3) {
      yield { event: 'delta', data: { text: text.slice(index, index + 3) } };
      await wait(24, signal);
    }

    yield { event: 'done', data: { ref: 'demo' } };
  };
})(window, document);
