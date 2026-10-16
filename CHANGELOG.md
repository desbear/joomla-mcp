# Changelog

## Ask the Site 0.13.2

- The component's menu (the left of the back end, and Components → Ask the Site) lists Agents, Handoffs,
  Appearance, Pets and Embeds besides the dashboard and answer quality.

## Ask the Site 0.13.1

- **The pet stays as it is when pressed, and the search box opens beside it** (to its left; to its right when it
  was moved to the left side), instead of the pet shrinking onto the box. Embedded, the frame makes room for both.
- The module's "Pet" setting is always shown (it applies to the floating button only, as its description says).
- Pages beside an answer that sit side by side (narrower windows) fill their rows: with two columns, 3 becomes 4,
  so no page is left alone on the last row.
- Fix: "0 more" was shown when every page was already listed; the line is hidden then.
- Fix: the Appearance page's preview said "N more" in English.

## Ask the Site 0.13.0

- **Pets have two pictures for each moment**: the whole pet, and a square thumbnail (a close-up). The whole pet is
  the floating button and, while the conversation is open on a wide window (1360px and more), stands at its right;
  on narrower windows a rounded-square thumbnail takes its place in the head. The module's pet comes with its
  thumbnails (head and scarf, about 0.75 MB). The pet page uploads, shows and removes both for each moment, and a
  still thumbnail beside the still picture.
- **The site's pet answers in the conversation**: with a pet as the floating button, each answer shows its thumbnail
  instead of the spark, half as large again (48px, a rounded square): thinking while the answer is awaited, talking
  while it is written, glad when it is done from the site's pages (puzzled when nothing was found or it failed),
  then still. Agents' faces are thumbnails too, of the same size.
- **Agents' faces follow what they do**: thinking while their turn is awaited; talking for a moment when they reply;
  glad when done (a form sent), puzzled on an error; a report: talking while written, glad when ready, puzzled if it
  fails; a person's window: thinking while it opens, glad when someone is there, puzzled when it does not open. The
  floating button's pet follows agents too.

## Ask the Site 0.12.6

- **Pets: a square thumbnail** (optional; PNG, WebP, JPG, GIF or AVIF): shown in the pet library and as the small
  face of agents (their buttons, beside the name above the conversation); the still picture when there is none.
  The module's pet uses its still picture.
- Fix: the pet library's buttons no longer overlap; each pet's actions ("Copy it to change", Edit, Select) are in
  its card, and "Delete the pets ticked" is below the list, asking for a selection first.
- Fix: links escaped twice (`&amp;amp;`) on the pet library and the dashboard's filters.

## Ask the Site 0.12.5

- How many pages are listed beside an answer before the rest fold into "N more" is a setting: "Pages shown" of the
  module, and of each embed key (3 by default; 0 lists them all).

## Ask the Site 0.12.4

- **Three pages beside an answer**, the rest in one pill under them: a mark for the first few of the rest (the
  first character of each title) and how many there are ("12 more"); pressed, the whole list opens and stays in
  view, and "Show less" folds it again. The list no longer shortens itself to the answer's height.

## Ask the Site 0.12.3

- **A helper offered below an answer comes right under its text**, not under the list of pages beside it (an
  answer's text, rating and offer are one column now; on phones they come before the pages).
- **Agents have a face** (an agent's "Avatar", on its Conversation tab): a pet of the library (it plays its Thinking
  moment while the agent works; animated AVIF or APNG) or one picture (an APNG moves too). It shows on the offer,
  on the helpers listed ("You can also try") and the "Talk to a person" button, beside its name above the
  conversation, and in place of the spark when the agent answers.
- **The offer and what comes of it are one block**: asking for a person from an offer shows, in the same block,
  whether someone is there and the button to open the chat window again, instead of a second block below.
- **While an answer is written or an agent is busy, agents wait visibly**: their buttons turn grey and cannot be
  pressed.
- **Thinking**: the spark turns twice, stops, flashes, and turns again, until the answer starts (still for visitors
  who ask for less motion).

## Ask the Site 0.12.2

- **LiveChat agent: the whole conversation is passed on** (new choice "The whole conversation before", on by default
  for new agents): the visitor's questions and the answers before the handoff, newest kept (20 turns, 8,000
  characters at most), shown beside the chat or ticket in parts the chat window shows whole ("Conversation 1/2"…).
  The last thing said may only be "a person, please". Agents set up before keep what they had chosen; tick it on
  their LiveChat tab.
- The Agent, Embed and Pet pages are saved from Joomla's toolbar at the top (Save, Save & Close, Close), as other
  edit pages are; the buttons below the form are gone.

## Ask the Site 0.12.1

- Fix: the "Save & Close" button of the Agent, Embed and Pet pages showed its language key (JSAVE_AND_CLOSE) instead of
  its words; it now uses Joomla's own JTOOLBAR_SAVE.

## Ask the Site 0.12.0

The floating button as a pet (docs/pet-spec.md).

- **Pets page** (Ask the Site → Pets, for those who may change the options): the pet library. One pet comes with
  the module (nine moments, animated AVIF, about 1.4 MB in all), ready to use or to copy and change. A pet has a
  name, sizes for desktops and phones, a word for the first visit, how soon it sleeps, a picture for each moment
  (uploaded on the page: APNG, animated AVIF, WebP or GIF, checked by their first bytes, kept in
  `images/mcpask/pets/`, so the media manager's own file types do not matter), how long the moments that play once
  last, and a still picture. Sizes are shown, larger than advised in red; "Try it" plays each moment in turn.
- **Chosen** by the module (floating button: "Button look") or by an embed key: the icon, the module's pet, or one of
  the library.
- **The moments**: idle; greeting when the pointer or the keyboard comes to it; picked up while dragged; thinking
  while an answer is awaited; talking while it comes in; glad when it is done or rated helpful; puzzled when nothing
  was found, something failed or it is rated unhelpful; asleep after a while with nothing done, waking at the first
  sign of the visitor. Moments without a picture use idle.
- **Shown**: closed, the pet is the button (no circle, no icon); open, it perches on the end of the search box; in
  the conversation, it takes the place of the dot in the head and does as the button's does. Two layers cross-fade
  between moments; what plays once is played from a copy kept in memory, so it starts from its first frame every
  time without being fetched again. Only idle is loaded at first, the rest when the page has time. Visitors who ask
  for less motion see the still picture, or the icon when there is none. A word beside it on the first visit, until
  closed or the pet is pressed. Embedded, the frame of the button grows to the pet's size.

## Ask the Site 0.11.0

Ask the Site on other websites and products (docs/embed-spec.md): one line of script in their pages.

- **Embeds page** (Ask the Site → Embeds, for those who may change the options): embed keys, each with the
  websites allowed (`https://…`, `https://*.example.com`; `http://` only for local addresses), the button's side,
  name and placeholder, the site's colours or one main colour, questions a day for the key, whether agents are
  offered, how visitors sign in, and questions asked today and in 30 days. The page of a key gives the line to
  copy, the public key, and the secret for the product's server (shown on request, renewable).
- **The line**: `<script src="…/media/mod_mcp_ask/js/embed.js" data-key="pk_…" async></script>` puts a frame of
  this site in a corner: the size of the button, of the open search box, or of the whole window for the
  conversation. The frame is served with `frame-ancestors` listing only the websites allowed, so browsers refuse it
  anywhere else; it shows nothing to a website not listed. What is asked and answered stays in the frame.
- **Where the visitor is**: the page's address and title go with each question, and its own scripts may add a few
  values (`McpAsk.setContext({ product: 'Vital CRM', section: '…' })`). When the address is a page read from a peer
  site or website (a support site, say), the answer starts from that page, as the module does on this site.
- **Who the visitor is**, never taken from the other website's page unsigned:
  - a **sign-in button** above the conversation opens a small window of this site, which goes to the account
    centre (an External Login server, such as CAS) or this site's login page, comes back signed in, hands the frame
    a token for this key (8 hours), and closes; for websites without a sign-in of their own;
  - **tokens signed by the product's server** with the key's secret (JWT HS256: `aud` the public key, `email`,
    `name`, `exp` within an hour), given as `data-user` or `McpAsk.setUser()`; the e-mail must be an account here.
    Off by default, until the format is matched to BizForm's own.
  A known visitor is the request's user for that request only (questions logged to the member, the member's limits
  and access, names and e-mail addresses in forms, live chat and reports); nothing is kept in a session.
- **Links**: a source or report opened from the conversation changes the other website's page when it is one of
  its own, and opens a new tab otherwise.
- **Live chat**: asking for a person opens LiveChat on the other website's page (loaded there, or the one it has
  already), not in the frame; what happens there is logged as on this site. The page a handoff came from may be a
  page of the website the key is embedded in.
- `McpAsk.open()`, `McpAsk.close()` for the other website's scripts; the frame follows single-page applications'
  changes of address.
- The question log records the embed key each question came through.
- Fix: a link within the page in an answer or report (`#mcp-ask-handoff`) no longer reloads the page.

## Ask the Site 0.10.0 / MCP Server 0.9.3

Fourth stage of agents (docs/agents-spec.md): passing visitors on to people.

- **LiveChat agent** (`plg_mcpask_livechat`, new): passes the visitor on to people in LiveChat. LiveChat's window
  is loaded only when a visitor asks (it sets cookies of its own); a page that has LiveChat already (a template's
  own code, old or current) keeps it and is driven through the API it has, never loaded twice. The people answering
  see, beside the chat or ticket, what is chosen of: the visitor's last question, the AI's answer (shortened), the
  page, the analysis report's link; signed-in visitors' names and e-mail addresses are passed on too. Within office
  hours with someone online the visitor chats; otherwise they leave a message in the same window, which LiveChat
  keeps as a ticket. The conversation of the site closes as LiveChat's window opens; the card left in it opens the
  window again.
- **Settings**: licence number and group, time zone, office hours (rows of days and times; none for always), days
  closed (holidays), what is passed on, and the words shown when someone is there or not.
- **Ways in**: as any agent (listed, trigger phrases such as "talk to a person", offered by the AI); a "Talk to a
  person" button at the head of the conversation; offered below the second answer in a row rated not helpful; and
  any link to `#mcp-ask-handoff` on the site, a report's closing button among them.
- **Handoffs log** (Agents → Handoffs, for those who may change the options): when, by which agent, from where,
  within office hours or not, whether someone was there, and what came of it (window opened, chat began, message
  left, window did not open), with totals, filters and CSV export (cells a spreadsheet would read as formulas are
  written as text). Only the question (300 characters at most), the page and whether there was a report are kept;
  no names or contact details; kept for a year. What the window does is told by the visitor's page with a key bound
  to that visitor, for a day; an outcome never goes back to an earlier one.
- **MCP tools** (plugin *MCP - MCP Ask*, Super-User-only): `list_agents`, `agent_stats` (offered, started,
  completed, left, the rate of completion, and how handoffs came out), `list_handoffs`, and `list_agent_reports`
  (type, scores, section titles, whether e-mailed; never the visitor's name or the report's private address).
- An agent may be done in its first turn; its conversation is closed at once.
- Fix: a report action's address is no longer turned into "http://…" when it is a link within the page.

## Ask the Site 0.9.0

Third stage of agents (docs/agents-spec.md): analysis reports, written for the visitor once an agent is done.

- **Report settings** (an agent's "Analysis report" tab): the title ({type} for the result), an introduction, the
  result types (name, description, colour, picture) the AI chooses from, as in a personality test, the scores
  (0–100, shown as bars), and the sections written in turn, each with what it is for, its length, whether it
  recommends what the site offers, and its picture (none, the type's, or made by AI from a prompt). Also the
  categories whose indexed pages it draws on (with subcategories; empty for the whole site), buttons at the end,
  pictures made a day, days kept, and whether the link is e-mailed. The defaults are a digital-transformation
  assessment in Traditional Chinese.
- **Written as it is read**: the type and scores first, then each section streamed into the conversation through
  the same filter as answers (only the site's hosts, citations of the pages found), pictures last so the text is
  never held up by one being drawn. Only the pages the report cites are listed, under the numbers it cites them
  by. The conversation follows the report as it grows unless the visitor has scrolled up. A report already being
  written (another tab, a reload) is followed rather than written twice; one that fails goes back to waiting.
- **Pictures**: through the "AI image" plugin (plg_system_gssaiimage) when it is installed and set up, with its
  service, key and model, otherwise with the component's own image settings (OpenAI or Open WebUI). No name,
  e-mail address or other personal detail goes into a prompt; the same prompt is drawn once and kept, and a
  site-wide daily cap applies, past which the type's own picture is used. Pictures are WebP (or JPEG), 1200 wide,
  in images/mcpask/reports.
- **Its own address**: a private page (a random 48-character key, kept only as a hash; not indexed, no referrer)
  with the type, scores, sections with pictures, buttons, the cited pages and a print button, in the module's
  colours; kept for the days set, then deleted. What was answered is kept encrypted only until the report is
  written.
- **E-mail**: the link is sent to the address given in the form (RSForm agent: the first e-mail field), in the
  site's language, with the visitor's name; phone numbers and street addresses never reach the report.
- Fix: lists in reports keep their numbers when paragraphs come between items; indented lines stay with the item
  above them.

## Ask the Site 0.8.0

First stage of agents (docs/agents-spec.md, stages 1 and 2): helpers that take over the conversation to get one
thing done, offered by plugins of the new "mcpask" group.

- **Agents page** (Ask the Site → Agents, for those who may change the options): set up agents of the kinds the
  installed plugins offer, each with its opening and closing words, triggers, languages, access and a daily limit
  per visitor, and see how often each was offered, started and completed in the last 30 days.
- **Three ways in**: listed with the search box and above the question field ("You can also try"); offered below
  an answer when the question means much the same as one of its trigger phrases (the question's embedding is
  reused, so this costs nothing more); or offered when the answering model judges it fits (a tool it may call,
  for agents described to it). The offer is a card below the answer.
- **The conversation**: a bar names the agent in charge, with a button to leave; what the visitor writes goes to
  the agent until it is done or left. Agents show cards the browser draws from data (text, fields, suggested
  replies, a summary to confirm, buttons, done, error), never markup of their own. What an agent keeps between
  turns stays on the server, encrypted with a key made of the site's secret and the visitor's own random key,
  bound to the visitor, for a day at most, and emptied as soon as the conversation is done or left. Every turn
  passes the checks a question does (same-site JSON, access, permission, blocked addresses, probes before any AI
  service call); field values are checked for attacks without being refused for being a date or a number range.
  A turn that fails leaves the cards before it usable.
- **RSForm! Pro agent** (`plg_mcpask_rsform`, new): fills in a form in conversation, a page at a time (cut at the
  form's page breaks), with the form's own texts. Visitors may fill in the card or answer in their own words: the
  AI service puts into the page's fields only values the fields take (options by value or label, dates, e-mail
  addresses) and says what is still missing; a complete page moves on by itself. Two or three suggested replies
  per page, never for names, e-mail addresses or phone numbers. A summary with buttons to change each page; only
  "Confirm and send" sends it, after every page is checked again. It is stored as RSForm stores a submission, with
  RSForm's events and e-mails; the form's captcha, its own PHP scripts and its mappings are not run.
- Fix: the module's suggestions box is as wide as its content where the module sits in a narrow place.

## Ask the Site 0.7.3

- **Appearance page** (Ask the Site → Appearance, for those who may change the options): describe the look in a
  sentence and the AI service proposes the module's colours (18 named colours: main and second colours, text on
  them, background, tints, body text, secondary text, headings, links, code, table headers, tip, warning and
  error boxes, the "new conversation" button). A live preview runs the module's own styles and script with a
  canned answer holding every kind of content; colours can also be chosen one by one, saved, reverted, or reset
  to the module's own. What the service returns is kept only as six-digit colours of those names; text that
  would be hard to read (WCAG contrast 4.5:1, 3:1 for button icons and box colours) is darkened or lightened.
  The description is passed as data and cannot change the rules.
- **The module uses the site theme** (option "Colours": site theme or its own); the accent colour of a module
  still applies to it. The theme is CSS custom properties only, added after the module's styles.
- **Colours that were fixed are now variables**: text on the accent, headings, links, code, table headers, tip,
  warning and error colours.
- **Screenshots open in a lightbox** over the conversation, with their description, the other screenshots of the
  answer a click or an arrow key away, closed by Escape or a click outside (Escape no longer closes the
  conversation behind). A template rule that enlarges images on hover no longer reaches them.
- **Wide layout**: twice the space between the sources and the edge of the window (32px) and between the answer
  and the sources (40px).
- The conversation's header is protected from a template's styles for `<header>` elements.

## Ask the Site 0.7.2

- **Source cards name this site too.** A page of the site itself is shown with the site's name (Global
  Configuration → Site Name) and its section, as pages of related sites already were, instead of the start of
  its address (often just "/tw/").

## Ask the Site 0.7.1

- **Browsers load the new script at once after an update.** The module's script and styles are now
  versioned with the module (`ask.js?0.7.1`) instead of Joomla's media version, which an update of the
  module alone does not always change: with a cached 0.6.6 script, images in answers showed as
  `![](/images/…)` and numbered steps all started at 1. The build checks that the two versions agree.

## Ask the Site 0.7.0

Answers with the site's own screenshots, tables and callouts.

- **Pages indexed as light Markdown.** Numbered lists keep their numbers, tables their rows, bold its stars;
  embeddings are made from the words only, so the search is unchanged. The scheduled task looks at every page
  again once and embeds only those whose passages come out different.
- **Images of the site's own pages** are kept in their passages as `![description](/path)`: only files that
  exist in the site's folder, with an image extension, given as a path, an address of the site itself
  (remembered from the last request for runs from the command line), never with a query, `..`, another host,
  `data:` or a script. Pages of other websites and of related sites keep no images.
- **In answers**, the model may show a step's screenshot right after the step (also on how-to questions with a
  template saved before this version), a Markdown table, and a tip, note or warning box (`> [!TIP]`, also
  `[!提示]`, `[!注意]`). The output filter lets an image through only when that exact path stands in the pages
  the answer was written from; any other image is removed without a trace (`removed: image`). An image or
  link is no longer cut in two by the sentence buffer, and the digits of an image path are not taken for a
  phone number.
- **Rendering**: images as paths under this site only (checked again in the browser), shown smaller and whole
  on a tap, removed when they fail to load; a screenshot under a numbered step stays in that step, and
  numbering goes on after it; tables scroll sideways on a phone; callouts in their colours, labelled in the
  visitor's language. Still no HTML from the model.
- Tests: `tests/ChunkerTest.php` (16 cases of images kept and refused), six image cases in
  `tests/OutputFilterTest.php`.

## Guides 0.1.0 (new package)

Second stage of step-by-step guides (docs/guides-spec.md, stage 2): `pkg_guides`, a component of its own that
Ask the Site reads like any other article.

- **Guides made from the site's articles.** Search articles (and FLEXIcontent items), open one cut into its
  sections (what stands under each heading), and add a section as a step or attach it as the source of a step;
  steps can also be written by hand. Steps are cards: reordered by dragging or arrows, edited as text or HTML,
  with a screenshot and a note. Each source is kept with the hash of its text, so a changed section shows.
- **Step library.** A step can be shared by several guides (a change changes it in all) or copied.
- **Placement**, set per site: "type categories and tags" (a fixed guides category; product and feature tags
  suggested from the sources' tags, grouped by top tag) or "topic categories" (categories suggested from where
  the sources are, how close the guide is to what each category holds when Ask the Site has indexed the site,
  and names that sound like guides).
- **Review by Super Users** ("Review guides" permission): send for review, approve (which publishes) or return
  with a note that is needed. A waiting block on top of the list (days waiting, items to fill in, steps
  without source, preview next to the source text) and a back-end home module "MCP - Guides to review",
  placed on the home page at install.
- **Published as a Joomla article or a FLEXIcontent item** in the structure Ask the Site recognises:
  "步驟 N：" headings with step-N anchors, prerequisites, how to check, links to the sources and the next
  guide. Saving a published guide sends it back to draft; approving again updates the same article. A new
  article gets an alias no other article of its category has.
- **FLEXIcontent**: new items are saved through FLEXIcontent's own model; Joomla tags are mapped to
  FLEXIcontent's tags; columns FLEXIcontent leaves empty are filled so a database in strict mode accepts the
  item; with Falang translation editing on, the save no longer fails on an empty end of publishing.
- **AI settings** of its own, or those of Ask the Site (for AI drafting, stage 4).
- Previews show article HTML in a sandboxed frame that runs no scripts.

## Ask the Site 0.6.6

First stage of step-by-step guides (docs/guides-spec.md, stage 1).

- **How-to answers.** Questions of how to do something (how-to words, editable) are answered by a template
  given to the model as plain text, so any model reads it (options: "How-to answers"): what it achieves,
  prerequisites, the steps in the order of the pages, numbered and cited, how to check, nothing made up.
- **Guides are given whole, in order.** A page with step headings ("步驟 1：", "Step 1:") is a guide; when any
  of its passages is found, all of them take its place in reading order (two guides, twelve passages at most),
  so an answer never starts at step 3 or puts steps out of turn. Any article written that way counts, made by
  hand or by the coming guides component.
- Citation groups wrapped in an extra pair of brackets, [[1][2]], no longer leave stray brackets.

## Ask the Site 0.6.5 / MCP Server 0.9.2

Hardening against prompt injection, after a visitor got the answer to repeat a marker they asked for.

- **Output filter** (new, `OutputFilter`): answers are held back a sentence at a time and filtered before
  the visitor sees anything. Removed: links to sites that are neither allowed (new option "Sites answers may
  link to") nor among the pages the answer was written from; phone numbers, e-mail addresses and LINE IDs
  the pages do not give; and sentences repeating text a visitor asked to have included (for questions marked
  as injection attempts, any 8 characters or more the pages do not hold; otherwise codes and markers such as
  RD-AIPT-K0). When nothing is left, a fixed polite refusal.
- **Prompt**: the question is marked off as `<user_question>` data; new rules not to repeat requested text,
  not to reveal or change the instructions, no contact details or promises the pages do not give, and a fixed
  refusal for questions that are only injection.
- **Rendering**: links in answers are clickable only over https to this site or the allowed sites, with
  `rel="noopener noreferrer nofollow"`; citations also in full-width brackets; a citation number with no page
  behind it is dropped (the widget had cut the list of pages at 12, so [13] and above showed raw).
- **Marks in the question log**: `injection_suspect` (new option "Phrases of injection attempts", with a list
  that comes with it), `html_payload`, `output_filtered`; shown as badges, filterable on the dashboard, with
  the answer as shown and as written before filtering. In the MCP tools: `flags`, the answer, a `flag` filter,
  counts per mark in `ask_stats` and `ask_overview`, flagged questions in the overview and pending list.
- **The ask icon** is now a speech bubble with two glowing stars instead of a magnifier.
- Tests: `tests/OutputFilterTest.php` (no Joomla needed) and `tests/acceptance.php` (T1–T9 against a site).

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


## Ask the Site 0.6.4

- **The on-screen keyboard no longer covers the search box or the conversation on iOS.** iOS lays its
  keyboard over the page without making the window smaller, so what is fixed to the bottom of the window
  ended up underneath it. The module now follows the visual viewport: while a keyboard is up, the open
  floating search box sits just above it (wherever the button was moved to), and the conversation keeps to
  the part of the window still seen, with the question field right above the keyboard.

## Ask the Site 0.6.3 / MCP Server 0.9.1

Security release after a full review; see SECURITY-AUDIT.md, "Review of 0.6.2". In short: requests
to listed websites and peers can no longer be steered into the server's own network; visitor
addresses are told apart correctly (IPv4 in IPv6 form, trusted proxies, CIDR in address lists);
hidden field text, FLEXIcontent type access and parent categories are respected; crawling and peer
syncing are bounded; finer back-end permissions. New option: Limits › Trusted proxies.

## Ask the Site 0.6.2

- The backup AI service has its own API format ("Backup answer API"), so Open WebUI on Chat Completions can
  fall back to OpenAI on the Responses API. When the main service fails part-way through a conversation with
  tools (before anything was shown), the backup starts the conversation over in its own format, since the
  rounds so far are written in the other one.

## Ask the Site 0.6.1

- **Backup AI service** (Options › AI service): when the main service is down (HTTP 5xx, 429, a timeout, no
  connection), for example an Open WebUI that sometimes answers 500, the answer is written by another,
  such as OpenAI itself, with its own address, key and chat model. It never steps in once part of an answer
  has been shown (the visitor would see it start over), and a refused request (a wrong key, an unknown model)
  is not hidden behind it. After a failure everyone goes straight to the backup for a minute instead of each
  waiting for the main service to fail. Embeddings use the backup only when allowed, which is right only when
  it offers the very same embedding model. The question log notes each answer the backup gave, and the
  dashboard shows when it last had to.

## Ask the Site 0.6.0

- **Visitors rate answers.** Each finished answer has 👍 / 👎; 👎 asks, optionally, what was missing or
  wrong. Ratings are kept in the site's own question log (no other extension needed), shown next to the
  questions on the dashboard, and remembered in the visitor's conversation across pages. A rating is
  accepted only with a reference signed by the site for that very answer, within a day.
- **Answer quality page** (Components › Ask the Site › Answer quality): how answers were rated over the
  last 30 days, and the questions to look at: rated not helpful, nothing found, or failed, the same
  question once, the worst first, with what visitors said.
- **Source rules.** For a question, choose the indexed pages it should be answered from (this site, peer
  sites, listed websites) and optionally a note. A visitor's question that means much the same, by
  embedding, so other wording counts too, is answered with those pages first and the note as the site
  owner's direction (followed, not quoted). A rule can hold other wordings of its question, and applies
  from a closeness of 0.5 by default (Options › Answers), measured: the same question in other words
  0.54 to 0.87, another product's 0.45, unrelated below 0.3. Pages no longer public are left out. "Try the
  question" shows the full answer with the rules as saved. Rules count how often they were used; the
  scheduled task embeds them again when the embedding model changes.

## Ask the Site 0.5.3

- **EasyBlog posts are indexed when they are saved.** A new plugin, "EasyBlog - Ask the Site", listens
  where EasyBlog announces saving, deleting and publishing or unpublishing from the list of posts (EasyBlog
  only loads its own plugin group then). A saved post is indexed again at once; one that is unpublished,
  trashed, deleted or no longer public leaves the index. It is switched on when first installed, also by
  an upgrade; plugins an administrator switched off stay off.
- **The scheduled task passes over what has not changed.** Indexed pages whose modified date is not later
  than the last time they were looked at are not read at all, unless the index settings changed since
  (then everything is looked at once) or they were last looked at more than 30 days ago (what a page shows
  can change without its date). The task log counts them as "passed over". Changed pages are still only
  embedded again when their text, title, address, language or access really changed.

## Ask the Site 0.5.2

- **Live progress for websites.** The dashboard's website card shows each site with a progress bar
  (pages handled of the pages to read), its state (not read yet, reading, to be continued, up to date,
  error), how it is read (RSS/Atom, sitemap, following links), pages indexed, when it was last read and
  why pages were passed over. Progress is stored, so it is there after a reload, on another computer,
  and while the scheduled task reads; the card checks every 5 seconds while something is under way.
- **A reading started from the dashboard carries on after a reload**, until everything is read or
  "Pause" is pressed.
- **One reading at a time.** The button and the scheduled task no longer read the same pages side by
  side: whichever comes second waits.

## Ask the Site 0.5.1

Found by reading seven real websites in one go:

- **Websites take turns.** Each run starts with the website read longest ago and gives each at least
  12 seconds, so one large site no longer keeps the others waiting at "0 / 0".
- **Less is read again on every run.** A crawl under way continues straight where it stopped, and the
  pages a feed or sitemap lists are reused for six hours instead of reading the list each time.
- **ASP.NET Web Forms pages** wrap the whole page in one form, which was removed as a form along with all
  the text ("no text"). A form holding most of the page is now unwrapped; ordinary forms still go.
- **Sitemap indexes** are read newest first, until there are enough pages, and their category, tag and
  author sitemaps are skipped: with WordPress (Yoast) the newest posts are in the last of many sitemaps.
- **An address that redirects to another host** (edu.example.com to www.example.com) is read where it
  leads, never to a private or reserved address.
- **A section only**: a website given as https://example.com/blog/ is crawled within /blog/.
- The dashboard shows why pages were passed over last time (HTTP 403, no text, robots.txt, no connection).

## Ask the Site 0.5.0

- **Who may ask, and how often, is set with Joomla's ACL.** Two new permissions on the component's
  Permissions tab: "Ask questions" (allowed for Public on install and upgrade, so nothing changes until an
  administrator says otherwise) and "Ask without limits" (Super Users always). The module is not shown to a
  visitor who may not ask; one who tries anyway is told to log in, or that the account may not ask.
- **Limits by user group** (Options › Limits): per-minute and per-day limits for chosen groups, for example
  more for signed-in members. The most generous limit of a visitor's groups applies, 0 meaning none; other
  groups keep the general limits. Signed-in users are counted by account (when who asked is recorded), so
  colleagues behind one office address no longer share a limit.
- **Websites without a feed or sitemap.** A listed website can now be given by its home page: its sitemap is
  looked for (robots.txt, `/sitemap.xml`, WordPress, JSitemap, OSMap), and when there is none its links are
  followed breadth first, to a crawl depth (3 by default) and the page limit. A reading may take several runs
  and resumes where it stopped; once through, pages no longer reached are removed, and the site is read again
  a day later. Sign-in, account, basket, search and file links are skipped, as are pages behind a login (401,
  403, a password field), `noindex` pages (whose links are still followed) and `nofollow` links; listing pages
  are followed but not answered from; tracking parameters are dropped and canonical addresses used.
- The crawler identifies itself as `Mozilla/5.0 (compatible; AskTheSite/0.4; +<site>)`.

## Ask the Site 0.4.0

- **EasyBlog posts are answered from** when EasyBlog is installed (Options › Content › "Include EasyBlog
  posts", on by default). Only posts everyone may read: published and not trashed or archived, within their
  dates, public, without a password or "log in to read", in a published public category, and for team blogs
  a published team open to everyone. Addresses come from EasyBlog's own router.
- The component now indexes several kinds of the site's own content side by side. The synchronise button
  works through them in turn, the scheduled task gives each its share of the time, and pruning keeps the
  pages of every source that is still answered from (switching EasyBlog off removes its posts).
- Not yet: EasyBlog posts are not re-indexed the moment they are saved (the scheduled task picks them up),
  and they are not shared with peer sites.

## Ask the Site 0.3.1

- **The floating button can be moved.** Drag it anywhere; on release it settles on the nearer side of the
  window at the height it was left, and opens towards the middle. The spot is kept in a cookie
  (`mcp_ask_float`, one year, this site only), which the module also reads, so later pages arrive with the
  button already there. A tap still opens it; moving only starts after a few pixels.

## Ask the Site 0.3.0

- **Websites by feed or sitemap.** Options › Other sites › "Websites by feed or sitemap": name, the
  address of an RSS/Atom feed or `sitemap.xml` (sitemap indexes too), an optional content selector and
  a page limit. Pages are read politely (same site only, robots.txt, a pause between requests), the main
  text is found by itself, and only new or changed pages are embedded again. Answers cite them with the
  site's name. A dashboard card shows each website's pages, last read and errors, with a "Read websites
  now" button; the scheduled task keeps them up to date.
- Excerpts from other sites are now marked as such for the model, so it can say where a point comes from.

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
