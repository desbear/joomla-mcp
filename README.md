# MCP Server for Joomla

Connect AI assistants — Claude, ChatGPT, Cursor, VS Code and any other [Model Context Protocol](https://modelcontextprotocol.io) client — to your Joomla site. Ask for content statistics, search articles, write drafts, set tags and custom fields, and publish, all with the permissions of your own Joomla account.

> **Status: beta (0.10.0).** Tested on a live Joomla 5.4 site with claude.ai. Please read [Tested with](#tested-with) and report problems in the issue tracker.

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

1. Download `pkg_mcp-x.y.z.zip` from the [latest release](https://github.com/desbear/joomla-mcp/releases/latest) (under **Assets**) and install it in **System → Install → Extensions**. Do not use GitHub's "Source code" zip: that is the repository, not an installable package.
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

**AI Ask for Joomla**: when [AI Ask for Joomla](https://github.com/desbear/joomla-ai-ask) is installed, it adds tools of its own (the questions visitors asked, how answers were rated, source rules, agents and handoffs).

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

## Related

[AI Ask for Joomla](https://github.com/desbear/joomla-ai-ask) answers visitors' questions from the site's own content with AI, with agents, embeds on other websites and an animated floating button. It is a separate project and does not need this server; when both are installed, AI assistants connected here can also review the questions visitors asked.

## Development

```bash
build/build.sh                      # creates dist/pkg_mcp-<version>.zip
build/dev-sync.sh /path/to/joomla   # copy the source into an installed site
```

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

**下載**：到 [最新版本頁](https://github.com/desbear/joomla-mcp/releases/latest)，在 **Assets** 下載 `pkg_mcp-x.y.z.zip`（不要下載「Source code」，那是原始碼，不能直接安裝）。

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

**相關專案**：[AI Ask for Joomla](https://github.com/desbear/joomla-ai-ask) 用 AI 依網站自己的內容回答訪客的問題，是另一個獨立專案，不需要本伺服器；兩者都安裝時，連到本伺服器的 AI 助理也能查看訪客的提問紀錄。
