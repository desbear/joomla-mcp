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
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Keeps a copy of what other sites offer of their index, so questions can be answered from
 * their public content as well.
 *
 * The other site lists its pages with a hash each; what is new or has changed is fetched, what
 * is gone is removed. Nothing is remembered between runs, so a run that was cut short simply
 * continues the next time. Everything received is treated as it would be from a stranger.
 *
 * @since  0.2.0
 */
final class PeerSync
{
    /** Pages of other sites are filed under this prefix and the ID of the site. */
    public const PREFIX = 'peer:';

    /** Pages taken from one other site at most. */
    private const MAX_PAGES = 5000;

    /** Pages asked for in one request. */
    private const BATCH = 20;

    private const MAX_CHUNKS = 300;

    private const MAX_TEXT = 8000;

    public function __construct(private DatabaseInterface $db, private Settings $settings)
    {
    }

    public static function isPeer(string $source): bool
    {
        return str_starts_with($source, self::PREFIX);
    }

    /**
     * Read the other sites until everything is up to date or the time is up.
     *
     * @return  array<string, array{name: string, total: int, stored: int, imported: int, removed: int, remaining: int, error: string}>  By site ID
     */
    public function run(int $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        $state    = new State($this->db);
        $report   = [];

        $this->forget();

        foreach ($this->settings->peers() as $id => $peer) {
            $result = ['name' => $peer['name'], 'total' => 0, 'stored' => 0, 'imported' => 0, 'removed' => 0, 'remaining' => 0, 'error' => ''];

            try {
                $result = $this->read($peer, $deadline) + $result;
            } catch (ServiceException $e) {
                $result['error'] = $e->getMessage();
            }

            $result['stored'] = \count($this->stored($id));
            $report[$id]      = $result;

            $state->set(self::PREFIX . $id, ['synced' => Factory::getDate()->toSql(), 'error' => $result['error'], 'remaining' => $result['remaining']]);
        }

        return $report;
    }

    /**
     * What the dashboard shows about each site.
     *
     * @return  array<string, array{name: string, url: string, stored: int, synced: ?string, error: string, remaining: int}>
     */
    public function status(): array
    {
        $state  = new State($this->db);
        $status = [];

        foreach ($this->settings->peers() as $id => $peer) {
            $last        = $state->get(self::PREFIX . $id);
            $status[$id] = [
                'name'      => $peer['name'],
                'url'       => $peer['url'],
                'stored'    => \count($this->stored($id)),
                'synced'    => $last['synced'] ?? null,
                'error'     => (string) ($last['error'] ?? ''),
                'remaining' => (int) ($last['remaining'] ?? 0),
            ];
        }

        return $status;
    }

    /**
     * @param  array{id: string, name: string, url: string, key: string}  $peer
     */
    private function read(array $peer, float $deadline): array
    {
        $client  = new PeerClient($peer);
        $indexer = new Indexer($this->db, $this->settings);
        $source  = self::PREFIX . $peer['id'];
        $remote  = $client->pages();
        // However many pages another site lists, only so many are taken (and paid to embed)
        $remote['pages'] = \array_slice((array) $remote['pages'], 0, self::MAX_PAGES, true);
        $stored  = $this->stored($peer['id']);
        $result  = ['total' => \count($remote['pages']), 'imported' => 0, 'removed' => 0, 'remaining' => 0];

        foreach (array_diff_key($stored, $remote['pages']) as $itemId => $hash) {
            $indexer->remove($source, (int) $itemId);
            $result['removed']++;
        }

        // Embeddings can be used as they are when they were made the same way as this site's;
        // otherwise only the text is taken and embedded here
        $same  = $remote['model'] === $this->settings->embeddingModel() && $remote['dimensions'] === $this->dimensions();
        $mark  = $same ? '' : '|' . $this->settings->embeddingModel() . '|' . $this->settings->embeddingDimensions();
        $fetch = [];

        foreach ($remote['pages'] as $itemId => $hash) {
            if (($stored[$itemId] ?? null) !== sha1($hash . $mark)) {
                $fetch[] = $itemId;
            }
        }

        $host = strtolower((string) parse_url($peer['url'], PHP_URL_HOST));

        foreach (array_chunk($fetch, self::BATCH) as $number => $ids) {
            if (microtime(true) > $deadline) {
                $result['remaining'] = \count($fetch) - $number * self::BATCH;

                break;
            }

            foreach ($client->documents($ids) as $page) {
                $itemId = (int) ($page['id'] ?? 0);

                if (!\in_array($itemId, $ids, true) || !isset($remote['pages'][$itemId])) {
                    continue;
                }

                if ($this->keep($indexer, $source, $itemId, $page, sha1($remote['pages'][$itemId] . $mark), $host, $same)) {
                    $result['imported']++;
                }
            }
        }

        return $result;
    }

    /**
     * Check a page received from another site and store it. What does not look right is left out.
     */
    private function keep(Indexer $indexer, string $source, int $itemId, array $page, string $hash, string $host, bool $same): bool
    {
        $url   = trim((string) ($page['url'] ?? ''));
        $title = trim(strip_tags((string) ($page['title'] ?? '')));

        // A site may only send visitors to itself
        if ($title === '' || !preg_match('#^https?://#i', $url) || strtolower((string) parse_url($url, PHP_URL_HOST)) !== $host) {
            return false;
        }

        $language = (string) ($page['language'] ?? '*');
        $chunks   = [];
        $vectors  = [];
        $length   = $this->dimensions();

        foreach (\array_slice((array) ($page['chunks'] ?? []), 0, self::MAX_CHUNKS) as $chunk) {
            // An image of another site is a path on that site: it is not shown here
            $text = \is_array($chunk) ? trim((string) preg_replace(Chunker::IMAGE, '', strip_tags((string) ($chunk['text'] ?? '')))) : '';

            if ($text === '') {
                continue;
            }

            $vector = $same ? Vector::unpack((string) ($chunk['embedding'] ?? '')) : [];

            if ($same && ($length > 0 ? \count($vector) !== $length : !$vector)) {
                return false;
            }

            $chunks[]  = ['heading' => mb_substr(trim(strip_tags((string) ($chunk['heading'] ?? ''))), 0, 250), 'text' => mb_substr($text, 0, self::MAX_TEXT)];
            $vectors[] = array_values($vector);
        }

        if (!$chunks) {
            return false;
        }

        if (!$same) {
            $vectors = [];

            foreach (array_chunk($chunks, 32) as $batch) {
                $vectors = array_merge($vectors, $this->settings->client()->embeddings(
                    $this->settings->embeddingModel(),
                    array_map(static fn ($chunk) => trim($title . "\n" . $chunk['heading'] . "\n" . Chunker::words($chunk['text'])), $batch),
                    $this->settings->embeddingDimensions()
                ));
            }
        }

        // What another site offers is public there; here it is filed as public as well
        $document = new Document($source, $itemId, mb_substr($title, 0, 255), mb_substr($url, 0, 1000), preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $language) ? $language : '*', 1, '');

        $indexer->store($document, $hash, $chunks, $vectors);

        return true;
    }

    /**
     * The length of this site's embeddings, from the option or from what is stored.
     */
    private function dimensions(): int
    {
        static $length = null;

        if ($this->settings->embeddingDimensions() > 0) {
            return $this->settings->embeddingDimensions();
        }

        if ($length === null) {
            $query  = $this->db->getQuery(true)
                ->select('c.embedding')
                ->from($this->db->quoteName('#__mcpask_chunks', 'c'))
                ->join('INNER', $this->db->quoteName('#__mcpask_documents', 'd'), 'd.id = c.document_id')
                ->where('d.source NOT LIKE ' . $this->db->quote(self::PREFIX . '%'))
                ->setLimit(1);
            $length = \count(Vector::unpack((string) $this->db->setQuery($query)->loadResult()));
        }

        return $length;
    }

    /**
     * @return  array<int, string>  Hash by page ID, of what is stored of a site
     */
    private function stored(string $peerId): array
    {
        $source = self::PREFIX . $peerId;
        $query  = $this->db->getQuery(true)
            ->select('item_id, hash')
            ->from($this->db->quoteName('#__mcpask_documents'))
            ->where('source = :source')
            ->bind(':source', $source);

        return $this->db->setQuery($query)->loadAssocList('item_id', 'hash');
    }

    /**
     * Drop the pages of sites that are no longer read.
     */
    private function forget(): void
    {
        $db      = $this->db;
        $current = array_map(static fn ($id) => self::PREFIX . $id, array_keys($this->settings->peers()));
        $query   = $db->getQuery(true)
            ->select('id, source')
            ->from($db->quoteName('#__mcpask_documents'))
            ->where('source LIKE ' . $db->quote(self::PREFIX . '%'));

        $gone = array_filter($db->setQuery($query)->loadObjectList(), static fn ($row) => !\in_array($row->source, $current, true));

        foreach (array_chunk(array_map(static fn ($row) => (int) $row->id, $gone), 200) as $ids) {
            $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__mcpask_chunks'))->whereIn('document_id', $ids))->execute();
            $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__mcpask_documents'))->whereIn('id', $ids))->execute();
        }
    }
}
