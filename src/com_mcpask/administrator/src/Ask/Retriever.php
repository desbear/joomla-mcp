<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Ask;

use Gss\Component\Mcpask\Administrator\Ask\Source\SourceInterface;
use Gss\Component\Mcpask\Administrator\Ask\Source\Sources;
use Joomla\CMS\Access\Access;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

\defined('_JEXEC') or die;

/**
 * Finds the passages closest in meaning to a question, among this site's pages and those read
 * from other sites.
 *
 * On MySQL and MariaDB the search has two passes. The database first picks the passages whose
 * bit codes differ least from the question's (see Vector::code()), which it does for tens of
 * thousands of passages in a few milliseconds; only those are then compared exactly in PHP. On
 * other databases, and while older passages have no code yet, every embedding is compared in PHP.
 *
 * @since  0.1.0
 */
final class Retriever
{
    /** Rows read from the database at a time when everything is compared. */
    private const PAGE = 500;

    /**
     * Passages the first pass hands to the second: at least this many, this many per passage
     * wanted, and this part of all there are. Measured on a real index, the passages that are
     * truly the best twenty stood within the first eighth of the rough ordering.
     */
    private const SHORTLIST = 600;

    private const SHORTLIST_FACTOR = 60;

    private const SHORTLIST_PART = 8;

    /** Passages of one page that may be used for one answer, so a long page cannot crowd out the others. */
    private const PER_DOCUMENT = 2;

    /** Passages more alike than this are the same text, published on two pages: one of them is enough. */
    private const SAME_TEXT = 0.97;

    /** Set to false to compare everything, for measuring what the first pass costs in accuracy. */
    public bool $twoPasses = true;

    /** @var array<string, SourceInterface>  This site's own kinds of content, by name */
    private array $own;

    public function __construct(private DatabaseInterface $db, private Settings $settings, private SourceInterface $source)
    {
        $this->own = Sources::own($db, $settings);
    }

    /**
     * The address of a stored page: a page of another site keeps the one it had there.
     */
    public function url(object $page): string
    {
        if (Sources::isElsewhere($page->source)) {
            return $page->link;
        }

        return ($this->own[$page->source] ?? $this->source)->url($page->link);
    }

    /**
     * Whether a stored page may be answered from now: a page of this site while a guest may read
     * it; a page from elsewhere while that site is still one that is read (a peer or website that
     * was removed stops counting at once, not only once its pages are cleared away).
     */
    private function usable(object $row): bool
    {
        if (Sources::isElsewhere($row->source)) {
            return \in_array($row->source, Sources::elsewhere($this->settings), true);
        }

        return (bool) $this->readable([$row]);
    }

    /**
     * Of these stored pages of this site, the ones a guest may still read, as "source:item" keys.
     *
     * @param   object[]  $rows  With source and item_id
     *
     * @return  array<string, true>
     */
    private function readable(array $rows): array
    {
        $bySource = [];
        $readable = [];

        foreach ($rows as $row) {
            if (!Sources::isElsewhere($row->source)) {
                $bySource[$row->source][] = (int) $row->item_id;
            }
        }

        foreach ($bySource as $name => $ids) {
            // A source that is no longer answered from has nothing readable
            foreach (isset($this->own[$name]) ? $this->own[$name]->visible($ids) : [] as $id) {
                $readable[$name . ':' . $id] = true;
            }
        }

        return $readable;
    }

    /**
     * @param   float[]  $embedding  Of the question
     * @param   int[]    $exclude    IDs of passages that were found before
     *
     * @return  array<int, object{id: int, document_id: int, item_id: int, source: string, title: string, link: string, heading: string, text: string, score: float}>
     *          Best first
     */
    public function search(array $embedding, string $language, array $exclude = []): array
    {
        $db       = $this->db;
        $question = Vector::unpack(Vector::pack($embedding));
        // Many more than are needed: repeated pages and the limit per page thin the candidates out
        $keep     = $this->settings->topK() * 10;
        $minimum  = $this->settings->minScore();
        $best     = [];

        foreach ($this->candidates($question, $language, array_map('intval', $exclude)) as $rows) {
            foreach ($rows as $row) {
                $vector = Vector::unpack($row->embedding);
                $score  = Vector::similarity($question, $vector);

                if ($score >= $minimum) {
                    $best[(int) $row->id] = ['score' => $score, 'document' => (int) $row->document_id, 'vector' => $vector];
                }
            }

            // Only the leaders are worth remembering
            if (\count($best) > $keep * 2) {
                uasort($best, static fn ($a, $b) => $b['score'] <=> $a['score']);
                $best = \array_slice($best, 0, $keep, true);
            }
        }

        uasort($best, static fn ($a, $b) => $b['score'] <=> $a['score']);

        $chosen = [];
        $used   = [];
        $copyOf = [];

        foreach ($best as $id => $candidate) {
            if (\count($chosen) >= $this->settings->topK()) {
                break;
            }

            foreach ($chosen as $other => $score) {
                if (Vector::similarity($candidate['vector'], $best[$other]['vector']) >= self::SAME_TEXT) {
                    // A page that repeats another counts as that page from here on, and is shown as it
                    $copyOf[$candidate['document']] ??= $copyOf[$best[$other]['document']] ?? $best[$other]['document'];

                    continue 2;
                }
            }

            $document = $copyOf[$candidate['document']] ?? $candidate['document'];

            if (($used[$document] ?? 0) < self::PER_DOCUMENT) {
                $used[$document] = ($used[$document] ?? 0) + 1;
                $chosen[$id]     = $candidate['score'];
            }
        }

        if (!$chosen) {
            return [];
        }

        $query = $db->getQuery(true)
            ->select('c.id, c.document_id, c.heading, c.text, d.item_id, d.source, d.title, d.link')
            ->from($db->quoteName('#__mcpask_chunks', 'c'))
            ->join('INNER', $db->quoteName('#__mcpask_documents', 'd'), 'd.id = c.document_id')
            ->whereIn('c.id', array_keys($chosen));
        $rows  = $db->setQuery($query)->loadObjectList('id');

        // The index may be behind this site: a page unpublished a minute ago must not be quoted.
        // Pages of other sites are as current as the last time they were read.
        $visible = $this->readable($rows);
        $found   = [];

        foreach ($chosen as $id => $score) {
            if (isset($rows[$id]) && (Sources::isElsewhere($rows[$id]->source) || isset($visible[$rows[$id]->source . ':' . $rows[$id]->item_id]))) {
                $rows[$id]->score       = $score;
                $rows[$id]->document_id = $copyOf[(int) $rows[$id]->document_id] ?? (int) $rows[$id]->document_id;
                $found[]                = $rows[$id];
            }
        }

        return $found;
    }

    /**
     * The passages to compare exactly, in batches: a shortlist made by the database, or all of them.
     *
     * @param   array<int, float>  $question  Unpacked embedding
     * @param   int[]              $exclude
     *
     * @return  iterable<int, object[]>
     */
    private function candidates(array $question, string $language, array $exclude): iterable
    {
        $db = $this->db;

        if ($this->twoPasses && $this->coded()) {
            $distance = [];

            foreach (Vector::code($question) as $word => $bits) {
                $distance[] = 'BIT_COUNT(c.b' . $word . ' ^ ' . $bits . ')';
            }

            $total = (int) $db->setQuery(
                $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__mcpask_chunks'))
            )->loadResult();
            $query = $this->passages()
                ->order(implode(' + ', $distance))
                ->setLimit(max(self::SHORTLIST, $this->settings->topK() * self::SHORTLIST_FACTOR, (int) ceil($total / self::SHORTLIST_PART)));

            $this->limit($query, $language);

            if ($exclude) {
                $query->whereNotIn('c.id', $exclude);
            }

            yield $db->setQuery($query)->loadObjectList();

            return;
        }

        $exclude = array_flip($exclude);
        $after   = 0;

        do {
            $query = $this->passages()->where('c.id > ' . $after)->order('c.id')->setLimit(self::PAGE);

            $this->limit($query, $language);

            $rows  = $db->setQuery($query)->loadObjectList();
            $after = $rows ? (int) end($rows)->id : $after;

            yield array_filter($rows, static fn ($row) => !isset($exclude[(int) $row->id]));
        } while (\count($rows) === self::PAGE);
    }

    private function passages(): QueryInterface
    {
        return $this->db->getQuery(true)
            ->select('c.id, c.document_id, c.embedding')
            ->from($this->db->quoteName('#__mcpask_chunks', 'c'))
            ->join('INNER', $this->db->quoteName('#__mcpask_documents', 'd'), 'd.id = c.document_id');
    }

    /**
     * What may be searched: this site's current source and the sites it reads, in the visitor's
     * language, at the view levels of a visitor who is not signed in.
     */
    private function limit(QueryInterface $query, string $language): void
    {
        $levels  = array_values(array_unique(array_map('intval', Access::getAuthorisedViewLevels(0)))) ?: [1];
        $sources = [...array_keys($this->own), ...Sources::elsewhere($this->settings)];

        $query->whereIn('d.source', $sources, ParameterType::STRING)
            ->where('d.access IN (' . implode(',', $levels) . ')')
            ->whereIn('d.language', [$language, '*'], ParameterType::STRING);
    }

    /**
     * Whether every passage has its bit code, so the database can make the shortlist.
     */
    private function coded(): bool
    {
        if (!Indexer::coded($this->db)) {
            return false;
        }

        $query = $this->db->getQuery(true)
            ->select('1')
            ->from($this->db->quoteName('#__mcpask_chunks'))
            ->where('coded = 0')
            ->setLimit(1);

        return !$this->db->setQuery($query)->loadResult();
    }

    /**
     * The indexed page of this site for a content item, when a guest may read it.
     *
     * @return  object{id: int, item_id: int, source: string, title: string, link: string}|null
     */
    public function locate(int $itemId): ?object
    {
        if ($itemId < 1 || !$this->source->visible([$itemId])) {
            return null;
        }

        $name  = $this->source->name();
        $query = $this->db->getQuery(true)
            ->select('id, item_id, source, title, link')
            ->from($this->db->quoteName('#__mcpask_documents'))
            ->where('source = :source')
            ->where('item_id = ' . $itemId)
            ->bind(':source', $name);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    /**
     * A page of a peer site or website being read, by its address: where a visitor of Ask the Site
     * embedded on that site is (0.11.0). The address is compared without its fragment, and with and
     * without a closing slash.
     *
     * @since  0.11.0
     */
    public function locateUrl(string $url): ?object
    {
        $url       = preg_replace('/#.*$/', '', trim($url)) ?? '';
        $elsewhere = Sources::elsewhere($this->settings);

        if (!preg_match('#^https?://#i', $url) || !$elsewhere) {
            return null;
        }

        $variants = array_values(array_unique([$url, rtrim($url, '/'), rtrim($url, '/') . '/']));
        $query    = $this->db->getQuery(true)
            ->select('id, item_id, source, title, link')
            ->from($this->db->quoteName('#__mcpask_documents'))
            ->whereIn($this->db->quoteName('source'), $elsewhere, \Joomla\Database\ParameterType::STRING)
            ->whereIn($this->db->quoteName('link'), $variants, \Joomla\Database\ParameterType::STRING);

        return $this->db->setQuery($query, 0, 1)->loadObject() ?: null;
    }

    /**
     * The passages of one page that are closest to a question, however close that is.
     *
     * @param   float[]  $embedding
     * @param   int[]    $exclude
     *
     * @return  object[]  As search() returns them
     */
    public function within(object $page, array $embedding, int $count, array $exclude = []): array
    {
        $question = Vector::unpack(Vector::pack($embedding));
        $query    = $this->db->getQuery(true)
            ->select('id, heading, text, embedding')
            ->from($this->db->quoteName('#__mcpask_chunks'))
            ->where('document_id = ' . (int) $page->id);
        $rows     = array_filter($this->db->setQuery($query)->loadObjectList(), static fn ($row) => !\in_array((int) $row->id, $exclude, true));

        foreach ($rows as $row) {
            $row->score       = Vector::similarity($question, Vector::unpack($row->embedding));
            $row->document_id = (int) $page->id;
            $row->item_id     = (int) $page->item_id;
            $row->source      = $page->source;
            $row->title       = $page->title;
            $row->link        = $page->link;

            unset($row->embedding);
        }

        usort($rows, static fn ($a, $b) => $b->score <=> $a->score);

        return \array_slice($rows, 0, $count);
    }

    /**
     * The administrator's source rules for questions that mean much the same as this one.
     *
     * @param   float[]  $embedding
     *
     * @return  object[]
     */
    public function hints(array $embedding, string $language): array
    {
        return (new Hints($this->db, $this->settings))->match($embedding, $language);
    }

    /**
     * A stored page by where it came from, when a guest may still read it.
     *
     * @return  object{id: int, item_id: int, source: string, title: string, link: string}|null
     */
    public function document(string $source, int $itemId): ?object
    {
        $query = $this->db->getQuery(true)
            ->select('id, item_id, source, title, link')
            ->from($this->db->quoteName('#__mcpask_documents'))
            ->where('source = :source')
            ->where('item_id = ' . $itemId)
            ->where('status = ' . $this->db->quote('ok'))
            ->bind(':source', $source);
        $row   = $this->db->setQuery($query)->loadObject();

        if (!$row || !$this->usable($row)) {
            return null;
        }

        return $row;
    }

    /**
     * Stored pages whose title holds these words, for the administrator to choose from.
     *
     * @return  array<int, array{source: string, item_id: int, title: string, url: string, site: string}>
     */
    public function lookup(string $words, int $limit = 20): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('item_id, source, title, link')
            ->from($db->quoteName('#__mcpask_documents'))
            ->where('status = ' . $db->quote('ok'))
            ->whereIn('source', [...array_keys($this->own), ...Sources::elsewhere($this->settings)], ParameterType::STRING)
            ->setLimit($limit);

        // The page that is called just that first, then the shortest titles: a product page before
        // the many announcements that mention it
        $exact = trim($words);
        $query->order('CASE WHEN title = :exact THEN 0 ELSE 1 END, LENGTH(title), title')->bind(':exact', $exact);

        // Bound by reference, so each word keeps its own variable
        $likes = [];

        foreach (array_values(array_filter(preg_split('/\s+/u', trim($words)) ?: [])) as $number => $word) {
            $likes[$number] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word) . '%';
            $query->where('title LIKE :word' . $number)->bind(':word' . $number, $likes[$number]);
        }

        return array_map(fn ($row) => [
            'source'  => $row->source,
            'item_id' => (int) $row->item_id,
            'title'   => $row->title,
            'url'     => $this->url($row),
            'site'    => Sources::siteName($this->settings, $row->source),
        ], $db->setQuery($query)->loadObjectList());
    }

    /** A passage under a step heading ("步驟 3：…", "Step 3:"): the page is a step-by-step guide. */
    public const STEP_HEADING = '/(?:^|›\s*)(?:步驟|Step)\s*\d+\s*[:：]/iu';

    public static function isStep(string $heading): bool
    {
        return (bool) preg_match(self::STEP_HEADING, $heading);
    }

    /**
     * All passages of a page in reading order, as search() returns passages: for a guide, whose
     * steps make sense only all together and in their order.
     *
     * @return  object[]
     */
    public function inOrder(object $page, int $max): array
    {
        if (!$this->usable($page)) {
            return [];
        }

        $query = $this->db->getQuery(true)
            ->select('id, heading, text')
            ->from($this->db->quoteName('#__mcpask_chunks'))
            ->where('document_id = ' . (int) $page->document_id)
            ->order('position')
            ->setLimit($max);
        $rows  = $this->db->setQuery($query)->loadObjectList();

        foreach ($rows as $row) {
            $row->document_id = (int) $page->document_id;
            $row->item_id     = (int) $page->item_id;
            $row->source      = $page->source;
            $row->title       = $page->title;
            $row->link        = $page->link;
            $row->score       = (float) ($page->score ?? 0);
        }

        return $rows;
    }

    /**
     * The whole text of an indexed page, section by section, for when a passage is not enough.
     */
    public function page(int $documentId, int $maxCharacters): string
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('c.heading, c.text, d.item_id, d.source')
            ->from($db->quoteName('#__mcpask_chunks', 'c'))
            ->join('INNER', $db->quoteName('#__mcpask_documents', 'd'), 'd.id = c.document_id')
            ->where('c.document_id = ' . $documentId)
            ->order('c.position');
        $rows  = $db->setQuery($query)->loadObjectList();

        if (!$rows || !$this->usable($rows[0])) {
            return '';
        }

        $text    = '';
        $heading = null;

        foreach ($rows as $row) {
            if ($row->heading !== $heading && $row->heading !== '') {
                $text .= '## ' . $row->heading . "\n\n";
            }

            $heading = $row->heading;
            $text   .= $row->text . "\n\n";
        }

        return mb_substr(trim($text), 0, $maxCharacters);
    }
}
