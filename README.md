# MCP Server for Joomla

Connect AI assistants — Claude, ChatGPT, Cursor, VS Code and any other [Model Context Protocol](https://modelcontextprotocol.io) client — to your Joomla site. Ask for content statistics, search articles, write drafts, set tags and custom fields, and publish, all with the permissions of your own Joomla account.

> **Status: beta (0.6.6).** Tested on a live Joomla 5.4 site with claude.ai. Please read [Tested with](#tested-with) and report problems in the issue tracker.

[繁體中文說明](#繁體中文)

## Features

- **Sign in with OAuth.** Paste the URL into claude.ai, ChatGPT or Claude Code, sign in to your Joomla site (MFA included) and approve permissions. No tokens to copy. API tokens work too.
- **One package, no extra software.** Pure PHP, no Node.js, Composer or background process. Works on shared hosting.
- **Admin dashboard** with the endpoint URL, copy-paste configuration for each AI client, a health check that spots common hosting problems, and an activity log.
- **Safe by default.** New articles are unpublished, trashing is off, read-only mode and category limits are one click away, and every tool call is logged.
- **Uses Joomla itself.** Writes go through the core models, so ACL, versions, workflows, content plugins, Smart Search and the User Actions Log all work as usual.
- **Extensible.** Tools are plugins in the `mcp` group; any extension can add its own, or take over existing tools.
- **Beyond articles.** Menus, modules, Akeeba Backup health and backups, and EasyBlog posts, FLEXIcontent items or JReviews listings when those are your content system.

## Requirements

- Joomla 4.4, 5.x or 6.x
- PHP 8.1 or newer
- HTTPS (strongly recommended — the API token travels in a request header)

## Installation

1. Download `pkg_mcp-x.y.z.zip` from the releases and install it in **System → Install → Extensions**.
2. Open **Components → MCP Server**. The health check should be all green.
3. Connect your assistant:
   - **claude.ai, Claude Desktop, mobile:** Settings → Connectors → Add custom connector → paste the endpoint URL shown on the dashboard (`https://your-site/api/index.php/v1/mcp`) → Connect → sign in → Allow.
   - **Claude Code:** `claude mcp add --transport http joomla https://your-site/api/index.php/v1/mcp`, then `/mcp` → Authenticate.
   - **Clients without OAuth:** enable the API token in your user account (Joomla API Token tab) and copy the configuration snippet from the dashboard.

## How OAuth works

The MCP endpoint is an OAuth 2.1 protected resource, and Joomla is its authorization server, following the [MCP authorization specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization):

- Authorization code flow with PKCE (S256) only; refresh tokens are rotated, and reuse of an old refresh token ends the connection.
- Clients register with dynamic client registration (RFC 7591) or a client ID metadata document (used by claude.ai).
- Sign-in and consent use the Joomla front-end login, so multi-factor authentication applies. People choose which permissions (read, write, publish, trash) to grant.
- Redirects are only allowed to configured domains (claude.ai, chatgpt.com, … by default) and to localhost for desktop and CLI clients.
- Only SHA-256 hashes of codes and tokens are stored. **Components → MCP Server → Connections** lists and revokes connections.
- Discovery works without any web server configuration: all metadata is served below `/api/index.php/v1/mcp`. A system plugin also answers `/.well-known/…` at the domain root when the server passes such URLs to Joomla.

## Tools

| Tool | Access | What it does |
|---|---|---|
| `site_info` | read | Site, languages, time zone, your permissions, server settings |
| `search_articles` | read | Search and filter articles (category, tag, author, state, dates, language), sort by hits or dates |
| `get_article` | read | Full article with tags, custom field values, metadata and URLs |
| `list_categories` | read | Category tree with article counts |
| `list_tags` | read | Tags with usage counts |
| `list_fields` | read | Custom fields: names, types, options, categories |
| `content_overview` | read | Totals by state, recent activity, most read, most recent |
| `content_stats` | read | Counts and hits grouped by category, author, tag, language, state, year, quarter, month, week, day, weekday or hour |
| `create_article` | write | Create an article with tags (optionally creating new ones) and custom fields |
| `update_article` | write | Change any part of an article |
| `set_article_tags` | write | Add, remove or replace tags on several articles |
| `set_article_fields` | write | Set custom field values |
| `create_category` / `create_tag` | write | Create categories and tags |
| `set_article_state` | publish | Publish, unpublish, archive, feature |
| `trash_articles` | trash | Move to trash (off by default; nothing is ever deleted permanently) |

**Menus** (plugin *MCP - Menus*): `list_menus`, `list_menu_items`, `get_menu_item`, `create_menu`, `create_menu_item`, `update_menu_item` (also moves items), `set_menu_item_state`, `trash_menu_items`.

**Modules** (plugin *MCP - Modules*): `list_module_types`, `describe_module_type`, `list_module_positions`, `list_modules` (including "which modules are on this page"), `get_module`, `create_module`, `update_module`, `set_module_state`, `trash_modules`.

**Akeeba Backup** (plugin *MCP - Akeeba Backup*, only when Akeeba Backup is installed): `backup_status` (health check with warnings), `list_backups`, `get_backup_log`, `start_backup`, `continue_backup`.

**EasyBlog** (plugin *MCP - EasyBlog*, only when EasyBlog is installed): `list_blog_posts`, `get_blog_post`, `list_blog_categories`, `list_blog_tags`, `blog_overview`, `blog_stats`, `create_blog_post`, `update_blog_post`, `set_blog_post_state`, `trash_blog_posts`.

**Media folders** (plugin *MCP - Media*): `list_media`, `media_overview`, `upload_media`, `media_upload_policy`. Survey the media folders - what is in them, the mix of types, space by folder, the largest files, and what is not an image - and upload images to them, getting back the site-relative path to put in article HTML. Built on com_media's own API; no other extension needed. Reading needs the Media Manager's Manage permission. Uploading is off until an administrator turns it on: then images for anyone with Create, and other types only for a Super User and only those listed in the options. SVG, HTML, XML, JavaScript and anything executable are always refused.

**Ask the Site** (plugin *MCP - MCP Ask*, only when that extension is installed): `list_questions`, `ask_stats`, `ask_overview` to review what visitors asked, how they rated the answers and which questions the site could not answer; and, with Ask the Site 0.6.0 or later, `list_pending_questions`, `search_indexed_pages`, `list_source_rules`, `save_source_rule`, `delete_source_rule` and `try_question` to point those questions to the pages that answer them; and, with Ask the Site 0.8.0 or later, `list_agents`, `agent_stats`, `list_handoffs` and `list_agent_reports` over its agents. Super-User-only; saving and deleting rules are write and delete tools.

**JLex Helpful** (plugin *MCP - JLex Helpful*, only when that extension is installed): `list_article_feedback`, `article_feedback_stats`, `article_feedback_overview`. Review "was this helpful?" votes, the reasons given and the comments left, and find the articles with the lowest helpful ratio. Super-User-only and read-only.

**JReviews** (plugin *MCP - JReviews*, only when JReviews is installed): `list_listing_types`, `list_listing_fields`, `list_listings`, `get_listing`, `list_reviews`, `jreviews_stats`, `create_listing`, `update_listing`. Listings are searched and grouped by listing type, category, custom field values, rating and number of reviews. Reviews are read-only.

**FLEXIcontent** (plugin *MCP - FLEXIcontent*, only when FLEXIcontent is installed): the article tools above operate on FLEXIcontent items instead, with content types, multiple categories, FLEXIcontent tags and custom fields, plus `list_types`. Statistics can group by content type or by the values of a custom field.

With OAuth, a tool is only offered when the person granted its access level on the consent page.

Categories and tags can be referenced by ID, title or path, so the assistant rarely needs to look up IDs first.

## Configuration

**Components → MCP Server → Options**

- **Read-only mode.** Only reading and statistics tools are offered.
- **Default state of new articles.** Unpublished by default, so you can review AI-written content.
- **Allow publishing / Allow trashing**
- **Limit to categories.** Restrict everything to some categories and their children.
- **Disabled tools.** Hide single tools.
- **OAuth.** On/off, automatic client registration, allowed redirect domains, token lifetimes.
- **Activity log.** On/off, with or without arguments, retention in days.
- **Permissions.** "Use MCP Server" decides which user groups may connect (Super Users always can).

## Troubleshooting

**"Authorization header does not reach Joomla"** on the dashboard: some servers strip this header. API token users can switch to the `X-Joomla-Token` header (the dashboard does this automatically), but OAuth needs the Authorization header, so fix the server:

- Apache: use Joomla's `.htaccess` (rename `htaccess.txt`), or add `CGIPassAuth On`
- nginx + PHP-FPM: `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`

**A save returns a `warning`.** The article was stored, but a third-party plugin failed afterwards (for example a search indexer that cannot reach its server). The warning contains the plugin's message.

## Adding tools from your own extension

Create a plugin in the `mcp` group and subscribe to `onMcpRegisterTools`:

```php
use Gss\Component\Mcp\Administrator\Mcp\Tool;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;

public static function getSubscribedEvents(): array
{
    return ['onMcpRegisterTools' => 'onMcpRegisterTools'];
}

public function onMcpRegisterTools(\Joomla\Event\EventInterface $event): void
{
    $event->getArgument('registry')->add(new Tool(
        'count_contacts',
        'Count the contacts in the contact directory.',
        ['properties' => ['published' => ['type' => 'boolean']]],
        function (array $args, ToolContext $ctx): array {
            $db = $ctx->db;
            // ... query, or throw new ToolException('message the model can act on')
            return ['count' => 42];
        },
        'Count contacts',
        Tool::SCOPE_READ,
        'mycomponent',
        false,
        fn (ToolContext $ctx) => $ctx->user->authorise('core.manage', 'com_contact')
    ));
}
```

Return arrays (sent as JSON) or strings. Throw `ToolException` for errors the model should see and fix. The last argument hides the tool from users without permission. Tools with a scope other than `read` disappear automatically in read-only mode.

## Tested with

| | Status |
|---|---|
| Joomla 5.4 / PHP 8.5 / MySQL (MariaDB) | ✅ |
| claude.ai (OAuth, client ID metadata document) | ✅ |
| MCP TypeScript SDK (discovery, dynamic registration) | ✅ |
| Claude Code with API token | ✅ |
| Joomla 4.4 / PHP 8.1 (FLEXIcontent, EasyBlog sites) | ✅ |
| Joomla 6, PostgreSQL | ⚠️ written for, not yet tested |
| ChatGPT, Cursor, VS Code | ⚠️ not yet tested |

Reports for untested combinations are very welcome.

## Ask the Site (separate package)

`pkg_mcpask-x.y.z.zip` is a second, independent package in this repository: a search box for visitors that answers their questions from the site's own content with OpenAI, and names the pages each answer came from. It does not need the MCP server.

> **Status: experimental (0.12.0).** Runs on installed Joomla 4.4 and 6.1 sites with FLEXIcontent and the real OpenAI service; loads and reads native articles on Joomla 5.3 and 5.4; checked against the Joomla 6 source but not run there. Not yet used on a production site.

- **Module "MCP - Ask the Site"** (`mod_mcp_ask`): an icon, a search box, or a button floating in the lower corner of the window, that turns into a full-screen conversation, with the answer streamed in, numbered references and source cards. Choosing a source puts the conversation away and moves to that page with a view transition; the conversation can be reopened there.
- **Component "Ask the Site"** (`com_mcpask`): cuts published content into passages, stores an embedding for each (in the site's own database), finds the passages closest to a question and streams the answer written from them. Dashboard with setup checks, index status, a "synchronise" button and the questions visitors asked.
- **Content**: Joomla articles, or FLEXIcontent items when FLEXIcontent is installed, including text custom fields; and EasyBlog posts when EasyBlog is installed (0.4.0). Only what a visitor who is not signed in may read is indexed, and that is checked again when a passage is about to be quoted.
- **Back-end module "MCP - Questions Asked"** (`mod_mcp_ask_log`): the latest questions with who asked (account or guest, and address), for the back-end home page.
- **Plugins**: `Content - Ask the Site` re-indexes saved content and removes unpublished or deleted content at once, and `EasyBlog - Ask the Site` does the same for EasyBlog posts; `Task - Ask the Site` adds a scheduled task that synchronises the rest, passing over what has not changed since it last looked.
- **Several sites, one answer** (0.2.0): a site can read the index of other sites that run this extension and hold each other's export key, and answer from their public content as well, with each source marked by its site. Embeddings are reused when both sites make them the same way, so reading another site costs nothing at the AI service. The scheduled task keeps the copies up to date.
- **Websites you know, by feed or sitemap** (0.3.0): list other websites with the address of their RSS or Atom feed or their `sitemap.xml` (or just the home page, 0.5.0: the sitemap is then found, or the links followed), and their pages are read, indexed and answered from, each source marked by its site. Only pages on the same site as the list are read, robots.txt is respected, a feed that carries the full text saves fetching the page, and a page is read again only when the list shows it changed (or after a week when it gives no date). An optional CSS selector picks the content of a page; otherwise the main text is found by itself. This costs embedding tokens, and the text is trusted like your own, so list only sites you vouch for.
- **Probes are turned away for free** (0.2.2): text that tests for weaknesses instead of asking something (markup, scripts, database statements, commands, bare addresses) is refused before anything is sent to the AI service; an address that keeps at it is shut out for an hour, and addresses can be blocked for good.
- **Screenshots, tables and callouts in answers** (0.7.0): pages are indexed as light Markdown (lists with their numbers, tables, bold), with the images of the site's own pages kept where they stand. An answer may show a step's screenshot right after the step, a table, or a tip or warning box; an image is shown only when it is a file of this site found in the pages the answer was written from, given exactly as it stands there. Images of other websites are never kept; the model can write no HTML.
- **Appearance described in words** (0.7.3): on the Appearance page, describe the look ("calm dark blue", "make the buttons coral") and the AI service proposes the module's colours, shown at once in a live preview of the module; colours can also be chosen one by one. Only six-digit colours of known names are kept, and text that would be hard to read is made readable (WCAG contrast).
- **Agents** (0.8.0): helpers that take over the conversation to get one thing done, offered by plugins. The first, `plg_mcpask_rsform`, fills in an RSForm! Pro form in conversation (a page at a time, the AI putting the visitor's own words into the fields, suggested replies, a summary to confirm) and sends it as RSForm does. Agents are listed with the search box, offered below answers to questions close to their trigger phrases, or offered when the AI judges they fit. Since 0.9.0 an agent may end with an **analysis report**: a result type and scores, sections streamed into the conversation with pictures (from the AI image plugin's service or the component's own), the pages it cites, a private address of its own, and the link e-mailed to the visitor. Since 0.10.0, `plg_mcpask_livechat` passes visitors on to people in **LiveChat** (a chat within office hours when someone is there, otherwise a message kept as a ticket), with what they asked passed on and every handoff logged. See [docs/agents-spec.md](docs/agents-spec.md).
- **Embeds** (0.11.0): Ask the Site on other websites and products with one line of script (a frame of this site, allowed only on the websites listed), with the page the visitor is on, a sign-in through the account centre in a small window, or a token signed by the product's server. See [docs/embed-spec.md](docs/embed-spec.md).
- **Pets** (0.12.0): the floating button may be a pet whose nine moments (idle, greeting, picked up, thinking, talking, glad, puzzled, asleep, waking) follow the visitor and the answer; one comes with the module, others are uploaded on the Pets page (APNG or animated AVIF). See [docs/pet-spec.md](docs/pet-spec.md).
- **Answer quality** (0.6.0): visitors rate answers 👍 / 👎 with an optional reason; an Answer quality page lists the questions rated badly or not answered, and source rules point such questions (and others worded alike) to the pages that answer them, with an optional note.
- **Cost control**: limits per visitor (per minute, per day), per user group, and for the whole site, with Joomla permissions for who may ask and who is not limited (0.5.0), a maximum answer length, and no model call when nothing relevant is found.
- **Reviewed for security** before release: see the second part of [SECURITY-AUDIT.md](SECURITY-AUDIT.md) for what was found, fixed, and what remains.

Setup: install the package, open **Components → Ask the Site → Options** and enter an OpenAI API key, press **Synchronise the index**, publish the module, and add the scheduled task. The module's **Demo mode** shows the interface with a canned answer before any of this is done.

On MySQL and MariaDB a search has two passes: the database shortlists passages by comparing compact bit codes, and only the shortlist is compared exactly in PHP. On a real index of 5,300 passages this returned the same twenty passages as comparing everything in 157 of 160 cases, four times as fast. On other databases every embedding is compared in PHP. The stream needs a server that does not buffer PHP output (on nginx, `X-Accel-Buffering` is honoured by default).

## Guides (separate package)

`pkg_guides-x.y.z.zip` is a third package: step-by-step guides put together from sections of the site's own articles (or written by hand), reviewed by a Super User, and published as Joomla articles or FLEXIcontent items in the structure Ask the Site answers how-to questions from. Steps can be shared between guides; categories or tags are suggested from the sources; a back-end home module lists what waits for review.

> **Status: experimental (0.1.0).** Publishing as a Joomla article and as a FLEXIcontent item tested on a Joomla 4.4 site with FLEXIcontent 5.0.2 and Falang; the editor screens not yet used in a browser.

Setup: install the package, open **Components → Guides → Options** and choose the placement (and the guides category, or the FLEXIcontent type), then **New guide**. See [docs/guides-spec.md](docs/guides-spec.md).

## Development

```bash
build/build.sh                      # creates dist/pkg_mcp-, pkg_mcpask- and pkg_guides-<version>.zip
build/dev-sync.sh /path/to/joomla   # copy the source into an installed site
```

`demo/ask/index.html` shows the module outside Joomla with its canned answer; serve the repository root with any static web server.

## Roadmap

- **0.7.** Media tools, bulk operations with dry run, writing JReviews reviews, automated tests.
- **1.0.** Joomla Extensions Directory release.

## Security

This extension gives an AI assistant a live connection to your site, so it has been reviewed as
such. [SECURITY-AUDIT.md](SECURITY-AUDIT.md) records what was audited, what was wrong, what was
verified to be correct, what has *not* been checked, and how to harden an installation.

Please report vulnerabilities privately, see [SECURITY.md](SECURITY.md).

## License

GNU General Public License version 2 or later.

---

## 繁體中文

MCP Server for Joomla 讓 Claude、ChatGPT、Cursor、VS Code 等支援 [Model Context Protocol](https://modelcontextprotocol.io) 的 AI 助理連接你的 Joomla 網站。你可以查詢內容統計、搜尋文章、撰寫草稿、設定標籤與自訂欄位，也可以發佈文章，所有操作都以你自己 Joomla 帳號的權限進行。

**安裝**：在「系統 → 安裝 → 擴充套件」安裝 `pkg_mcp-x.y.z.zip`，接著開啟「元件 → MCP Server」確認健康檢查全部正常。

**連線**：

- **claude.ai、Claude Desktop、手機**：設定 → 連接器 → 新增自訂連接器，貼上儀表板顯示的端點網址，按連線後登入本站並按允許。
- **Claude Code**：執行 `claude mcp add --transport http joomla <端點網址>`，再用 `/mcp` 完成驗證。
- **不支援 OAuth 的客戶端**：到使用者帳號的「Joomla API Token」分頁啟用 Token，再從儀表板複製設定。

**管理**：「元件 → MCP Server → 已連線的應用」可以查看或撤銷連線；「活動紀錄」會記錄每次登入授權與工具呼叫。

**安全預設**：

- 新文章預設為未發佈。
- 移到垃圾桶預設關閉。
- 可一鍵開啟唯讀模式或限制可操作的分類。
- 每次工具呼叫都會記錄在活動紀錄中。

**主機問題**：如果伺服器會移除 Authorization 標頭，儀表板會自動改用 `X-Joomla-Token` 標頭。

### 官網內容問答（獨立套件）

`pkg_mcpask-x.y.z.zip` 是這個專案裡的第二個獨立套件：給訪客用的搜尋框，透過 OpenAI 依官網自己的內容回答問題，並標示每段回答來自哪些頁面。不需要安裝 MCP Server。

> **狀態：實驗性（0.12.0）。** 已在安裝了 FLEXIcontent 的 Joomla 4.4 與 6.1 站上搭配真正的 OpenAI 服務執行；在 Joomla 5.3 與 5.4 上可載入並讀取原生文章；Joomla 6 只對照原始碼核對過，尚未實際執行。尚未用於正式網站。資安審查結果見 [SECURITY-AUDIT.md](SECURITY-AUDIT.md) 後半部。

- **模組「MCP - 詢問官網內容」**：圖示、搜尋框，或固定在視窗右下角的浮動按鈕，送出後展開成全螢幕對話，回答逐字出現並附來源編號與來源卡片。點選來源會先收合對話，再以 View Transition 轉到該頁，換頁後可以繼續同一段對話。
- **元件「官網內容問答」**：把已發佈的內容切成段落並儲存向量（存在網站自己的資料庫），找出與問題最相近的段落，再串流輸出依這些段落寫成的回答。儀表板提供設定檢查、索引狀態、同步按鈕與訪客提問紀錄。
- **內容來源**：Joomla 文章；若安裝了 FLEXIcontent 則改讀 FLEXIcontent 項目，並包含文字類自訂欄位；安裝了 EasyBlog 時也會納入所有人可讀的部落格文章（0.4.0）。只會為未登入訪客可閱讀的內容建立索引，引用前還會再檢查一次。
- **後台模組「MCP - 訪客提問」**：最近的提問與提問者（帳號或訪客、網路位址），可放在後台首頁。
- **外掛**：「內容 - 官網內容問答」在存檔時更新索引，取消發佈或刪除的內容立即移除；「EasyBlog - 官網內容問答」對 EasyBlog 文章做同樣的事；「排程 - 官網內容問答」提供定期同步的排程工作，沒有修改過的內容會直接略過。
- **多站共用**（0.2.0）：可以讀取其他裝有本擴充、並互相持有匯出金鑰的網站的索引，回答時一併取材自它們的公開內容，來源會標示出自哪個網站。兩站的向量做法相同時直接沿用，不會產生 AI 服務費用；排程工作會定期更新。
- **外部網站（RSS／Sitemap）**（0.3.0）：在選項中列出其他網站的 RSS／Atom 摘要或 `sitemap.xml` 網址（0.5.0 起也可以只填首頁：自動尋找 Sitemap，找不到就沿連結爬取），就會讀取、索引並用它們的頁面回答，來源標示出自哪個網站。只讀取與清單同網域的頁面、遵守 robots.txt；摘要已含全文時不另外抓頁面；只有清單顯示頁面有更新時才重新讀取（沒有日期的頁面每週一次）。可填 CSS 選擇器指定內文位置，否則自動找出主要內容。會消耗 embedding Token，且內容會被當成本站內容一樣信任，請只加入您認識的網站。
- **探測內容不花費用**（0.2.2）：不是提問而是在測試弱點的文字（標籤、指令碼、資料庫語法、指令、單獨一個網址）會在送到 AI 服務之前就被擋下；一再嘗試的位址會被暫時封鎖一小時，也可以永久封鎖指定位址。
- **回答可帶截圖、表格與提示框**（0.7.0）：索引時把頁面轉成簡易 Markdown（保留清單編號、表格、粗體），本站頁面的圖片保留在原位置。回答可以在步驟後附上該步驟的截圖、用表格比較，或加上「提示」「注意」框；圖片只有在它是本站的檔案、而且出自這次回答引用的頁面時才會顯示，路徑必須一字不差。外部網站的圖片一律不保留，模型也無法輸出 HTML。
- **用一句話設定外觀**（0.7.3）：在「外觀」頁面描述想要的風格（「沉穩的深藍商務風」「按鈕改成珊瑚粉」），AI 服務會提出模組的配色，右側即時預覽；也可以逐一挑選顏色。只接受已知名稱的六位色碼，文字對比不足時自動調整到易讀（WCAG 對比）。
- **Agents**（0.8.0）：接手對話、完成一件事的小幫手，由外掛提供。第一個是 `plg_mcpask_rsform`：用對話填寫 RSForm! Pro 表單（一次一頁、AI 把訪客的話填進欄位、推薦回答、確認摘要後才送出），並依 RSForm 原本的流程送出。Agent 可以在搜尋框旁列出、在問題接近觸發問句時於回答下方推薦，或由 AI 判斷後推薦。0.9.0 起，Agent 完成後可以產生**分析報告**：結果類型與評分、逐段串流到對話中並配圖（沿用 AI 生圖外掛的服務或元件自己的設定）、引用的頁面、專屬私密網址，並把連結寄給訪客。0.10.0 起，`plg_mcpask_livechat` 可轉接 **LiveChat** 真人客服（服務時間內有人在線就對話，否則留言轉工單），並帶入訪客剛剛的問題、留下轉接紀錄。詳見 [docs/agents-spec.md](docs/agents-spec.md)。
- **嵌入**（0.11.0）：其他網站或產品貼一行程式碼即可使用官網內容問答（本站的 iframe，只允許設定的網站），會帶入訪客所在頁面；訪客可在小視窗用帳號中心登入，或由產品後端簽 token 帶入身分。詳見 [docs/embed-spec.md](docs/embed-spec.md)。
- **小寵物**（0.12.0）：浮動按鈕可以換成一隻小寵物，依訪客的操作與回答播放待機、打招呼、被拎起來、思考、回答中、開心、困惑、睡覺、醒來九種動作；內建一隻，也可以在「小寵物」頁上傳自己的（APNG 或動態 AVIF）。詳見 [docs/pet-spec.md](docs/pet-spec.md)。
- **回答品質**（0.6.0）：訪客可以對回答按 👍／👎 並選填原因；「回答品質」頁面列出被評為沒幫助或答不出來的問題，可以用「指定來源規則」把這類問題（以及意思相近的不同問法）導向應該參考的頁面，並附上補充說明。
- **費用控制**：可限制每位訪客每分鐘、每天，以及全站每天的提問數，也可依使用者群組設定不同限制，並用 Joomla 權限（ACL）設定誰可以提問、誰不受限制（0.5.0）；並限制回答長度；找不到相關內容時不會呼叫模型。

**設定步驟**：安裝套件 → 「元件 → 官網內容問答 → 選項」填入 OpenAI API 金鑰 → 按「同步索引」→ 發佈模組 → 新增排程工作。還沒設定前，可以開啟模組的「示範模式」先看介面效果。

### 操作教學（獨立套件）

`pkg_guides-x.y.z.zip` 是第三個套件：從網站既有文章挑出段落（或手寫）組成步驟教學，由超級管理員審核後，發佈成 Joomla 文章或 FLEXIcontent 項目，格式就是官網內容問答回答「怎麼做」時會依序引用的步驟結構。步驟可以在多份教學間共用；依來源建議分類或標籤；後台首頁模組列出待審的教學。

> **狀態：實驗性（0.1.0）。** 發佈成 Joomla 文章與 FLEXIcontent 項目已在裝有 FLEXIcontent 5.0.2 與 Falang 的 Joomla 4.4 站上測試；編輯畫面尚未在瀏覽器中實際操作。

**設定步驟**：安裝套件 → 「元件 → 操作教學 → 選項」選擇放置方式（以及操作教學分類或 FLEXIcontent 類型）→ 「新增教學」。詳見 [docs/guides-spec.md](docs/guides-spec.md)。
