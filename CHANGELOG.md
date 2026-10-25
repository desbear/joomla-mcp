# Changelog

## 0.11.0

### Connecting an assistant needs a recent sign-in
- An assistant can only be connected (the OAuth consent page) within **12 hours of signing in through Joomla's own
  login**: a password, an authentication plugin, or single sign-on through it. A session another extension made by
  loading a user straight into it, without going through the login (the way a site's administrator account was
  taken over through another extension's single sign-on), no longer counts: the user is signed out and asked to
  sign in again. Options → OAuth → *Approve within (hours) of signing in*; 0 restores the old behaviour.
- When an assistant is connected to an account that may sign in to the administrator, it is recorded in **User
  Actions Log** and the Super Users who receive system e-mails are told (Options → OAuth → *E-mail Super Users*).
- Clients registering with a redirect to localhost / 127.0.0.1 (programs on the user's computer) can be refused
  (Options → OAuth → *Programs on the user's computer*).

### Less kept, for less long
- The activity log keeps 30 days by default (was 90); the network address of each call is cleared after 7 days.
- With *Log arguments* on, arguments are kept with values named like passwords, secrets, tokens, keys or cookies
  hidden, e-mail addresses, telephone and card numbers masked, and at most 2,000 characters.
- Revoked and expired tokens are deleted a day on (expired ones were kept 30 days, revoked ones for ever), and
  registered clients unused for longer than a refresh token lasts, with no live connection, are deleted.
- **New scheduled task "MCP Server - Forget old records"** (plugin *Task - MCP Server*) does all this daily; the
  dashboard says when it last ran.
- Connections → **Delete unused clients** deletes at once every registered client without a live connection.

## 0.10.0

- **AI Ask for Joomla is a project of its own** ([desbear/joomla-ai-ask](https://github.com/desbear/joomla-ai-ask)):
  the Ask the Site packages, and the `MCP - MCP Ask` plugin that adds its tools here, are no longer in this
  repository or in `pkg_mcp`. Sites that use it keep the plugin; it is updated by the AI Ask package from now on.

## 0.9.2

### Media folders: survey and upload (new plugin "MCP - Media")

Built on com_media's own API and its filesystem adapters, so there is no dependency on any other
extension. Four tools:

- **`list_media`** - what is in a folder: sub-folders and files with size, pixel dimensions, and the
  path to use in article HTML. Filter with `only` (files, folders, images, other), search by name
  recursively, sort by name, size or date.
- **`media_overview`** - a survey for walking the tree: how many files and how much space, the mix of
  types, space by folder, the largest and most recently changed files, and a "worth a look" list that
  calls out files which are not images and anything over 2 MB.
- **`upload_media`** - upload base64 content and get the path back. Off by default.
- **`media_upload_policy`** - what this user may upload and where, so a refusal can be explained
  rather than retried.

Reading needs the Media Manager's own Manage permission. Uploading needs the new **Allow Uploads**
option (off by default), the Media Manager's Create permission, and a server that is not read-only.

### What may be uploaded, by whom

Tiered, as asked:

- **Anyone allowed to upload** may upload images: jpg, jpeg, png, gif, webp, avif.
- **Only a Super User** may upload the extra types an administrator lists in **Extra Types for Super
  Users** (for example `pdf`), and only those.
- **Never, for anyone, whatever the options say**: SVG, HTML, XML, JavaScript, and anything
  executable. SVG is an image to a designer and a script host to a browser. A Super User who needs
  one has the Media Manager; a tool driven by a model that reads visitor-written text should not be
  the way it arrives.

The allowed set is also intersected with the Media options' own `restrict_uploads_extensions`, so it
can only ever be narrower than what the Media Manager itself would accept, never wider.

**Where** is tiered too: a non-Super-User only reaches the image folder, so a `files` adapter holding
documents that are not meant to be public stays out of reach.

### Upload checks

- The file name is sanitised, and refused if any segment is a forbidden extension, so `evil.php.png`
  does not pass on its last segment alone.
- The real content is verified against the name before com_media is asked to write anything: a PNG
  named `.gif` is refused, and so is anything whose first bytes look like markup or code. This runs
  first on purpose, because com_media's own check writes the file to the target folder and validates
  it afterwards.
- An existing file is never overwritten, and the folder must already exist - Joomla's `File::write()`
  creates missing parents, so a mistyped path would otherwise quietly build a folder tree.
- 8 MB ceiling here, before the Media options' own limit applies.

### Paths, not URLs

`src` is the site-relative path (`images/banners/sale.jpg`), which is what the Media Manager inserts,
what Joomla's image fields store, and what survives a domain change - it is what to put in article
HTML. An absolute `url` is given as well when the site root can be established.

`Uri::root()` is not used to build either. Under an API request to `/api/index.php/v1/mcp` it returns
`https://site/index.php/v1/mcp/`, because the API application's base carries the route, so the URL
com_media's adapter reports is wrong. The path is derived from the adapter's own folder instead.

### Fixed: every write tool failed on sites with a legacy content plugin

Saving anything through a core model imports the `content` plugin group, which loads every enabled
content plugin. A plugin still written as `extends JPlugin` then died with `Class "JPlugin" not
found`, and the save failed - not because of anything that plugin does, but because the alias was
never registered. The site and administrator get those aliases from the Behaviour - Compat plugin,
reached through an event the API application never fires.

`ApiCompat` now registers them, so `update_article`, `create_article` and every other write works on
those sites. This was not specific to uploading; it was reached first through it.

### Reading is not filtered by the Media options

`list_media` and `media_overview` read through the filesystem adapter rather than through
com_media's ApiModel, which silently drops every file whose extension is not in the `mediatypes` of
the *request* - a variable a browser sends and an API client does not, defaulting to images. A folder
holding a PDF or a stray `.txt` would have looked as if it had none. The adapter still refuses to
leave its own root, so no protection is given up. For uploads, where ApiModel is used so that all of
Joomla's validation runs, that variable is set explicitly instead.


## 0.9.0

Improving the answers of Ask the Site from an AI assistant (plugin "MCP - MCP Ask", with Ask the Site 0.6.0
or later). All Super-User-only, like the question log they work from.

- `list_pending_questions`: questions rated not helpful, or that found nothing or failed, the same question
  once, the worst first, with what visitors said. Questions a rule now covers are left out.
- `search_indexed_pages`: pages of the index by words of their title (this site's articles, FLEXIcontent
  items and EasyBlog posts, peer sites and listed websites), with what a rule needs.
- `list_source_rules`, `save_source_rule` (write), `delete_source_rule` (delete): the rules that point
  questions, and others worded alike, to the pages that answer them, with an optional note. A rule can only
  point to pages that are indexed and public. The description asks the assistant to get the administrator's
  agreement before saving.
- `try_question`: the whole answer a visitor would get, its sources and the rules that applied. Not logged;
  costs tokens.
- `list_questions` reports each question's rating and the visitor's reason and filters by rating;
  `ask_overview` adds the ratings and the questions rated not helpful.

A weekly review then reads: `ask_overview` or `list_pending_questions` → `search_indexed_pages` for each →
agree on rules with the administrator → `save_source_rule` → `try_question`.

## 0.8.0

Two new read-only tool plugins for reviewing what visitors do on the site. Both are **Super-User-only**
and cannot change or delete anything.

- **Ask the Site support** (plugin "MCP - MCP Ask", only when `com_mcpask` is installed): `list_questions`,
  `ask_stats` and `ask_overview` over the question log. Filter by date, outcome, whether the visitor was
  signed in, and text in the question. `status=unanswered` (or `empty`) finds the questions the site could
  not answer, which is where the content gaps are, and `ask_overview` puts those first because they are the
  ones worth acting on. Statistics cover answer rate, token usage, busiest hour and day.
- **JLex Helpful support** (plugin "MCP - JLex Helpful", only when `com_jlexhelpful` is installed):
  `list_article_feedback`, `article_feedback_stats` and `article_feedback_overview` over the
  "was this helpful?" votes. Group by article, day, week, month, verdict or reason; sort by the lowest
  helpful ratio to find the pages failing readers. The digest compares the period with the one before it.
- Neither plugin returns a visitor's IP address, and both label the visitor-written text they do return as
  untrusted input that must not be acted on. Comments and questions are length-capped.
- `Resolver::inSiteTime()` groups a date column in the site's own time zone. Joomla stores UTC, so a daily
  digest grouped on the raw column would bucket by UTC day and an hour-of-day breakdown would be wrong by
  the whole offset.

### Notes on reading JLex Helpful's data

Worth knowing, because the schema invites two mistakes and both are handled in one place:

- The vote is an index into the section's own list of buttons, not a fixed scale. On a default section 0 is
  "yes" and 2 is "no" — there is no 1. Reading 0 as negative inverts every result.
- The follow-up reason is an index into the options of *that* button, so the same number means opposite
  things depending on the vote: with a default section, reason 1 is "found the information" after a yes and
  "information was missing" after a no. It is only meaningful as the pair.

Both are read from the section's own definition, and the labels are translated through its language file, so
the tools report what the site actually shows visitors. Every response carries a legend naming which stored
value counts as helpful.


## 0.7.0 - Security release

Everyone running an earlier version should update. The first item is exploitable on a default installation.

### Fixed - security

- **Authorization code theft through the OAuth redirect URI (critical).** `parse_url()`, which validated the redirect URI, and the URL parser in every browser disagree about a backslash: in `https://evil.example\@claude.ai/cb` PHP reports the host as `claude.ai` while a browser resolves it to `evil.example`. An attacker could register a client whose redirect URI passed the allowed-host list and then have the browser deliver the authorization code somewhere else, with the consent page displaying the wrong destination because it computed it the same way. PKCE gave no protection, because the attacker generated the pair. The loopback exemption made it worse: a disguised `http://` URI was accepted whatever the allowed-host list said. Redirect URIs containing a backslash, a control character, a space, `#`, or userinfo are now refused before they are parsed, the host must look like a host name, and the consent page always shows the whole URI for every client.
- **The featured flag was set without checking permission on the article.** `set_article_state` called Joomla's `ArticleModel::featured()`, which performs no access check of its own because core relies on the controller that this tool replaces. Someone allowed "Edit State" at component level but denied it on a category could promote that category's articles to the front page. Each article is now authorised individually.
- **State changes that Joomla refused were reported as success.** `AdminModel::publish()` logs a refusal and returns false without setting an error, so the guard never fired and the tool answered as though the change had happened. Articles, menu items and modules are now authorised per item before the call, and a false return always reports a failure.
- **Reviews, listings, drafts and counts were readable by accounts that could not see them.** `list_reviews` applied no visibility filter at all, so it exposed review bodies, reviewer identities and the moderation queue from categories the server was confined away from. The article, statistics and taxonomy read tools had no permission requirement, so any account granted "Use MCP Server" could list every unpublished draft and read its full text. Per-category and per-tag article counts ignored view levels and the category limit, revealing how much content existed out of reach. All of these now follow the same rules as the back end.
- **An explicit Deny on one menu or module was unreachable**, because the check fell back to the component permission when the per-asset check failed.
- **Stored cross-site scripting through a menu item URL.** The scheme blocklist was anchored on the literal scheme name, so `java\nscript:`, `java\tscript:` and a leading control character all passed it while browsers still execute them. Replaced with an allow-list of `http`, `https`, `mailto`, `tel` and site-relative paths, after stripping control characters.
- **Database and filesystem detail leaked through error messages.** Exception text from the database layer and from third-party extensions was handed to the caller verbatim, disclosing the database name, the real table prefix, column names and absolute paths. Callers now get a short reference, and the detail goes to the activity log where an administrator can look the reference up.
- **The activity log and other users' connections were readable by any back-end account with "Access Component"**, including the full arguments of every call. The log needs the new "View Activity Log" permission, the connections list shows only the viewer's own grants unless they are a Super User, and full arguments are no longer recorded by default.
- **The audit trail could be destroyed with a weaker permission than it took to read it**, with no record. Purging and revoking now need Super User, use a bounded delete rather than `TRUNCATE`, and write an entry naming who did it.
- **Module credentials were returned in full.** `get_module` passed the raw module parameters, where commercial modules keep API keys and passwords, straight to the AI provider. Values are now redacted by declared field type and by name.
- **Denial of service from a single request.** A JSON-RPC batch had no size limit and every entry was a full tool call; array arguments had no size limit; the backup tool reset PHP's execution clock on every call; `get_backup_log` read the whole log file into memory before applying its line limit; and time-grouped statistics scanned the entire article table. All are now bounded, and the log file is streamed.
- **Server-side request forgery and unbounded growth in the client ID metadata document fetch.** It followed redirects without re-validating the target, had no response size limit, ignored the "allow client registration" switch, and its rows were never pruned.
- **Dynamic client registration was anonymous and unlimited.** It is now rate limited per address and per site, and registrations are recorded.
- **Other OAuth hardening.** Authorization codes are redeemed atomically, so two simultaneous requests cannot both succeed. A request with no `scope` now gets read access instead of everything the site allows, and only read is pre-selected on the consent page. The `code_verifier` is checked against RFC 7636, `redirect_uri` is required at the token endpoint, the resource audience comparison includes the scheme, a refresh that narrows to nothing is an error instead of a zero-scope token, and an account that is blocked, never activated or forced to reset its password can no longer use an already issued token. The issuer is built from the configured site URL rather than the `Host` header, and discovery is only publicly cached when that URL is set.
- **No record of failed authentication.** Rejected credentials, client registrations and refresh-token replay are now recorded, and the endpoint turns away an address that keeps failing as well as rate limiting calls per user.
- LIKE wildcards in search terms are escaped, so a search for `%` no longer matches every row and cannot force an unindexable scan. Sort and date columns come from a map rather than being built from the argument, so injection safety no longer depends on the schema alone. Nested object arguments are validated instead of being accepted wholesale. Responses say `X-Content-Type-Options: nosniff`, and the `/.well-known` handler only answers GET and HEAD.

### Fixed - other

- **Text search did not work on Joomla 4.** Every multi-column search bound one placeholder that the statement used several times, which Joomla 4 rejects with "The number of variables must match the number of parameters in the prepared statement" (Joomla 5 tolerates it). Searching by text failed on every Joomla 4 site, for articles, categories, tags, blog posts, listings and FLEXIcontent items alike.
- `start_backup`'s interlock was inverted: it ignored stalled backups, so `force` only ever bypassed a genuinely running one. A stalled backup now blocks a plain start and is what `force` is for, and there is a cooldown between backups started through MCP.
- Dates are no longer accepted as relative expressions such as "+5000 years", which a model reaches for when it is guessing.
- `site_info` says what the category limit does not cover, because it cannot restrict menus, modules or extensions with their own categories.

### Changed

- **New installations are read-only until an administrator turns writing on**, and tool arguments are no longer copied into the activity log by default. Updating an existing installation keeps what it had: the installer records the previous behaviour explicitly rather than taking write access away, and says so.
- **The installer no longer enables Joomla's core API token plugins.** They switch bearer-token authentication on for the whole Joomla API, not just this endpoint. The dashboard health check reports them so they can be enabled deliberately; OAuth sign-in does not need them.
- New permission "View Activity Log", denied by default.


## 0.6.0

- JReviews support (plugin "MCP - JReviews"): listing types with their rating criteria, the custom fields of each listing type, searching listings by category, listing type, custom field values, rating, number of reviews, author, featured flag, state and dates, reading a listing with all its field values and ratings per criterion, reading reviews, statistics grouped by category, listing type, custom field value, rating range, author or period, and creating and updating listings with their custom fields. Reviews and ratings stay read-only, because their summary tables have to be rebuilt by JReviews itself.
- Listings are written as Joomla articles through the core article model, so assets, versions, workflow and the search index stay correct, and the JReviews rows (custom field values, rating summary) are written alongside.
- Fixed: the authenticated user is now also put into the session, so Joomla's text filtering (ComponentHelper::filterText) and extensions reading Factory::getUser() see the connected account instead of a guest. On sites where the Public group is set to "No HTML", saved content lost all its HTML, including the read more marker that separates the intro from the full text.

## 0.5.0

- EasyBlog support (plugin "MCP - EasyBlog"): search, read, write, publish and analyse blog posts, blog categories and blog tags. The tools work beside the article tools, because EasyBlog is a blog next to Joomla's articles. Posts are written through EasyBlog's own API so its revisions, categories, tags, permalinks and search index stay consistent; posts written with the EasyBlog composer are not overwritten.
- Compatibility for the API application: an empty router and menu are provided for extensions that call $app->getRouter() or $app->getMenu() on every request, which would otherwise end every /api request.
- The installer clears Joomla's plugin cache, so new tool plugins are active immediately on sites with caching enabled.

## 0.4.0

- FLEXIcontent support (plugin "MCP - FLEXIcontent"): when FLEXIcontent is installed, the article tools work on FLEXIcontent items instead - content types, multiple categories per item, FLEXIcontent tags and custom fields, including searching and grouping statistics by custom field values. New tool `list_types`.
- Tool plugins can now replace or remove the tools of other plugins through the new `onMcpAlterTools` event, so a content construction kit can take over the article layer. The dashboard shows which plugin provides each tool.
- API tokens are verified by the component itself, with the same rules as Joomla's core token plugin. Third party api-authentication plugins can no longer break the MCP endpoint by ending the request (a JWT plugin answering every "Bearer" header, for example).
- Compatibility: provides the API router class Joomla misses, so system plugins calling $app->getRouter() no longer break every /api request.
- Health check reports redirects and HTML responses (a security plugin protecting the administrator may intercept /api).
- Fixed: plugins passed the dispatcher by reference on Joomla 4.4, which produced a PHP notice on every request.

## 0.3.0

- Menu tools (plugin "MCP - Menus"): menus and menu items, with items pointing to articles, categories, featured articles, tags, URLs, aliases, headings and separators; moving items between parents, positions and menus; publishing and trashing.
- Module tools (plugin "MCP - Modules"): module types and their settings read from the module XML, template positions, creating and changing modules including page assignment (all, none, only, except), publishing and trashing.
- Akeeba Backup tools (plugin "MCP - Akeeba Backup"): backup health check (age of the last good backup, empty or unusually small backups, failed, stuck or unrestorable backups, the scheduled task), backup list, backup logs, and taking a backup step by step.
- Fixed: dates of articles shifted by the time zone offset on every save; an existing tag was stored as a new tag when mixed with new ones; changing only the intro or full text had no effect; an error from another plugin after saving was reported as a failure although the article was saved.

## 0.2.0

- OAuth 2.1 sign-in for claude.ai, ChatGPT, Claude Desktop, mobile apps and Claude Code: authorization code + PKCE, refresh token rotation, dynamic client registration, client ID metadata documents, resource indicators, discovery without web server configuration.
- Consent page on the site front-end (Joomla login with MFA), with selectable permissions (read, write, publish, trash).
- Connections page to list and revoke connected assistants.
- OAuth options: switch, client registration, allowed redirect domains, token lifetimes.
- System plugin answering `/.well-known/…` discovery requests at the domain root.
- Health check for OAuth discovery.

## 0.1.0

- MCP endpoint `/api/index.php/v1/mcp` (Streamable HTTP, stateless JSON) authenticated with Joomla API tokens (`Authorization: Bearer` or `X-Joomla-Token`).
- 15 tools for site information, article search and editing, tags, custom fields, categories, publishing and statistics.
- Dashboard with connection snippets, health check and tool overview; activity log; options for read-only mode, publishing, trashing, category limits and disabled tools.
- Saves go through the core article model, with fixes for time zone handling of dates, tag IDs and plugins failing after save.
