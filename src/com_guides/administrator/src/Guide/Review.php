<?php

/**
 * @package     Guides
 * @subpackage  com_guides
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Guides\Administrator\Guide;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Sending a guide for review, and approving (which publishes it) or returning it. The same for
 * the back end and the MCP tools, so both check the same permissions: only who has "Review guides"
 * (by default only Super Users) approves or returns.
 *
 * @since  0.1.0
 */
final class Review
{
    /** Days after which a waiting guide is shown as late. */
    public const LATE_DAYS = 3;

    public function __construct(private DatabaseInterface $db, private Store $store, private Publisher $publisher)
    {
    }

    public static function create(DatabaseInterface $db): self
    {
        $settings = Settings::load();

        return new self($db, new Store($db), new Publisher($db, $settings));
    }

    public static function mayReview(User $user): bool
    {
        return $user->authorise('guides.review', 'com_guides');
    }

    public static function mayEdit(User $user, ?object $guide = null): bool
    {
        return $user->authorise('core.edit', 'com_guides')
            || ($guide && (int) $guide->created_by === (int) $user->id && $user->authorise('core.edit.own', 'com_guides'))
            || (!$guide && $user->authorise('core.create', 'com_guides'));
    }

    public function submit(int $id, User $user): object
    {
        $guide = $this->guide($id);

        if (!self::mayEdit($user, $guide)) {
            throw new \RuntimeException('Not allowed to send this guide for review.');
        }

        if (!\in_array($guide->state, ['draft', 'stale'], true)) {
            throw new \RuntimeException('Only a draft can be sent for review (this guide is ' . $guide->state . ').');
        }

        if (!$guide->steps) {
            throw new \RuntimeException('A guide needs at least one step.');
        }

        $this->store->setState($id, 'review', ['submitted' => Factory::getDate()->toSql(), 'submitted_by' => (int) $user->id, 'review_note' => '']);

        return $this->guide($id);
    }

    /**
     * Approve and publish. The reviewer may settle the category and tags at the same time.
     *
     * @param   array{catid?: int, tags?: int[], target_type?: string, note?: string}  $changes
     *
     * @return  array{type: string, id: int, url: string}  The published article
     */
    public function approve(int $id, User $user, array $changes = []): array
    {
        if (!self::mayReview($user)) {
            throw new \RuntimeException('Only reviewers (Super Users) approve guides.');
        }

        $guide = $this->guide($id);

        if (!\in_array($guide->state, ['review', 'stale'], true)) {
            throw new \RuntimeException('Only a guide waiting for review can be approved (this guide is ' . $guide->state . ').');
        }

        $fields = [];

        if (isset($changes['catid']) && (int) $changes['catid'] > 0) {
            $fields['catid'] = $guide->catid = (int) $changes['catid'];
        }

        if (isset($changes['tags'])) {
            $guide->tags    = array_values(array_unique(array_filter(array_map('intval', (array) $changes['tags']))));
            $fields['tags'] = json_encode($guide->tags);
        }

        if (isset($changes['target_type']) && \in_array($changes['target_type'], Content::TYPES, true)) {
            $fields['target_type'] = $guide->target_type = $changes['target_type'];
        }

        $published = $this->publisher->publish($guide, $user);

        $this->store->setTarget($id, $published['type'], $published['id']);
        $this->store->setState($id, 'published', $fields + [
            'reviewed'    => Factory::getDate()->toSql(),
            'reviewed_by' => (int) $user->id,
            'review_note' => mb_substr(trim(strip_tags((string) ($changes['note'] ?? ''))), 0, 2000),
        ]);

        return $published;
    }

    public function reject(int $id, User $user, string $note): object
    {
        if (!self::mayReview($user)) {
            throw new \RuntimeException('Only reviewers (Super Users) return guides.');
        }

        $note  = mb_substr(trim(strip_tags($note)), 0, 2000);
        $guide = $this->guide($id);

        if ($note === '') {
            throw new \InvalidArgumentException('Say what should change: a note is needed to return a guide.');
        }

        if ($guide->state !== 'review') {
            throw new \RuntimeException('Only a guide waiting for review can be returned (this guide is ' . $guide->state . ').');
        }

        $this->store->setState($id, 'draft', ['reviewed' => Factory::getDate()->toSql(), 'reviewed_by' => (int) $user->id, 'review_note' => $note]);

        return $this->guide($id);
    }

    /**
     * Guides waiting for review, oldest first, and those to check because a source changed.
     *
     * @return  array{review: object[], stale: object[]}
     */
    public function pending(): array
    {
        $db    = $this->db;
        $now   = Factory::getDate()->toUnix();
        $query = $db->getQuery(true)
            ->select('g.id, g.title, g.product, g.tags, g.state, g.gaps, g.submitted, g.modified, u.name AS submitted_name')
            ->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__guides_guide_steps') . ' gs WHERE gs.guide_id = g.id) AS steps')
            ->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__guides_guide_steps') . ' gs WHERE gs.guide_id = g.id AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__guides_sources') . ' s WHERE s.step_id = gs.step_id)) AS unsourced')
            ->from($db->quoteName('#__guides_guides', 'g'))
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = g.submitted_by')
            ->whereIn('g.state', ['review', 'stale'], \Joomla\Database\ParameterType::STRING)
            ->order('g.submitted ASC, g.modified ASC');
        $lists = ['review' => [], 'stale' => []];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $since          = $row->submitted ?: $row->modified;
            $row->days      = $since ? (int) floor(($now - Factory::getDate($since)->toUnix()) / 86400) : 0;
            $row->late      = $row->state === 'review' && $row->days > self::LATE_DAYS;
            $row->gaps      = json_decode((string) $row->gaps, true) ?: [];
            $row->tags      = $this->tagTitles(json_decode((string) $row->tags, true) ?: []);
            $row->steps     = (int) $row->steps;
            $row->unsourced = (int) $row->unsourced;

            $lists[$row->state][] = $row;
        }

        return $lists;
    }

    /**
     * @param   int[]  $ids
     *
     * @return  string[]
     */
    public function tagTitles(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        return $ids ? $this->db->setQuery($this->db->getQuery(true)->select('title')->from($this->db->quoteName('#__tags'))->whereIn('id', $ids)->order('title'))->loadColumn() : [];
    }

    private function guide(int $id): object
    {
        $guide = $this->store->guide($id);

        if (!$guide) {
            throw new \InvalidArgumentException('There is no guide ' . $id . '.');
        }

        return $guide;
    }
}
