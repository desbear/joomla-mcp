<?php

/**
 * @package     Guides
 * @subpackage  com_guides
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Guides\Administrator\Guide;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Guides, their steps (which several guides may share), the order of the steps in each guide,
 * and where each step was taken from.
 *
 * @since  0.1.0
 */
final class Store
{
    public const STATES = ['draft', 'review', 'published', 'stale', 'archived'];

    /** Steps one guide may have. */
    public const MAX_STEPS = 40;

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * @param   array{state?: string, search?: string}  $filters
     *
     * @return  object[]  Guides with their number of steps, newest change first
     */
    public function guides(array $filters = [], int $limit = 200): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('g.id, g.title, g.product, g.language, g.state, g.target_type, g.target_id, g.catid, g.modified, g.submitted, g.submitted_by, g.reviewed, g.gaps')
            ->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__guides_guide_steps') . ' gs WHERE gs.guide_id = g.id) AS steps')
            ->select('u.name AS submitted_name, c.title AS category')
            ->from($db->quoteName('#__guides_guides', 'g'))
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = g.submitted_by')
            ->join('LEFT', $db->quoteName('#__categories', 'c'), 'c.id = g.catid')
            ->order('g.modified DESC')
            ->setLimit($limit);

        if (\in_array($filters['state'] ?? '', self::STATES, true)) {
            $query->where('g.state = :state')->bind(':state', $filters['state']);
        }

        if (($filters['search'] ?? '') !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']) . '%';
            $query->where('(g.title LIKE :search OR g.product LIKE :search2)')->bind(':search', $like)->bind(':search2', $like);
        }

        return array_map(static function ($row) {
            $row->gaps = json_decode((string) $row->gaps, true) ?: [];

            return $row;
        }, $db->setQuery($query)->loadObjectList());
    }

    /**
     * One guide with its steps in order, each with its sources and how many guides use it.
     */
    public function guide(int $id): ?object
    {
        $db    = $this->db;
        $guide = $db->setQuery($db->getQuery(true)->select('*')->from($db->quoteName('#__guides_guides'))->where('id = ' . $id))->loadObject();

        if (!$guide) {
            return null;
        }

        $guide->tags  = array_values(array_map('intval', json_decode((string) $guide->tags, true) ?: []));
        $guide->gaps  = json_decode((string) $guide->gaps, true) ?: [];
        $guide->steps = $this->steps($id);

        return $guide;
    }

    /**
     * @return  object[]  The steps of a guide in order
     */
    public function steps(int $guideId): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('s.id, s.title, s.body, s.image, s.note, gs.ordering')
            ->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__guides_guide_steps') . ' o WHERE o.step_id = s.id) AS used')
            ->from($db->quoteName('#__guides_guide_steps', 'gs'))
            ->join('INNER', $db->quoteName('#__guides_steps', 's'), 's.id = gs.step_id')
            ->where('gs.guide_id = ' . $guideId)
            ->order('gs.ordering');
        $steps = $db->setQuery($query)->loadObjectList();

        foreach ($steps as $step) {
            $step->used    = (int) $step->used;
            $step->sources = $db->setQuery(
                $db->getQuery(true)
                    ->select('source_type, item_id, section, title, hash, changed')
                    ->from($db->quoteName('#__guides_sources'))
                    ->where('step_id = ' . (int) $step->id)
            )->loadObjectList();
        }

        return $steps;
    }

    /**
     * Keep a guide with its steps, in the order given. A guide that was published or in review
     * goes back to draft: what changed has to be reviewed again (the published article stays as
     * it is until then).
     *
     * @param   array  $data  id, title, summary, prerequisites, verify, language, product, target_type,
     *                        fc_type, catid, tags[], next_guide_id, steps[] (id, title, body, image, note, sources[])
     *
     * @return  int  The guide's ID
     */
    public function save(array $data, int $userId): int
    {
        $db    = $this->db;
        $now   = Factory::getDate()->toSql();
        $id    = (int) ($data['id'] ?? 0);
        $title = trim(mb_substr(strip_tags((string) ($data['title'] ?? '')), 0, 255));

        if ($title === '') {
            throw new \InvalidArgumentException('A guide needs a title.');
        }

        $record = (object) [
            'title'         => $title,
            'summary'       => self::plain($data['summary'] ?? '', 1000),
            'prerequisites' => self::plain($data['prerequisites'] ?? '', 2000),
            'verify'        => self::plain($data['verify'] ?? '', 1000),
            'language'      => preg_match('/^([a-z]{2,3}-[A-Z]{2}|\*)$/', (string) ($data['language'] ?? '')) ? (string) $data['language'] : '*',
            'product'       => self::plain($data['product'] ?? '', 100),
            'target_type'   => ($data['target_type'] ?? '') === 'flexicontent' ? 'flexicontent' : 'content',
            'fc_type'       => max(0, (int) ($data['fc_type'] ?? 0)),
            'catid'         => max(0, (int) ($data['catid'] ?? 0)),
            'tags'          => json_encode(array_values(array_unique(array_filter(array_map('intval', (array) ($data['tags'] ?? [])))))),
            'next_guide_id' => max(0, (int) ($data['next_guide_id'] ?? 0)),
            'modified'      => $now,
            'modified_by'   => $userId,
        ];

        if ($id) {
            $current = $this->guide($id);

            if (!$current) {
                throw new \InvalidArgumentException('There is no guide ' . $id . '.');
            }

            $record->id    = $id;
            $record->state = \in_array($current->state, ['published', 'review', 'stale'], true) ? 'draft' : $current->state;
            $db->updateObject('#__guides_guides', $record, 'id');
        } else {
            $record->state        = 'draft';
            $record->gaps         = json_encode(array_values((array) ($data['gaps'] ?? [])), JSON_UNESCAPED_UNICODE);
            $record->review_note  = '';
            $record->created      = $now;
            $record->created_by   = $userId;
            $db->insertObject('#__guides_guides', $record, 'id');
            $id = (int) $record->id;
        }

        if (isset($data['steps'])) {
            $this->saveSteps($id, \array_slice((array) $data['steps'], 0, self::MAX_STEPS), $record->language, $record->product, $userId);
        }

        return $id;
    }

    /**
     * @param  array[]  $steps
     */
    private function saveSteps(int $guideId, array $steps, string $language, string $product, int $userId): void
    {
        $db   = $this->db;
        $now  = Factory::getDate()->toSql();
        $kept = [];

        $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__guides_guide_steps'))->where('guide_id = ' . $guideId))->execute();

        foreach (array_values($steps) as $ordering => $step) {
            $title = trim(mb_substr(strip_tags((string) ($step['title'] ?? '')), 0, 255));

            if ($title === '') {
                continue;
            }

            $record = (object) [
                'title'       => $title,
                'body'        => ComponentHelper::filterText((string) ($step['body'] ?? '')),
                'image'       => self::image((string) ($step['image'] ?? '')),
                'note'        => self::plain($step['note'] ?? '', 1000),
                'modified'    => $now,
                'modified_by' => $userId,
            ];
            $stepId = (int) ($step['id'] ?? 0);

            if ($stepId && $this->stepExists($stepId)) {
                $record->id = $stepId;
                $db->updateObject('#__guides_steps', $record, 'id');
            } else {
                $record->language   = $language;
                $record->product    = $product;
                $record->created    = $now;
                $record->created_by = $userId;
                $db->insertObject('#__guides_steps', $record, 'id');
                $stepId = (int) $record->id;
            }

            // A step appears once in a guide
            if (isset($kept[$stepId])) {
                continue;
            }

            $kept[$stepId] = true;
            $link = (object) ['guide_id' => $guideId, 'step_id' => $stepId, 'ordering' => $ordering];
            $db->insertObject('#__guides_guide_steps', $link);

            if (isset($step['sources'])) {
                $this->saveSources($stepId, (array) $step['sources']);
            }
        }

        $this->dropOrphans();
    }

    /**
     * @param  array[]  $sources  source_type, item_id, section, title, hash
     */
    private function saveSources(int $stepId, array $sources): void
    {
        $db  = $this->db;
        $now = Factory::getDate()->toSql();

        $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__guides_sources'))->where('step_id = ' . $stepId))->execute();

        foreach (\array_slice($sources, 0, 10) as $source) {
            $type = (string) ($source['source_type'] ?? '');

            if (!\in_array($type, Content::TYPES, true) || (int) ($source['item_id'] ?? 0) < 1) {
                continue;
            }

            $row = (object) [
                'step_id'     => $stepId,
                'source_type' => $type,
                'item_id'     => (int) $source['item_id'],
                'section'     => mb_substr(strip_tags((string) ($source['section'] ?? '')), 0, 255),
                'title'       => mb_substr(strip_tags((string) ($source['title'] ?? '')), 0, 255),
                'hash'        => preg_match('/^[0-9a-f]{40}$/', (string) ($source['hash'] ?? '')) ? $source['hash'] : '',
                'checked'     => $now,
            ];
            $db->insertObject('#__guides_sources', $row);
        }
    }

    /**
     * Steps no guide uses any more go, with their sources.
     */
    private function dropOrphans(): void
    {
        $db      = $this->db;
        $orphans = $db->setQuery(
            $db->getQuery(true)
                ->select('s.id')
                ->from($db->quoteName('#__guides_steps', 's'))
                ->where('NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__guides_guide_steps') . ' gs WHERE gs.step_id = s.id)')
        )->loadColumn();

        if ($orphans) {
            $ids = array_map('intval', $orphans);
            $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__guides_sources'))->whereIn('step_id', $ids))->execute();
            $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__guides_steps'))->whereIn('id', $ids))->execute();
        }
    }

    private function stepExists(int $id): bool
    {
        return (bool) $this->db->setQuery($this->db->getQuery(true)->select('1')->from($this->db->quoteName('#__guides_steps'))->where('id = ' . $id))->loadResult();
    }

    /**
     * Set a guide's state and what goes with it (who submitted or reviewed it, and when, a note).
     *
     * @param  array<string, mixed>  $fields
     */
    public function setState(int $id, string $state, array $fields = []): void
    {
        if (!\in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException('Unknown state ' . $state . '.');
        }

        $row = (object) (['id' => $id, 'state' => $state] + $fields);
        $this->db->updateObject('#__guides_guides', $row, 'id');
    }

    /**
     * The published article or item of a guide.
     */
    public function setTarget(int $id, string $type, int $targetId): void
    {
        $row = (object) ['id' => $id, 'target_type' => $type, 'target_id' => $targetId];
        $this->db->updateObject('#__guides_guides', $row, 'id');
    }

    /**
     * Delete a guide. Its steps go when no other guide uses them; the published article stays.
     */
    public function delete(int $id): void
    {
        $db = $this->db;

        $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__guides_guide_steps'))->where('guide_id = ' . $id))->execute();
        $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__guides_sources'))->where('guide_id = ' . $id))->execute();
        $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__guides_guides'))->where('id = ' . $id))->execute();
        $db->setQuery($db->getQuery(true)->update($db->quoteName('#__guides_guides'))->set('next_guide_id = 0')->where('next_guide_id = ' . $id))->execute();

        $this->dropOrphans();
    }

    /**
     * Steps of the library whose title holds these words, with how many guides use each.
     *
     * @return  object[]
     */
    public function library(string $words, int $limit = 20): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('s.id, s.title, s.product, s.modified')
            ->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__guides_guide_steps') . ' o WHERE o.step_id = s.id) AS used')
            ->from($db->quoteName('#__guides_steps', 's'))
            ->order('s.modified DESC')
            ->setLimit($limit);
        $likes = [];

        foreach (array_values(array_filter(preg_split('/\s+/u', trim($words)) ?: [])) as $n => $word) {
            $likes[$n] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word) . '%';
            $query->where('(s.title LIKE :w' . $n . ' OR s.product LIKE :p' . $n . ')')->bind(':w' . $n, $likes[$n])->bind(':p' . $n, $likes[$n]);
        }

        return $db->setQuery($query)->loadObjectList();
    }

    /**
     * One step of the library, as a guide takes it in.
     */
    public function step(int $id): ?object
    {
        $db   = $this->db;
        $step = $db->setQuery($db->getQuery(true)->select('id, title, body, image, note')->from($db->quoteName('#__guides_steps'))->where('id = ' . $id))->loadObject();

        if ($step) {
            $step->sources = $db->setQuery($db->getQuery(true)->select('source_type, item_id, section, title, hash, changed')->from($db->quoteName('#__guides_sources'))->where('step_id = ' . $id))->loadObjectList();
        }

        return $step ?: null;
    }

    /**
     * @return  array<int, string>  Guides that use a step, by ID
     */
    public function usage(int $stepId): array
    {
        $db = $this->db;

        return $db->setQuery(
            $db->getQuery(true)
                ->select('g.id, g.title')
                ->from($db->quoteName('#__guides_guide_steps', 'gs'))
                ->join('INNER', $db->quoteName('#__guides_guides', 'g'), 'g.id = gs.guide_id')
                ->where('gs.step_id = ' . $stepId)
        )->loadAssocList('id', 'title');
    }

    /**
     * How many guides wait for a review, and how many need a look because a source changed.
     *
     * @return  array{review: int, stale: int}
     */
    public function counts(): array
    {
        $db   = $this->db;
        $rows = $db->setQuery($db->getQuery(true)->select('state, COUNT(*) AS n')->from($db->quoteName('#__guides_guides'))->whereIn('state', ['review', 'stale'], ParameterType::STRING)->group('state'))->loadAssocList('state', 'n');

        return ['review' => (int) ($rows['review'] ?? 0), 'stale' => (int) ($rows['stale'] ?? 0)];
    }

    private static function plain(mixed $text, int $max): string
    {
        return trim(mb_substr(strip_tags((string) $text), 0, $max));
    }

    /**
     * An image of the site's own media, or an https address; nothing else.
     */
    private static function image(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        // Joomla's media field adds the image's size after a hash
        $path = (string) preg_replace('/#.*$/', '', $path);

        return preg_match('#^(images/[^\s"\'<>]+|https://[^\s"\'<>]+)$#i', $path) && !str_contains($path, '..') ? mb_substr($path, 0, 1024) : '';
    }
}
