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
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Sources the administrator points questions to. A rule holds a question, the pages it should be
 * answered from, and optionally a word of guidance; a visitor's question that means much the same
 * (by embedding, so other wording counts too) is answered with those pages first.
 *
 * Also what the administrator works from: the questions visitors rated badly, or that found nothing.
 *
 * @since  0.6.0
 */
final class Hints
{
    /** Pages one rule may point to. */
    public const MAX_DOCUMENTS = 5;

    public const MAX_NOTE = 1000;

    /** Other wordings one rule may hold besides its question. */
    public const MAX_VARIANTS = 10;

    public function __construct(private DatabaseInterface $db, private Settings $settings)
    {
    }

    /**
     * @return  object[]  Rules, newest first, with their documents decoded
     */
    public function all(): array
    {
        $query = $this->db->getQuery(true)
            ->select('id, question, variants, documents, note, language, state, hits, last_hit, created, modified, signature')
            ->from($this->db->quoteName('#__mcpask_hints'))
            ->order('id DESC');

        return array_map([$this, 'decode'], $this->db->setQuery($query)->loadObjectList());
    }

    public function get(int $id): ?object
    {
        $query = $this->db->getQuery(true)
            ->select('id, question, variants, documents, note, language, state, hits, last_hit, created, modified, signature')
            ->from($this->db->quoteName('#__mcpask_hints'))
            ->where('id = ' . $id);
        $row   = $this->db->setQuery($query)->loadObject();

        return $row ? $this->decode($row) : null;
    }

    /**
     * Keep a rule. The question is embedded the way the index is, so it can be compared with what
     * visitors ask.
     *
     * @param   array{id?: int, question: string, variants?: string|string[], documents: array<int, array{source: string, item_id: int}>, note?: string, language?: string, state?: int}  $data
     *
     * @return  int  The rule's ID
     *
     * @throws  ServiceException  When the question could not be embedded
     * @throws  \InvalidArgumentException  When the rule is incomplete
     */
    public function save(array $data, int $userId): int
    {
        $question  = trim(mb_substr((string) $data['question'], 0, 500));
        $documents = [];

        foreach ((array) $data['documents'] as $document) {
            $source = (string) ($document['source'] ?? '');
            $itemId = (int) ($document['item_id'] ?? 0);

            if (preg_match('/^[a-z0-9:]{1,32}$/', $source) && $itemId > 0) {
                $documents[$source . ':' . $itemId] = ['source' => $source, 'item_id' => $itemId];
            }
        }

        $documents = \array_slice(array_values($documents), 0, self::MAX_DOCUMENTS);
        $variants  = self::variants($data['variants'] ?? [], $question);
        $note      = trim(mb_substr(strip_tags((string) ($data['note'] ?? '')), 0, self::MAX_NOTE));

        if ($question === '' || (!$documents && $note === '')) {
            throw new \InvalidArgumentException('A rule needs a question and at least one page or a note.');
        }

        $now      = Factory::getDate()->toSql();
        $language = (string) ($data['language'] ?? '*');
        $record   = (object) [
            'question'  => $question,
            'variants'  => implode("\n", $variants),
            // The question and each other wording, so the closest of them counts
            'embedding' => json_encode(array_map([Vector::class, 'pack'], $this->embed([$question, ...$variants]))),
            'signature' => $this->signature(),
            'documents' => json_encode($documents),
            'note'      => $note,
            'language'  => preg_match('/^([a-z]{2,3}-[A-Z]{2}|\*)$/', $language) ? $language : '*',
            'state'     => (int) ($data['state'] ?? 1) ? 1 : 0,
            'modified'  => $now,
        ];

        if (!empty($data['id'])) {
            $record->id = (int) $data['id'];
            $this->db->updateObject('#__mcpask_hints', $record, 'id');

            return $record->id;
        }

        $record->created    = $now;
        $record->created_by = $userId;
        $record->hits       = 0;

        $this->db->insertObject('#__mcpask_hints', $record, 'id');

        return (int) $record->id;
    }

    public function delete(int $id): void
    {
        $this->db->setQuery($this->db->getQuery(true)->delete($this->db->quoteName('#__mcpask_hints'))->where('id = ' . $id))->execute();
    }

    /**
     * The rules whose question means much the same as this one, closest first.
     *
     * @param   float[]  $embedding  Of the visitor's question
     *
     * @return  object[]  With "score"
     */
    public function match(array $embedding, string $language): array
    {
        $signature = $this->signature();
        $query     = $this->db->getQuery(true)
            ->select('id, question, embedding, documents, note')
            ->from($this->db->quoteName('#__mcpask_hints'))
            ->where('state = 1')
            ->where('signature = :signature')
            ->whereIn('language', [$language, '*'], ParameterType::STRING)
            ->bind(':signature', $signature);
        $question  = Vector::unpack(Vector::pack($embedding));
        $matches   = [];

        foreach ($this->db->setQuery($query)->loadObjectList() as $row) {
            $row->score = max(array_map(static fn ($packed) => Vector::similarity($question, Vector::unpack($packed)), self::embeddings($row->embedding)));

            if ($row->score >= $this->settings->hintScore()) {
                unset($row->embedding);
                $matches[] = $this->decode($row);
            }
        }

        usort($matches, static fn ($a, $b) => $b->score <=> $a->score);

        return \array_slice($matches, 0, 3);
    }

    /**
     * Count that these rules were used.
     *
     * @param  int[]  $ids
     */
    public function hit(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids) {
            $this->db->setQuery(
                $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__mcpask_hints'))
                    ->set('hits = hits + 1')
                    ->set('last_hit = ' . $this->db->quote(Factory::getDate()->toSql()))
                    ->whereIn('id', $ids)
            )->execute();
        }
    }

    /**
     * Embed again the rules made under other index settings (another embedding model or size),
     * which cannot be compared with questions until then.
     *
     * @return  int  Rules embedded again
     */
    public function refresh(int $limit = 50): int
    {
        $signature = $this->signature();
        $query     = $this->db->getQuery(true)
            ->select('id, question, variants')
            ->from($this->db->quoteName('#__mcpask_hints'))
            ->where('signature <> :signature')
            ->bind(':signature', $signature)
            ->setLimit($limit);
        $rows      = $this->db->setQuery($query)->loadObjectList();

        foreach ($rows as $row) {
            $texts  = [$row->question, ...self::variants((string) $row->variants, $row->question)];
            $record = (object) ['id' => (int) $row->id, 'embedding' => json_encode(array_map([Vector::class, 'pack'], $this->embed($texts))), 'signature' => $signature];

            $this->db->updateObject('#__mcpask_hints', $record, 'id');
        }

        return \count($rows);
    }

    /**
     * Questions worth a look: rated badly, or not answered (nothing found, or an error), over the
     * last so many days, the same question once, most asked first. Questions a rule has been made
     * for since are left out.
     *
     * @return  object[]  question, asked, bad, empty, errors, last, reasons, log_id
     */
    public function pending(int $days, int $limit = 100): array
    {
        $db    = $this->db;
        $since = Factory::getDate('-' . $days . ' days')->toSql();
        $query = $db->getQuery(true)
            ->select('id, question, status, rating, feedback, created, hint_id')
            ->select(isset($db->getTableColumns('#__mcpask_log')['flags']) ? 'flags' : $db->quote('') . ' AS flags')
            ->from($db->quoteName('#__mcpask_log'))
            ->where('created >= :since')
            ->where('question IS NOT NULL')
            ->where('(rating < 0 OR status IN (' . $db->quote('empty') . ', ' . $db->quote('error') . '))')
            ->order('created DESC')
            ->bind(':since', $since);
        $ruled = [];

        foreach ($this->all() as $hint) {
            foreach ([$hint->question, ...self::variants((string) $hint->variants, $hint->question)] as $text) {
                $ruled[self::normal($text)] = true;
            }
        }

        $found = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $key = self::normal((string) $row->question);

            if ($key === '' || isset($ruled[$key])) {
                continue;
            }

            $item = $found[$key] ??= (object) [
                'question' => (string) $row->question,
                'log_id'   => (int) $row->id,
                'asked'    => 0,
                'bad'      => 0,
                'empty'    => 0,
                'errors'   => 0,
                'last'     => $row->created,
                'reasons'  => [],
                'flags'    => [],
            ];

            $item->flags = array_values(array_unique([...$item->flags, ...array_filter(explode(',', (string) $row->flags))]));

            $item->asked++;
            $item->bad    += (int) $row->rating < 0 ? 1 : 0;
            $item->empty  += $row->status === 'empty' ? 1 : 0;
            $item->errors += $row->status === 'error' ? 1 : 0;

            if ($row->feedback !== '' && \count($item->reasons) < 3) {
                $item->reasons[] = (string) $row->feedback;
            }
        }

        $found = array_values($found);
        usort($found, static fn ($a, $b) => [$b->bad + $b->empty, $b->asked] <=> [$a->bad + $a->empty, $a->asked]);

        return \array_slice($found, 0, $limit);
    }

    /**
     * How answers were rated over the last so many days.
     *
     * @return  array{good: int, bad: int, answered: int}
     */
    public function ratings(int $days): array
    {
        $db    = $this->db;
        $since = Factory::getDate('-' . $days . ' days')->toSql();
        $query = $db->getQuery(true)
            ->select('SUM(CASE WHEN rating > 0 THEN 1 ELSE 0 END) AS good, SUM(CASE WHEN rating < 0 THEN 1 ELSE 0 END) AS bad')
            ->select('SUM(CASE WHEN status = ' . $db->quote('ok') . ' THEN 1 ELSE 0 END) AS answered')
            ->from($db->quoteName('#__mcpask_log'))
            ->where('created >= :since')
            ->bind(':since', $since);
        $row   = $db->setQuery($query)->loadObject();

        return ['good' => (int) ($row->good ?? 0), 'bad' => (int) ($row->bad ?? 0), 'answered' => (int) ($row->answered ?? 0)];
    }

    /**
     * @param   string[]  $texts
     *
     * @return  array<int, float[]>
     */
    private function embed(array $texts): array
    {
        return $this->settings->client()->embeddings($this->settings->embeddingModel(), $texts, $this->settings->embeddingDimensions());
    }

    /**
     * The other wordings of a rule's question: one per line, each once, without the question itself.
     *
     * @param   string|string[]  $variants
     *
     * @return  string[]
     */
    private static function variants(string|array $variants, string $question): array
    {
        $lines = \is_array($variants) ? $variants : (preg_split('/\R/u', $variants) ?: []);
        $kept  = [];

        foreach ($lines as $line) {
            $line = trim(mb_substr(strip_tags((string) $line), 0, 500));

            if ($line !== '' && self::normal($line) !== self::normal($question)) {
                $kept[self::normal($line)] = $line;
            }
        }

        return \array_slice(array_values($kept), 0, self::MAX_VARIANTS);
    }

    /**
     * The stored embeddings of a rule: a list, or one (as a rule may have been stored before it had wordings).
     *
     * @return  string[]  Packed
     */
    private static function embeddings(string $stored): array
    {
        $list = json_decode($stored, true);

        return \is_array($list) ? array_values(array_filter($list, 'is_string')) : [$stored];
    }

    private function signature(): string
    {
        return $this->settings->embeddingModel() . '|' . $this->settings->embeddingDimensions();
    }

    private function decode(object $row): object
    {
        $row->documents = json_decode((string) $row->documents, true) ?: [];

        return $row;
    }

    /**
     * A question as compared with others: the same words, whatever the spacing, case and punctuation at the end.
     */
    private static function normal(string $question): string
    {
        return mb_strtolower(trim((string) preg_replace(['/\s+/u', '/[?？!！。.]+$/u'], [' ', ''], $question)));
    }
}
