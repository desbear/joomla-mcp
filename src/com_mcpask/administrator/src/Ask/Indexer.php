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
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Keeps the index in step with the site: one row per page in #__mcpask_documents, and its
 * passages with their embeddings in #__mcpask_chunks.
 *
 * @since  0.1.0
 */
final class Indexer
{
    /** Passages sent to the embeddings service in one request. */
    private const EMBED_BATCH = 32;

    /** Even an unchanged page is looked at again after this many days. */
    private const RECHECK_DAYS = 30;

    public function __construct(private DatabaseInterface $db, private Settings $settings)
    {
    }

    /**
     * Index a page, unless nothing that matters has changed since the last time.
     *
     * @return  string  "indexed", "unchanged" or "empty" (a page without text is not kept)
     *
     * @throws  ServiceException  When the embeddings could not be made; the page is marked "pending"
     */
    public function index(Document $document): string
    {
        // Images are kept only of the site's own pages, never of other websites
        $own    = !str_starts_with($document->source, WebSync::PREFIX) && !str_starts_with($document->source, PeerSync::PREFIX);
        $chunks = Chunker::chunks($document->html, $this->settings->chunkTokens(), $own ? ['folder' => JPATH_ROOT, 'url' => $this->siteUrl()] : null);
        $hash   = sha1(json_encode([
            $document->title, $document->link, $document->language, $document->access,
            $chunks, $this->settings->indexSignature(),
        ], JSON_INVALID_UTF8_SUBSTITUTE));
        $row    = $this->row($document->source, $document->itemId);

        if (!$chunks) {
            $this->remove($document->source, $document->itemId);

            return 'empty';
        }

        if ($row && $row->hash === $hash && $row->status === 'ok') {
            $this->touch((int) $row->id);

            return 'unchanged';
        }

        try {
            $vectors = [];

            foreach (array_chunk($chunks, self::EMBED_BATCH) as $batch) {
                // The title and the section give a short passage the context it was written in
                $inputs  = array_map(static fn ($chunk) => trim($document->title . "\n" . $chunk['heading'] . "\n" . Chunker::words($chunk['text'])), $batch);
                $vectors = array_merge($vectors, $this->settings->client()->embeddings(
                    $this->settings->embeddingModel(),
                    $inputs,
                    $this->settings->embeddingDimensions()
                ));
            }
        } catch (ServiceException $e) {
            $this->shelve($row, $document, mb_substr($e->getMessage(), 0, 250));

            throw $e;
        }

        $this->store($document, $hash, $chunks, $vectors);

        return 'indexed';
    }

    /**
     * The site's address, for images given with it. A request knows it; a scheduled task run from
     * the command line does not, and uses the one the last request remembered. Empty when no
     * request has been seen yet: then only images given as paths are kept.
     */
    private function siteUrl(): string
    {
        static $url = null;

        if ($url !== null) {
            return $url;
        }

        $state = new State($this->db);

        if (!str_starts_with(PHP_SAPI, 'cli')) {
            try {
                $url = rtrim(Uri::root(), '/') . '/';

                if (($state->get('site:url')['url'] ?? '') !== $url) {
                    $state->set('site:url', ['url' => $url]);
                }

                return $url;
            } catch (\Throwable) {
                // Fall back on what was remembered
            }
        }

        return $url = (string) ($state->get('site:url')['url'] ?? '');
    }

    /**
     * Keep a page with its passages and their embeddings, replacing what was stored of it.
     *
     * @param  array<int, array{heading: string, text: string}>  $chunks
     * @param  array<int, float[]>                               $vectors  One per passage
     */
    public function store(Document $document, string $hash, array $chunks, array $vectors): void
    {
        $row = $this->row($document->source, $document->itemId);

        $this->db->transactionStart();

        try {
            $id = $this->save($row, $document, ['status' => 'ok', 'message' => '', 'hash' => $hash, 'chunks' => \count($chunks)]);

            $this->deleteChunks($id);

            foreach (array_values($chunks) as $position => $chunk) {
                $packed = Vector::pack($vectors[$position]);
                $record = [
                    'document_id' => $id,
                    'position'    => $position,
                    'heading'     => mb_substr($chunk['heading'], 0, 255),
                    'text'        => $chunk['text'],
                    'embedding'   => $packed,
                ];

                $record = (object) ($record + $this->code($packed));

                $this->db->insertObject('#__mcpask_chunks', $record);
            }

            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->db->transactionRollback();

            throw $e;
        }
    }

    /**
     * Whether the database can do the first pass of a search itself (see Retriever).
     */
    public static function coded(DatabaseInterface $db): bool
    {
        return $db->getServerType() === 'mysql';
    }

    /**
     * The bit code columns of a passage, where the database has them.
     *
     * @return  array<string, string|int>
     */
    private function code(string $packed): array
    {
        if (!self::coded($this->db)) {
            return [];
        }

        $columns = ['coded' => 1];

        foreach (Vector::code(Vector::unpack($packed)) as $word => $bits) {
            $columns['b' . $word] = $bits;
        }

        return $columns;
    }

    /**
     * Give passages stored before version 0.2.0 their bit codes.
     *
     * @return  int  Passages still without one
     */
    public function encode(int $limit): int
    {
        if (!self::coded($this->db)) {
            return 0;
        }

        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('id, embedding')
            ->from($db->quoteName('#__mcpask_chunks'))
            ->where('coded = 0')
            ->setLimit($limit);

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $record = (object) (['id' => (int) $row->id] + $this->code($row->embedding));

            $db->updateObject('#__mcpask_chunks', $record, 'id');
        }

        return (int) $db->setQuery(
            $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__mcpask_chunks'))->where('coded = 0')
        )->loadResult();
    }

    /**
     * Index a page again later (the scheduled task does).
     */
    public function defer(Document $document): void
    {
        $this->shelve($this->row($document->source, $document->itemId), $document, '');
    }

    /**
     * Mark a page as waiting to be indexed. What was stored of it is dropped: the page has changed,
     * and text an editor has just taken out must not be quoted until the index catches up.
     */
    private function shelve(?object $row, Document $document, string $message): void
    {
        $id = $this->save($row, $document, ['status' => 'pending', 'message' => $message, 'hash' => '', 'chunks' => 0]);

        $this->deleteChunks($id);
    }

    public function remove(string $source, int $itemId): void
    {
        if ($row = $this->row($source, $itemId)) {
            $this->deleteChunks((int) $row->id);
            $this->db->setQuery(
                $this->db->getQuery(true)->delete($this->db->quoteName('#__mcpask_documents'))->where('id = ' . (int) $row->id)
            )->execute();
        }
    }

    /**
     * Work through the pages of a source in order of ID; the administrator's "synchronise" button
     * calls this again and again with the last ID it got back.
     *
     * @return  array{last: int, done: bool, indexed: int, unchanged: int, empty: int, failed: int, error: string}
     */
    public function sync(SourceInterface $source, int $afterId, int $limit): array
    {
        $result = ['last' => $afterId, 'done' => false, 'indexed' => 0, 'unchanged' => 0, 'empty' => 0, 'failed' => 0, 'error' => ''];
        $ids    = $source->ids($afterId, $limit);

        foreach ($ids as $id) {
            try {
                $document = $source->load($id);
                $result[$document ? $this->index($document) : 'empty']++;
            } catch (ServiceException $e) {
                // The service is down or misconfigured: the next page would fail the same way
                $result['failed']++;
                $result['error'] = $e->getMessage();

                return $result;
            }

            $result['last'] = $id;
        }

        // The caller prunes once every source is done
        $result['done'] = \count($ids) < $limit;

        return $result;
    }

    /**
     * What the scheduled task does: new and deferred pages first, then the pages that have not been
     * looked at for the longest time, until the time is up. The caller prunes first.
     *
     * @return  array{indexed: int, unchanged: int, empty: int, fresh: int, error: string}
     */
    public function refresh(SourceInterface $source, int $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        $result   = ['indexed' => 0, 'unchanged' => 0, 'empty' => 0, 'fresh' => 0, 'error' => ''];
        $db       = $this->db;
        $name     = $source->name();

        $query = $db->getQuery(true)
            ->select('item_id, status, checked')
            ->from($db->quoteName('#__mcpask_documents'))
            ->where('source = :source')
            ->order('checked')
            ->bind(':source', $name);
        $known = $db->setQuery($query)->loadAssocList('item_id');

        // New pages and pages that failed first, then indexed pages that may have changed, oldest first
        $current = array_keys(array_filter($known, static fn ($row) => $row['status'] === 'ok'));
        $changed = $this->changed($source, $current, $known);
        $queue   = array_merge(array_diff($source->ids(), array_keys($known)), array_diff(array_keys($known), $current), $changed);

        // What has not changed since it was looked at is not read at all
        $result['fresh'] = \count($current) - \count($changed);

        foreach ($queue as $id) {
            if (microtime(true) > $deadline) {
                break;
            }

            try {
                $document = $source->load((int) $id);
                $result[$document ? $this->index($document) : 'empty']++;
            } catch (ServiceException $e) {
                $result['error'] = $e->getMessage();

                break;
            }
        }

        return $result;
    }

    /**
     * Of the pages that are indexed, those that may have changed since they were looked at: changed
     * in the database since then, looked at under other index settings, or not looked at for a
     * month (what a page shows can change without its date: a field, the access of its category).
     * Oldest first, as given.
     *
     * @param   int[]                                   $ids
     * @param   array<int, array{checked: string}>      $known  By ID
     *
     * @return  int[]
     */
    private function changed(SourceInterface $source, array $ids, array $known): array
    {
        $state     = new State($this->db);
        $signature = $this->settings->indexSignature() . '|' . $this->fieldsSignature() . '|passages:' . Chunker::FORMAT;
        $since     = $state->get('index:signature');

        // Pages looked at under other settings are all looked at again
        if (($since['signature'] ?? null) !== $signature) {
            $since = ['signature' => $signature, 'since' => Factory::getDate()->toSql()];
            $state->set('index:signature', $since);
        }

        $month    = Factory::getDate('-' . self::RECHECK_DAYS . ' days')->toSql();
        $modified = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            $modified += $source->modified($chunk);
        }

        return array_values(array_filter($ids, static function ($id) use ($known, $modified, $since, $month) {
            $checked = (string) $known[$id]['checked'];

            return $checked <= $since['since'] || $checked < $month || !isset($modified[$id]) || $modified[$id] > $checked;
        }));
    }

    /**
     * What decides which custom fields a visitor sees (their state, access, groups, last change),
     * condensed: a field that is hidden or made private changes what pages show without changing
     * the pages, so every page is looked at again when this changes.
     */
    private function fieldsSignature(): string
    {
        if (!$this->settings->includeFields()) {
            return '';
        }

        $db    = $this->db;
        $parts = [];
        $tables = [
            '#__fields'                            => 'COUNT(*), SUM(state), SUM(access), MAX(modified_time)',
            '#__fields_groups'                     => 'COUNT(*), SUM(state), SUM(access), MAX(modified)',
            '#__fields_categories'                 => 'COUNT(*), SUM(field_id + category_id)',
            '#__flexicontent_fields'               => 'COUNT(*), SUM(published), SUM(access), SUM(id * access)',
            '#__flexicontent_fields_type_relations' => 'COUNT(*), SUM(field_id + type_id)',
        ];

        foreach ($tables as $table => $measures) {
            try {
                $parts[] = implode(',', (array) $db->setQuery($db->getQuery(true)->select($measures)->from($db->quoteName($table)))->loadRow());
            } catch (\Throwable) {
                // That kind of field is not on this site
                $parts[] = '-';
            }
        }

        return sha1(implode('|', $parts));
    }

    /**
     * Forget pages of this site that are gone, no longer public, or came from a source that is no
     * longer answered from. Pages of other sites are looked after by PeerSync and WebSync.
     *
     * @param   array<string, SourceInterface>  $sources  The site's own sources, by name
     *
     * @return  int  Number of pages removed
     */
    public function prune(array $sources): int
    {
        $db      = $this->db;
        $query   = $db->getQuery(true)
            ->select('id, source, item_id')
            ->from($db->quoteName('#__mcpask_documents'))
            ->where('source NOT LIKE ' . $db->quote(PeerSync::PREFIX . '%'))
            ->where('source NOT LIKE ' . $db->quote(WebSync::PREFIX . '%'));
        $visible = array_map(static fn ($source) => array_flip($source->ids()), $sources);
        $removed = 0;

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            if (!isset($visible[$row->source][(int) $row->item_id])) {
                $this->remove($row->source, (int) $row->item_id);
                $removed++;
            }
        }

        return $removed;
    }

    public function clear(): void
    {
        foreach (['#__mcpask_chunks', '#__mcpask_documents'] as $table) {
            $this->db->setQuery($this->db->getQuery(true)->delete($this->db->quoteName($table)))->execute();
        }
    }

    /**
     * Figures for the dashboard; the pages are this site's own.
     *
     * @return  array{documents: int, pending: int, chunks: int, checked: ?string, message: string}
     */
    public function stats(): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('COUNT(*) AS documents, SUM(CASE WHEN status <> ' . $db->quote('ok') . ' THEN 1 ELSE 0 END) AS pending, MAX(checked) AS checked')
            ->from($db->quoteName('#__mcpask_documents'))
            ->where('source NOT LIKE ' . $db->quote(PeerSync::PREFIX . '%'))
            ->where('source NOT LIKE ' . $db->quote(WebSync::PREFIX . '%'));
        $row   = $db->setQuery($query)->loadObject();

        $chunks  = $db->setQuery($db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__mcpask_chunks')))->loadResult();
        $message = $db->setQuery(
            $db->getQuery(true)
                ->select('message')
                ->from($db->quoteName('#__mcpask_documents'))
                ->where('message <> ' . $db->quote(''))
                ->order('checked DESC')
                ->setLimit(1)
        )->loadResult();

        return [
            'documents' => (int) ($row->documents ?? 0),
            'pending'   => (int) ($row->pending ?? 0),
            'chunks'    => (int) $chunks,
            'checked'   => $row->checked ?? null,
            'message'   => (string) $message,
        ];
    }

    private function row(string $source, int $itemId): ?object
    {
        $query = $this->db->getQuery(true)
            ->select('id, hash, status')
            ->from($this->db->quoteName('#__mcpask_documents'))
            ->where('source = :source')
            ->where('item_id = :item')
            ->bind(':source', $source)
            ->bind(':item', $itemId, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    /**
     * @return  int  ID of the document row
     */
    private function save(?object $row, Document $document, array $values): int
    {
        $record = (object) array_merge([
            'source'   => $document->source,
            'item_id'  => $document->itemId,
            'title'    => mb_substr($document->title, 0, 255),
            'link'     => $document->link,
            'language' => $document->language,
            'access'   => $document->access,
            'checked'  => Factory::getDate()->toSql(),
        ], $values);

        if ($row) {
            $record->id = (int) $row->id;
            $this->db->updateObject('#__mcpask_documents', $record, 'id');

            return $record->id;
        }

        $this->db->insertObject('#__mcpask_documents', $record, 'id');

        return (int) $record->id;
    }

    private function touch(int $id): void
    {
        $record = (object) ['id' => $id, 'checked' => Factory::getDate()->toSql()];

        $this->db->updateObject('#__mcpask_documents', $record, 'id');
    }

    private function deleteChunks(int $documentId): void
    {
        $this->db->setQuery(
            $this->db->getQuery(true)->delete($this->db->quoteName('#__mcpask_chunks'))->where('document_id = ' . $documentId)
        )->execute();
    }
}
