<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Ask;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Indexes websites the administrator has listed, from their RSS or Atom feed or their sitemap:
 * new and changed pages are read and indexed, pages that are gone are removed.
 *
 * Only the site's own pages are read, robots.txt is kept to, and a page that carries its full
 * text in the feed is not fetched at all. Their text is answered from like this site's own, so
 * only sites the administrator knows belong in the list.
 *
 * @since  0.3.0
 */
final class WebSync
{
    /** Pages of listed websites are filed under this prefix and the ID of the source. */
    public const PREFIX = 'web:';

    /** A page whose feed or sitemap gives no date is read again after this many days. */
    private const STALE_DAYS = 7;

    /** Text in a feed item at least this long is taken as the page itself. */
    private const FULL_TEXT = 600;

    /** Sitemaps read from one sitemap index. */
    private const MAX_SITEMAPS = 20;

    /** Sitemaps in an index that only list other pages: categories, tags, authors (WordPress, Yoast, Rank Math). */
    private const LISTING_MAPS = '#(category|categories|tag|tags|post_tag|author|authors|format|taxonomies|taxonomy)[-_]?sitemap|sitemap[-_](category|tag|author|taxonom)|wp-sitemap-(taxonomies|users)#i';

    /** Root elements of the lists this reads: RSS 2, RSS 1 (RDF), Atom, sitemaps. */
    private const LISTS = ['rss', 'rdf', 'feed', 'urlset', 'sitemapindex'];

    /** Where sitemaps usually are, when robots.txt does not say: the common name, WordPress, JSitemap, OSMap. */
    private const SITEMAPS = [
        '/sitemap.xml',
        '/sitemap_index.xml',
        '/wp-sitemap.xml',
        '/index.php?option=com_jmap&view=sitemap&format=xml',
        '/index.php?option=com_osmap&view=xml&id=1',
    ];

    /** Links taken from one crawled page, and how far a crawl may grow, by the site's page limit. */
    private const LINKS_PER_PAGE = 500;

    private const QUEUE_PER_PAGE = 20;

    private const SEEN_PER_PAGE = 50;

    /** Pages a crawl may fetch for each page it may keep (listing and noindex pages included). */
    private const FETCHES_PER_PAGE = 5;

    /** @var string[]  Namespaces of the XML document being read, worked out once per document */
    private array $namespaces = [];

    /** When the site being read has to stop, for the steps that read lists */
    private float $until = 0.0;

    /** Seconds a site gets at least in one run, when it is reached at all. */
    private const MIN_SLICE = 12;

    /** Hours the pages a feed or sitemap lists are reused before the list is read again. */
    private const LIST_HOURS = 6;

    /** A crawled site is read again from the start this many hours after it was read through. */
    private const RECRAWL_HOURS = 24;

    /** Addresses that are about an account, a basket, a search or a format other than the page. */
    private const SKIP = '#/(log-?in|log-?out|sign-?in|sign-?up|sign-?out|register|registration|my-?account|account|profile|cart|basket|checkout|wishlist|search|wp-admin|wp-login\.php|administrator)(/|\.|\?|$)'
        . '|[?&](option=com_users|task=|format=(feed|raw|json|pdf|amp)|tmpl=component|print=|replytocom=|share=|action=|output=)'
        . '|\.(pdf|jpe?g|png|gif|webp|svg|ico|zip|rar|7z|gz|docx?|xlsx?|pptx?|odt|mp3|mp4|m4a|mov|avi|webm|css|js|json|xml|rss|atom|txt)(\?|$)#i';

    /** Pages of a listing: followed to find what they list, not answered from themselves. */
    private const LISTING = '#[?&](start|limitstart|page|paged)=\d|/page/\d+/?$#i';

    /** @var array<string, int>  Why pages of the site being read were passed over, and how often */
    private array $skipped = [];

    /** How the site being read is read: "feed", "sitemap" or "crawl" */
    private string $mode = '';

    /** Name of the lock that keeps two readings (the button and the scheduled task) apart. */
    private const LOCK = self::PREFIX . 'lock';

    /** Name of the note that a reading was started from the dashboard and is not finished. */
    public const WANTED = self::PREFIX . 'wanted';

    public function __construct(private DatabaseInterface $db, private Settings $settings)
    {
    }

    /**
     * Note why a page was passed over, for the dashboard: "HTTP 403", "no text" and the like.
     */
    private function skip(string $reason): void
    {
        $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + 1;
    }

    public static function isWeb(string $source): bool
    {
        return str_starts_with($source, self::PREFIX);
    }

    /**
     * Work through the listed websites until everything is up to date or the time is up.
     *
     * @return  array<string, array{name: string, total: int, stored: int, imported: int, removed: int, remaining: int, error: string}>  By source ID
     */
    public function run(int $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        $state    = new State($this->db);
        $sites    = $this->settings->websites();
        $report   = [];

        // One reading at a time: another one is under way (or ended badly less than a minute ago)
        if (self::running($this->db)) {
            return [];
        }

        $state->set(self::LOCK, ['until' => time() + $seconds + 60]);

        try {
            $this->forget();

            // The site read longest ago first, so one large site cannot keep the others waiting
            $last = array_map(fn ($site) => $state->get(self::PREFIX . $site['id']), $sites);
            $order = array_keys($sites);
            usort($order, static fn ($a, $b) => strcmp((string) ($last[$a]['synced'] ?? ''), (string) ($last[$b]['synced'] ?? '')));

            foreach ($order as $number => $id) {
                $site   = $sites[$id];
                $result = ['name' => $site['name'], 'total' => 0, 'stored' => 0, 'imported' => 0, 'removed' => 0, 'remaining' => 0, 'error' => ''];
                $now    = microtime(true);
                // An even share of the time that is left, but enough to get somewhere: sites that are
                // not reached come first next time
                $until   = min($deadline, $now + max(self::MIN_SLICE, ($deadline - $now) / (\count($order) - $number)));
                $reached = $now < $deadline;

                $this->skipped = [];
                $this->mode    = '';

                if ($reached) {
                    // The lock lasts as long as this site may take, and a little more
                    $state->set(self::LOCK, ['until' => time() + (int) ceil($until - $now) + 60]);
                    $this->until = $until;

                    try {
                        $result = $this->read($site, $until) + $result;
                    } catch (ServiceException $e) {
                        $result['error'] = $e->getMessage();
                    } catch (\Throwable $e) {
                        // One site that breaks something must not keep the others from being read
                        $result['error'] = 'Could not be read: ' . \get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200);
                    }
                } else {
                    // Not reached this time: the next round starts with it
                    $result['remaining'] = 1;
                }

                $result['stored'] = \count($this->stored($id));
                $result['done']   = $result['done'] ?? max(0, $result['total'] - $result['remaining']);
                $report[$id]      = $result;

                if ($reached) {
                    $previous = $state->get(self::PREFIX . $id);

                    $state->set(self::PREFIX . $id, [
                        'synced'    => Factory::getDate()->toSql(),
                        'error'     => $result['error'],
                        'remaining' => $result['remaining'],
                        'skipped'   => $this->skipped,
                        // A failed reading keeps showing how far the last good one got
                        'total'     => $result['error'] !== '' ? (int) ($previous['total'] ?? 0) : $result['total'],
                        'done'      => $result['error'] !== '' ? (int) ($previous['done'] ?? 0) : $result['done'],
                        'mode'      => $this->mode ?: (string) ($previous['mode'] ?? ''),
                    ]);
                }
            }
        } finally {
            $state->set(self::LOCK, ['until' => 0]);
        }

        // In the order the administrator listed them
        return array_replace(array_intersect_key($sites, $report), $report);
    }

    /**
     * Whether a reading of the websites is under way right now, from the dashboard or the scheduled task.
     */
    public static function running(DatabaseInterface $db): bool
    {
        return (int) ((new State($db))->get(self::LOCK)['until'] ?? 0) > time();
    }

    /**
     * What the dashboard shows about each website.
     *
     * @return  array<string, array{name: string, url: string, stored: int, synced: ?string, error: string, remaining: int, skipped: array<string, int>, total: int, done: int, mode: string}>
     */
    public function status(): array
    {
        $state  = new State($this->db);
        $status = [];

        foreach ($this->settings->websites() as $id => $site) {
            $last        = $state->get(self::PREFIX . $id);
            $status[$id] = [
                'id'        => $id,
                'name'      => $site['name'],
                'url'       => $site['url'],
                'stored'    => \count($this->stored($id)),
                'synced'    => $last['synced'] ?? null,
                'error'     => (string) ($last['error'] ?? ''),
                'remaining' => (int) ($last['remaining'] ?? 0),
                'skipped'   => (array) ($last['skipped'] ?? []),
                'total'     => (int) ($last['total'] ?? 0),
                'done'      => (int) ($last['done'] ?? 0),
                'mode'      => (string) ($last['mode'] ?? ''),
            ];
        }

        return $status;
    }

    /**
     * @param  array{id: string, name: string, url: string, selector: string, limit: int}  $site
     */
    private function read(array $site, float $deadline): array
    {
        $state   = new State($this->db);
        $indexer = new Indexer($this->db, $this->settings);
        $source  = self::PREFIX . $site['id'];
        $crawl   = $state->get(self::PREFIX . $site['id'] . ':crawl');

        // A crawl that is under way goes on where it stopped, at the address it started from
        if (!empty($crawl['queue']) && !empty($crawl['url'])) {
            $site['url'] = $crawl['url'];

            return $this->crawl(new WebClient($site['url']), $indexer, $site, $deadline);
        }

        // A list read lately is used again, so a large sitemap is not read on every run
        $cache = $state->get(self::PREFIX . $site['id'] . ':entries');

        if (($cache['fetched'] ?? '') > Factory::getDate('-' . self::LIST_HOURS . ' hours')->toSql() && isset($cache['url'], $cache['entries'])) {
            $site['url'] = $cache['url'];
            $client      = new WebClient($site['url']);
            $entries     = (array) $cache['entries'];
            $this->mode  = (string) ($cache['mode'] ?? 'sitemap');
        } else {
            // A site given by an address that moves elsewhere (edu.example.com to www.example.com) is
            // read where it moved to
            $site['url'] = WebClient::landing($site['url']);
            $client      = new WebClient($site['url']);
            $entries     = $this->entries($client, $site);

            if ($entries === null) {
                return $this->crawl($client, $indexer, $site, $deadline);
            }

            $state->set(self::PREFIX . $site['id'] . ':entries', ['fetched' => Factory::getDate()->toSql(), 'url' => $site['url'], 'mode' => $this->mode, 'entries' => $entries]);
        }

        $stored  = $this->stored($site['id']);
        $result  = ['total' => \count($entries), 'imported' => 0, 'removed' => 0, 'remaining' => 0];

        // A list that suddenly has nothing is more likely broken than a site without pages
        if (!$entries && $stored) {
            throw new ServiceException(Text::sprintf('COM_MCPASK_WEBSITE_ERROR_EMPTY', $site['name']));
        }

        // What the list no longer has is gone from the site, or no longer among the newest
        foreach (array_diff_key($stored, $entries) as $itemId => $row) {
            $indexer->remove($source, (int) $itemId);
            $result['removed']++;
        }

        $stale = Factory::getDate('-' . self::STALE_DAYS . ' days')->toSql();
        $due   = array_filter($entries, static function ($entry, $itemId) use ($stored, $stale) {
            $row = $stored[$itemId] ?? null;

            return !$row || ($entry['date'] !== '' ? $entry['date'] > $row['checked'] : $row['checked'] < $stale);
        }, ARRAY_FILTER_USE_BOTH);

        foreach (array_keys($due) as $number => $itemId) {
            if (microtime(true) > $deadline) {
                $result['remaining'] = \count($due) - $number;

                break;
            }

            $entry = $due[$itemId];
            $page  = $this->page($client, $entry, $site['selector']);

            if ($page === 'gone') {
                // Gone, or no longer to be read: it does not stay in the index
                $indexer->remove($source, $itemId);

                continue;
            }

            if ($page === null) {
                // Not readable just now: what was stored stays, and it is tried again next time
                continue;
            }

            $document = new Document($source, $itemId, mb_substr($page['title'] ?: $entry['title'] ?: $entry['url'], 0, 255), $entry['url'], '*', 1, $page['html']);

            try {
                $outcome = $indexer->index($document);

                if ($outcome === 'indexed') {
                    $result['imported']++;
                } elseif ($outcome === 'empty') {
                    $this->skip('no text');
                }
            } catch (ServiceException $e) {
                $result['remaining'] = \count($due) - $number;

                throw $e;
            }
        }

        return $result;
    }

    /**
     * The pages a source lists, by the ID they are filed under. A feed gives its items with
     * their text; a sitemap only addresses, and a sitemap index points to more sitemaps.
     *
     * @return  array<int, array{url: string, title: string, date: string, html: string}>|null  Null when the site is to be crawled instead
     */
    private function entries(WebClient $client, array $site): ?array
    {
        $response = $client->get($site['url']);

        if ($response['status'] !== 200) {
            throw new ServiceException(Text::sprintf('COM_MCPASK_WEBSITE_ERROR_LIST', $site['name'], $response['status']));
        }

        $xml = $this->xml($response['body']);

        if (!$xml || !\in_array(strtolower($xml->getName()), self::LISTS, true)) {
            // A page rather than a list: the site's sitemap, when it can be found, otherwise its links
            if (!\in_array($response['type'], ['text/html', 'application/xhtml+xml', ''], true)) {
                throw new ServiceException(Text::sprintf('COM_MCPASK_WEBSITE_ERROR_FORMAT', $site['name']));
            }

            $xml = $this->discover($client, $response['url'], $site['id']);

            if (!$xml) {
                return null;
            }
        }

        $entries    = [];
        $root       = strtolower($xml->getName());
        $this->mode = \in_array($root, ['urlset', 'sitemapindex'], true) ? 'sitemap' : 'feed';

        if ($root === 'sitemapindex') {
            // The sitemaps changed most lately first, until there are enough pages; sitemaps of
            // categories, tags and authors only list what the others have
            $maps = [];

            foreach ($this->children($xml, 'sitemap') as $map) {
                $loc = trim((string) $this->child($map, 'loc'));

                if ($loc !== '' && !preg_match(self::LISTING_MAPS, $loc)) {
                    $maps[] = ['loc' => $loc, 'date' => $this->date((string) $this->child($map, 'lastmod'))];
                }
            }

            usort($maps, static fn ($a, $b) => strcmp($b['date'], $a['date']));

            foreach (\array_slice($maps, 0, self::MAX_SITEMAPS) as $map) {
                if ($this->until && microtime(true) > $this->until) {
                    break;
                }

                $part = $client->get($map['loc']);
                $xml2 = $part['status'] === 200 ? $this->xml($part['body']) : null;

                foreach ($xml2 ? $this->children($xml2, 'url') : [] as $url) {
                    $entries[] = ['url' => trim((string) $this->child($url, 'loc')), 'title' => '', 'date' => $this->date((string) $this->child($url, 'lastmod')), 'html' => ''];
                }

                if (\count($entries) >= $site['limit'] * 2) {
                    break;
                }
            }
        } elseif ($root === 'urlset') {
            foreach ($this->children($xml, 'url') as $url) {
                $entries[] = ['url' => trim((string) $this->child($url, 'loc')), 'title' => '', 'date' => $this->date((string) $this->child($url, 'lastmod')), 'html' => ''];
            }
        } elseif ($root === 'rss' || $root === 'rdf') {
            $items = $root === 'rss' ? $this->children($xml->channel ?? $xml, 'item') : $this->children($xml, 'item');

            foreach ($items as $item) {
                $content = $item->children('http://purl.org/rss/1.0/modules/content/');

                $entries[] = [
                    'url'   => trim((string) $this->child($item, 'link')),
                    'title' => trim((string) $this->child($item, 'title')),
                    'date'  => $this->date((string) ($this->child($item, 'pubDate') ?? $item->children('http://purl.org/dc/elements/1.1/')->date)),
                    'html'  => (string) ($content->encoded ?? '') ?: (string) $this->child($item, 'description'),
                ];
            }
        } elseif ($root === 'feed') {
            foreach ($this->children($xml, 'entry') as $item) {
                $href = '';

                foreach ($this->children($item, 'link') as $link) {
                    if (\in_array((string) ($link['rel'] ?? 'alternate'), ['alternate', ''], true)) {
                        $href = (string) $link['href'];

                        break;
                    }
                }

                $entries[] = [
                    'url'   => trim($href),
                    'title' => trim((string) $this->child($item, 'title')),
                    'date'  => $this->date((string) ($this->child($item, 'updated') ?? $this->child($item, 'published'))),
                    'html'  => (string) ($this->child($item, 'content') ?? $this->child($item, 'summary')),
                ];
            }
        } else {
            throw new ServiceException(Text::sprintf('COM_MCPASK_WEBSITE_ERROR_FORMAT', $site['name']));
        }

        // Only the site's own pages, each once, the newest first when that is known, as many as allowed
        $kept = [];

        foreach ($entries as $entry) {
            $entry['url'] = $client->absolute($entry['url'], $site['url']);

            if ($entry['url'] !== '' && $client->owns($entry['url'])) {
                $kept[self::itemId($entry['url'])] ??= $entry;
            }
        }

        uasort($kept, static fn ($a, $b) => strcmp($b['date'], $a['date']));

        return \array_slice($kept, 0, $site['limit'], true);
    }

    /**
     * The text of a page: from the feed when it carries the whole text, otherwise from the page.
     *
     * @return  array{title: string, html: string}|string|null  "gone" when the page is gone or may not be read, null when it could not be read just now
     */
    private function page(WebClient $client, array $entry, string $selector): array|string|null
    {
        if (!$client->allowed($entry['url'])) {
            $this->skip('robots.txt');

            return 'gone';
        }

        if (mb_strlen(trim(strip_tags($entry['html']))) >= self::FULL_TEXT) {
            return ['title' => $entry['title'], 'html' => $entry['html']];
        }

        $response = $client->get($entry['url']);

        if (\in_array($response['status'], [404, 410], true) || ($response['status'] === 200 && !\in_array($response['type'], ['text/html', 'application/xhtml+xml', ''], true))) {
            $this->skip($response['status'] === 200 ? $response['type'] : 'HTTP ' . $response['status']);

            return 'gone';
        }

        if ($response['status'] !== 200) {
            $this->skip($response['status'] ? 'HTTP ' . $response['status'] : 'no connection');

            return null;
        }

        $page = WebPage::read($response['body'], $selector, $response['charset']);

        return ['title' => $page['title'] ?: $entry['title'], 'html' => $page['html']];
    }

    /**
     * The sitemap of a site that was given by an ordinary page: the one robots.txt names, or one
     * where sitemaps usually are.
     */
    private function discover(WebClient $client, string $page, string $siteId): ?\SimpleXMLElement
    {
        // What was found is remembered for a week, so a site without a sitemap is not asked every time
        $state  = new State($this->db);
        $key    = self::PREFIX . $siteId . ':list';
        $known  = $state->get($key);
        $recent = ($known['checked'] ?? '') > Factory::getDate('-' . self::STALE_DAYS . ' days')->toSql();

        if ($recent && ($known['url'] ?? '') === '') {
            return null;
        }

        $candidates = $recent ? [$known['url']] : $client->sitemaps();

        if (!$recent) {
            foreach (self::SITEMAPS as $path) {
                $candidates[] = $client->absolute($path, $page);
            }
        }

        foreach (array_unique($candidates) as $url) {
            if ($this->until && microtime(true) > $this->until) {
                // Out of time: looked for again next time, not remembered as "none"
                return null;
            }

            $response = $client->get($url);
            $xml      = $response['status'] === 200 ? $this->xml($response['body']) : null;

            if ($xml && \in_array(strtolower($xml->getName()), ['urlset', 'sitemapindex'], true) && \count($xml->children()) > 0) {
                $state->set($key, ['url' => $url, 'checked' => Factory::getDate()->toSql()]);

                return $xml;
            }
        }

        $state->set($key, ['url' => '', 'checked' => Factory::getDate()->toSql()]);

        return null;
    }

    /**
     * Read a site without a feed or sitemap by following its links from the given page, breadth
     * first, to a limited depth and number of pages. One reading of the whole site takes as many
     * runs as it needs; where it got to is kept in between. Once through, pages it no longer
     * reached are removed, and the next reading starts a day later.
     */
    private function crawl(WebClient $client, Indexer $indexer, array $site, float $deadline): array
    {
        $state  = new State($this->db);
        $key    = self::PREFIX . $site['id'] . ':crawl';
        $source = self::PREFIX . $site['id'];
        $crawl  = $state->get($key);
        $result = ['total' => 0, 'imported' => 0, 'removed' => 0, 'remaining' => 0];

        $due = empty($crawl['queue']) && (empty($crawl['done']) || $crawl['done'] < Factory::getDate('-' . self::RECRAWL_HOURS . ' hours')->toSql());

        if ($due) {
            $start = $this->normal($site['url']);
            $crawl = ['started' => Factory::getDate()->toSql(), 'url' => $site['url'], 'done' => null, 'queue' => [[$start, 0]], 'seen' => [self::key($start) => 1], 'kept' => [], 'fetched' => 0];
        }

        $stored = $this->stored($site['id']);

        // Given a section (https://example.com/blog/), only that section is read
        $scope = preg_replace('#/[^/]*$#', '/', (string) parse_url($this->normal($site['url']), PHP_URL_PATH)) ?: '/';
        $inScope = static fn (string $link): bool => str_starts_with((string) parse_url($link, PHP_URL_PATH) ?: '/', $scope);

        while ($crawl['queue'] && \count($crawl['kept']) < $site['limit']) {
            if (microtime(true) > $deadline) {
                break;
            }

            // However many pages a site passes visitors through, a crawl reads only so many
            if (($crawl['fetched'] ?? 0) >= $site['limit'] * self::FETCHES_PER_PAGE) {
                $crawl['queue'] = [];

                break;
            }

            [$url, $depth] = array_shift($crawl['queue']);
            $response      = $client->get($url);
            $crawl['fetched'] = ($crawl['fetched'] ?? 0) + 1;
            $final         = $this->normal($response['url'] ?: $url);
            $itemId        = self::itemId($final);

            // Gone, not allowed, or behind a login: not kept. Out of reach just now: kept as it was.
            if (\in_array($response['status'], [401, 403, 404, 410], true) || ($response['status'] === 0 && !$client->allowed($url))) {
                $this->skip($response['status'] ? 'HTTP ' . $response['status'] : 'robots.txt');

                continue;
            }

            if ($response['status'] !== 200) {
                $this->skip($response['status'] ? 'HTTP ' . $response['status'] : 'no connection');

                if (isset($stored[$itemId])) {
                    $crawl['kept'][$itemId] = 1;
                }

                continue;
            }

            if (!\in_array($response['type'], ['text/html', 'application/xhtml+xml', ''], true) || preg_match(self::SKIP, $final)) {
                continue;
            }

            $signals = WebPage::signals($response['body']);

            if (!$signals['nofollow'] && $depth < $site['depth']) {
                $base = $signals['base'] !== '' ? $client->absolute($signals['base'], $final) : $final;

                foreach (\array_slice($signals['links'], 0, self::LINKS_PER_PAGE) as $href) {
                    // A crawl that has found this much has found enough
                    if (\count($crawl['queue']) >= $site['limit'] * self::QUEUE_PER_PAGE || \count($crawl['seen']) >= $site['limit'] * self::SEEN_PER_PAGE) {
                        break;
                    }

                    $link = $this->normal($client->absolute($href, $base));

                    if ($link !== '' && $client->owns($link) && $inScope($link) && !preg_match(self::SKIP, $link) && !isset($crawl['seen'][self::key($link)])) {
                        $crawl['seen'][self::key($link)] = 1;
                        $crawl['queue'][]                = [$link, $depth + 1];
                    }
                }
            }

            // Pages that ask not to be indexed, listing pages, and sign-in forms are only passed through
            $canonical = $signals['canonical'] !== '' ? $this->normal($client->absolute($signals['canonical'], $final)) : '';
            $address   = $canonical !== '' && $client->owns($canonical) ? $canonical : $final;
            $itemId    = self::itemId($address);

            if ($signals['noindex'] || preg_match(self::LISTING, $address) || preg_match('/<input[^>]+type\s*=\s*["\']?password/i', $response['body']) || isset($crawl['kept'][$itemId])) {
                continue;
            }

            $page = WebPage::read($response['body'], $site['selector'], $response['charset']);

            if (trim(strip_tags($page['html'])) === '') {
                $this->skip('no text');

                continue;
            }

            $outcome = $indexer->index(new Document($source, $itemId, mb_substr($page['title'] ?: $address, 0, 255), $address, '*', 1, $page['html']));

            if ($outcome !== 'empty') {
                $crawl['kept'][$itemId] = 1;
                $result['imported'] += $outcome === 'indexed' ? 1 : 0;
            }
        }

        // Through the site (or as far as the limit allows): what was not reached this time is gone
        if (!$crawl['queue'] || \count($crawl['kept']) >= $site['limit']) {
            // A reading that found nothing at all is more likely a failure than an empty site
            if (!$crawl['kept'] && $stored) {
                $state->set($key, ['done' => Factory::getDate()->toSql(), 'queue' => []]);

                throw new ServiceException(Text::sprintf('COM_MCPASK_WEBSITE_ERROR_EMPTY', $site['name']));
            }

            foreach (array_diff_key($stored, $crawl['kept']) as $itemId => $row) {
                $indexer->remove($source, (int) $itemId);
                $result['removed']++;
            }

            $crawl['queue'] = [];
            $crawl['done']  = Factory::getDate()->toSql();
        }

        $state->set($key, $crawl);

        $this->mode          = 'crawl';
        $result['total']     = min($site['limit'], \count($crawl['kept']) + \count($crawl['queue']));
        $result['done']      = min($result['total'], \count($crawl['kept']));
        // Links still to follow, but no more than the pages the limit leaves room for
        $result['remaining'] = min(\count($crawl['queue']), max(0, $site['limit'] - \count($crawl['kept'])));

        return $result;
    }

    /**
     * An address in one form: without its fragment and tracking parameters, so the same page is
     * not read twice under two names.
     */
    private function normal(string $url): string
    {
        $url   = (string) preg_replace('/#.*$/', '', trim($url));
        $parts = parse_url($url);

        if (!$parts || empty($parts['host'])) {
            return '';
        }

        $query = '';

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $values);
            $values = array_filter($values, static fn ($name) => !preg_match('/^(utm_\w+|fbclid|gclid|mc_cid|mc_eid|_ga|ref)$/i', (string) $name), ARRAY_FILTER_USE_KEY);
            ksort($values);
            $query = $values ? '?' . http_build_query($values) : '';
        }

        return strtolower($parts['scheme'] ?? 'https') . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '/') . $query;
    }

    private static function key(string $url): string
    {
        return substr(sha1($url), 0, 16);
    }

    private function xml(string $body): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        // No external entities and no network: a feed is data, nothing else
        $xml = simplexml_load_string(trim($body), \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // Worked out once: asking each element walks the whole document again (slow on a large sitemap)
        $this->namespaces = $xml ? \array_slice(array_values(array_unique($xml->getDocNamespaces(true))), 0, 20) : [];

        return $xml ?: null;
    }

    /**
     * Child elements by name, whatever namespace the document puts them in.
     *
     * @return  \SimpleXMLElement[]
     */
    private function children(\SimpleXMLElement $parent, string $name): array
    {
        $found = [];

        foreach ([null, ...$this->namespaces] as $namespace) {
            foreach ($namespace === null ? $parent->children() : $parent->children($namespace) as $child) {
                if ($child->getName() === $name) {
                    $found[] = $child;
                }
            }
        }

        return $found;
    }

    private function child(\SimpleXMLElement $parent, string $name): ?\SimpleXMLElement
    {
        return $this->children($parent, $name)[0] ?? null;
    }

    /**
     * A date in any of the usual forms, as the database stores dates; empty when there is none.
     */
    private function date(string $text): string
    {
        $time = trim($text) !== '' ? strtotime(trim($text)) : false;

        return $time ? gmdate('Y-m-d H:i:s', $time) : '';
    }

    /**
     * The ID a page is filed under: from its address, so the same page always gets the same one.
     */
    public static function itemId(string $url): int
    {
        return hexdec(substr(sha1($url), 0, 8)) & 0x7fffffff;
    }

    /**
     * @return  array<int, array{checked: string}>  What is stored of a source, by page ID
     */
    private function stored(string $siteId): array
    {
        $source = self::PREFIX . $siteId;
        $query  = $this->db->getQuery(true)
            ->select('item_id, checked')
            ->from($this->db->quoteName('#__mcpask_documents'))
            ->where('source = :source')
            ->bind(':source', $source, ParameterType::STRING);

        return $this->db->setQuery($query)->loadAssocList('item_id');
    }

    /**
     * Drop the pages of websites that are no longer listed.
     */
    private function forget(): void
    {
        $db      = $this->db;
        $current = array_map(static fn ($id) => self::PREFIX . $id, array_keys($this->settings->websites()));
        $query   = $db->getQuery(true)
            ->select('id, source')
            ->from($db->quoteName('#__mcpask_documents'))
            ->where('source LIKE ' . $db->quote(self::PREFIX . '%'));

        $gone = array_filter($db->setQuery($query)->loadObjectList(), static fn ($row) => !\in_array($row->source, $current, true));

        // And what was remembered about them: crawls under way, lists, sitemaps found
        $known = $db->setQuery($db->getQuery(true)->select('name')->from($db->quoteName('#__mcpask_state'))->where('name LIKE ' . $db->quote(self::PREFIX . '%:%')))->loadColumn();

        foreach ($known as $name) {
            if (!\in_array(substr($name, 0, (int) strrpos($name, ':')), $current, true)) {
                $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__mcpask_state'))->where('name = ' . $db->quote($name)))->execute();
            }
        }

        foreach (array_chunk(array_map(static fn ($row) => (int) $row->id, $gone), 200) as $ids) {
            $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__mcpask_chunks'))->whereIn('document_id', $ids))->execute();
            $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__mcpask_documents'))->whereIn('id', $ids))->execute();
        }
    }
}
