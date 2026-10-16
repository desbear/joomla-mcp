# Security audit

This extension hands an AI assistant a live connection to a Joomla site, so it deserves to be
looked at properly rather than trusted because it is small. This page records what has been
reviewed, what was wrong, what was verified to be right, and what has *not* been checked. The
itemised list of fixes is in [CHANGELOG.md](CHANGELOG.md) under 0.7.0.

To report something new, see [SECURITY.md](SECURITY.md) — please do it privately.

## What was audited

| | |
|---|---|
| Audit date | 1 October 2026 |
| Version reviewed | 0.6.0 |
| Version with the fixes | 0.7.0 |
| Scope | All of `src/`: the component, the API and site controllers, the OAuth server, the back-end UI, the installer, and all eight tool plugins (~16 000 lines) |
| Method | Source review in four parallel passes, each finding then re-verified against the running code |

**This was an internal review, not a third-party penetration test.** It was carried out with the
help of AI agents, and every finding they reported was checked by hand before being accepted:
against Joomla's own source for the ACL and escaping questions, and by executing the code on real
Joomla 4.4 and 5.4 sites where that was possible. Several reported findings did not survive that
check and were dropped; one recommendation was rejected because it would have broken OAuth interop
for no security gain. Treat the result as a careful first pass, not as a clean bill of health.

The four passes covered:

1. The OAuth 2.1 authorization server and its consent flow.
2. SQL injection, argument validation, authorization and mass assignment across every tool.
3. The back-end UI (XSS, CSRF, access control), logging, the glue plugins and the installer.
4. Every point where the code touches the filesystem, the network, the shell, or PHP's dynamic
   features.

## The security model

Worth understanding before reading the findings, because most of the protection comes from here.

- **Nobody can use the server until an administrator says so.** The `mcp.use` permission has no
  default rule, and Joomla denies by default, so only Super Users can connect on a fresh install.
  It is checked on every request and on every OAuth authorization, so removing it takes effect
  immediately rather than at token expiry.
- **Tools run as the connected Joomla user**, with that user's permissions. There is no service
  account and no privilege elevation. Writes go through Joomla's own models and forms, so ACL
  field filtering, workflow, versions, text filtering and the action log all behave as they do in
  the back end.
- **A tool that is hidden cannot be called.** `read_only`, `allow_publish`, `allow_delete`,
  `disabled_tools`, the per-tool permission callbacks and the granted OAuth scopes are all applied
  when a call arrives, not only when the tool list is built.
- **OAuth grants are per connection and per scope.** The person approves read, write, publish and
  trash separately on a consent page on their own site; only read is pre-selected. Scope cannot be
  widened at any later step.
- **Only hashes are stored.** Authorization codes, access tokens, refresh tokens and client secrets
  exist in the database as SHA-256 only. A leaked backup or a SQL injection elsewhere in the site
  yields nothing that can be replayed.
- **New installations are read-only** until an administrator turns writing on. Updating an existing
  installation keeps whatever it already had.

## What was found

One critical, two high and twelve medium findings, all fixed in 0.7.0. The three that matter most:

**Authorization code theft through the redirect URI (critical).** `parse_url()` and the URL parser
in every browser disagree about a backslash: in `https://evil.example\@claude.ai/cb` PHP reports
the host as `claude.ai` and a browser resolves it to `evil.example`. A redirect URI could therefore
pass the allowed-host list and still send the authorization code elsewhere, with the consent page
displaying the wrong destination because it computed it the same way. PKCE did not help, because
the attacker generated the pair. Exploitable on a default installation against any account that
could use the server.

**The featured flag was set without a per-article permission check (high).** Joomla's
`ArticleModel::featured()` performs no access check of its own — core relies on the controller to
do it, and this extension replaces that controller.

**Unbounded work from a single request (high).** A JSON-RPC batch had no size limit and each entry
was a full tool call; array arguments had no limit; one tool reset PHP's execution clock on every
call.

The rest were information disclosure (unpublished content, moderation queues, other users' activity
logs, module credentials, database identifiers in error messages), per-object permission checks
defeated by a component-level fallback, a bypassable URL scheme blocklist, and several
denial-of-service paths. See [CHANGELOG.md](CHANGELOG.md).

Two functional bugs surfaced that the review had not predicted and that only running the code
revealed: **text search did not work at all on Joomla 4**, and `start_backup`'s interlock was
inverted so `force` bypassed the wrong thing.

## What was verified to be correct

Reported here because "we found nothing" is only meaningful if you know what was looked for. Each
of these was traced through the code, and the Joomla-behaviour claims were checked against
Joomla's own source on both 4.4 and 5.4:

- **No SQL injection.** Every place a tool argument reaches SQL is cast, bound, quoted, or mapped
  through a `match` with a default. Dynamic column names (custom fields) are resolved against the
  database first and validated against `^[A-Za-z0-9_]+$`. `quoteName()` escapes backticks on both
  supported Joomla versions.
- **No mass assignment.** Every write handler names the fields it accepts. `created_by`, `access`,
  `rules`, `asset_id` and `checked_out` cannot be set through a tool.
- **No XSS in the back-end UI.** Including the path that looked most likely: an OAuth client name
  chosen by an anonymous registrant and later rendered on the Connections page. It is escaped at
  render, and so is the one core helper it passes through.
- **CSRF protection on every state-changing back-end task**, POST only, token checked before the
  permission check.
- **No shell execution, `eval`, variable functions, object injection, file upload or arbitrary
  file write anywhere.** `unserialize()` is called once, with `allowed_classes => false`.
- **The Akeeba backup log path is not traversable.** The identifier is cast and bound, every path
  component comes from the database, and the result is now also confined with `realpath()`.
- **OAuth:** PKCE S256 is mandatory with no downgrade to `plain`; `hash_equals` on every secret
  comparison; refresh token rotation with family-wide revocation on replay; no scope escalation at
  any of the four points scope is computed; revocation checks token ownership. Subdomain confusion
  is not present — `evil-claude.ai`, `claude.ai.evil.com` and `claude.ai.` are all rejected.
- **The consent page** sets `no-store`, `X-Frame-Options: DENY`, `frame-ancestors 'none'` and
  `Referrer-Policy: no-referrer`, and requires a logged-in session with `mcp.use`.

## What has not been checked

Please do not read this page as more than it is.

- **No third-party penetration test**, and no fuzzing of the JSON-RPC surface.
- **The EasyBlog and JReviews plugins were not exercised against a running install** — neither
  extension was available to test on. Their code was reviewed and lints clean, but the regression
  pass that covered the other six tool plugins did not cover these two.
- **PostgreSQL is written for but untested.** The schema and queries exist; nothing has been run
  against it.
- **Joomla 6 is untested.**
- **Third-party extensions are out of scope.** This extension calls other extensions' models
  (FLEXIcontent, EasyBlog, JReviews, Akeeba Backup); their own security is their own.
- **The AI side is out of scope.** Nothing here can stop a prompt-injected assistant from using
  the permissions it was legitimately granted. That is what the permission model, read-only mode,
  the category limit and the activity log are for — see below.

## Hardening for administrators

The defaults are chosen to be safe, but a site that matters deserves more than the defaults.

1. **Grant "Use MCP Server" to as few groups as possible.** This is the single most important
   control. A connected assistant can do whatever that user can do.
2. **Connect a dedicated Joomla user, not your own Super User account.** Give it exactly the
   permissions the task needs. The tools inherit Joomla's ACL, so an account restricted to one
   category is genuinely restricted.
3. **Leave read-only mode on** unless the assistant needs to write, and leave trashing off.
4. **Use "Limit to categories"** when the assistant only needs part of the site. Note what it
   cannot cover: it restricts Joomla categories, so it does not restrict menus, modules, or
   extensions that keep their own categories (EasyBlog, for example). `site_info` says so too.
5. **Set the Site URL** in Global Configuration → Server. It pins the URLs this extension
   generates instead of deriving them from the `Host` header.
6. **Prefer OAuth to API tokens.** An API token is a bearer secret with no scopes and no expiry;
   an OAuth grant is per-connection, per-scope, expiring and revocable from
   Components → MCP Server → Connections. The installer deliberately does not enable Joomla's core
   API token plugins, because they switch bearer-token authentication on for the whole Joomla API.
7. **Turn off automatic client registration** once your assistants are connected. It has to be
   open to add a new one, and it is rate limited, but it does not need to stay open.
8. **Review the activity log**, and keep "log arguments" off unless you are debugging — it copies
   article text and search terms into the database in cleartext for the retention period. Failed
   credentials, client registrations and refresh-token replay are always recorded, whatever that
   setting says.
9. **Watch for `auth:failed` and `oauth:token_replayed`** in the log. The second one means a
   rotated refresh token was presented again, which usually means it was stolen; the whole grant is
   revoked automatically when it happens.

---
