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
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Where a guide goes (docs/guides-spec.md, section 5.3). With "type_tags" the category is fixed
 * and the tags of its source articles are suggested, grouped by their top tag (the product); with
 * "topic" categories are suggested from where the sources are, how close the guide is to what each
 * category holds (when Ask the Site has indexed the site), and names that sound like guides.
 *
 * @since  0.1.0
 */
final class Placement
{
    /** Category names that sound like guides. */
    private const GUIDE_WORDS = '/教學|操作|說明|手冊|指南|FAQ|guide|how-?to|tutorial|manual|help/iu';

    /** Indexed articles compared when categories are weighed by meaning. */
    private const MAX_DOCUMENTS = 3000;

    public function __construct(private DatabaseInterface $db, private Settings $settings)
    {
    }

    /**
     * @param   array<int, array{source_type: string, item_id: int}>  $sources  The guide's source articles
     *
     * @return  array  mode, and categories (topic) or catid and tag groups (type_tags)
     */
    public function suggest(array $sources, string $title = '', string $summary = ''): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($source) => (int) ($source['item_id'] ?? 0), $sources))));

        if ($this->settings->placementMode() === 'type_tags') {
            return ['mode' => 'type_tags', 'catid' => $this->settings->guideCategory(), 'tags' => $this->tags($ids)];
        }

        return ['mode' => 'topic', 'categories' => $this->categories($ids, trim($title . "\n" . $summary))];
    }

    /**
     * The tags of the source articles, each with its number of votes, grouped by top tag.
     *
     * @param   int[]  $ids
     *
     * @return  array<int, array{root: string, tags: array<int, array{id: int, title: string, path: string, votes: int}>}>
     */
    private function tags(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $db    = $this->db;
        $votes = [];

        // Joomla's own tags, which FLEXIcontent items also keep when its tag sync is on
        foreach ($db->setQuery(
            $db->getQuery(true)
                ->select('tag_id, content_item_id')
                ->from($db->quoteName('#__contentitem_tag_map'))
                ->where($db->quoteName('type_alias') . ' = ' . $db->quote('com_content.article'))
                ->whereIn('content_item_id', $ids)
        )->loadObjectList() as $row) {
            $votes[(int) $row->tag_id][(int) $row->content_item_id] = true;
        }

        // FLEXIcontent's own tags, mapped to Joomla tags by their ID there
        if (Flexicontent::isAvailable()) {
            try {
                foreach ($db->setQuery(
                    $db->getQuery(true)
                        ->select('t.jtag_id, r.itemid')
                        ->from($db->quoteName('#__flexicontent_tags_item_relations', 'r'))
                        ->join('INNER', $db->quoteName('#__flexicontent_tags', 't'), 't.id = r.tid')
                        ->where('t.jtag_id > 0')
                        ->whereIn('r.itemid', $ids)
                )->loadObjectList() as $row) {
                    $votes[(int) $row->jtag_id][(int) $row->itemid] = true;
                }
            } catch (\Throwable) {
                // An older FLEXIcontent without the link to Joomla tags
            }
        }

        if (!$votes) {
            return [];
        }

        $tags = $db->setQuery(
            $db->getQuery(true)
                ->select('t.id, t.title, t.path, t.level, t.lft, t.rgt, t.published')
                ->select('(SELECT r.title FROM ' . $db->quoteName('#__tags') . ' r WHERE r.level = 1 AND r.lft <= t.lft AND r.rgt >= t.rgt) AS root')
                ->from($db->quoteName('#__tags', 't'))
                ->whereIn('t.id', array_keys($votes))
                ->where('t.published = 1')
        )->loadObjectList();
        $groups = [];

        foreach ($tags as $tag) {
            $root = (string) ($tag->root ?: $tag->title);

            $groups[$root]['root']   = $root;
            $groups[$root]['tags'][] = ['id' => (int) $tag->id, 'title' => (string) $tag->title, 'path' => (string) $tag->path, 'votes' => \count($votes[(int) $tag->id])];
        }

        foreach ($groups as &$group) {
            usort($group['tags'], static fn ($a, $b) => [$b['votes'], $a['title']] <=> [$a['votes'], $b['title']]);
            $group['votes'] = max(array_column($group['tags'], 'votes'));
        }

        unset($group);
        usort($groups, static fn ($a, $b) => [$b['votes'], $a['root']] <=> [$a['votes'], $b['root']]);

        return array_map(static fn ($group) => ['root' => $group['root'], 'tags' => $group['tags']], $groups);
    }

    /**
     * The three categories that suit a guide best.
     *
     * @param   int[]  $ids
     *
     * @return  array<int, array{id: int, title: string, path: string, score: float, votes: float, meaning: float|null, name: float, new_child: bool}>
     */
    private function categories(array $ids, string $text): array
    {
        $db         = $this->db;
        $categories = $db->setQuery(
            $db->getQuery(true)
                ->select('id, title, alias, path, parent_id, level')
                ->from($db->quoteName('#__categories'))
                ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
                ->where('published = 1')
        )->loadObjectList('id');

        if (!$categories) {
            return [];
        }

        // Where the sources are: a vote for each article's category, half a vote for its parent
        $votes = [];

        if ($ids) {
            foreach ($db->setQuery($db->getQuery(true)->select('catid')->from($db->quoteName('#__content'))->whereIn('id', $ids))->loadColumn() as $catid) {
                $votes[(int) $catid] = ($votes[(int) $catid] ?? 0) + 1;
                $parent              = (int) ($categories[(int) $catid]->parent_id ?? 0);

                if (isset($categories[$parent])) {
                    $votes[$parent] = ($votes[$parent] ?? 0) + 0.5;
                }
            }
        }

        $meaning = $text !== '' ? $this->meaning($text) : null;
        $most    = $votes ? max($votes) : 0;

        // Similarities lie close together: what counts is how a category compares with the others
        [$low, $high] = $meaning ? [min($meaning), max($meaning)] : [0.0, 0.0];
        $weights = $meaning === null ? ['votes' => 0.65, 'name' => 0.35, 'meaning' => 0.0] : ['votes' => 0.5, 'name' => 0.2, 'meaning' => 0.3];
        $scored  = [];

        foreach ($categories as $id => $category) {
            $vote  = $most > 0 ? ($votes[$id] ?? 0) / $most : 0.0;
            $name  = preg_match(self::GUIDE_WORDS, $category->title . ' ' . $category->alias) ? 1.0 : 0.0;
            $close = $meaning[$id] ?? null;
            $near  = $close !== null && $high > $low ? ($close - $low) / ($high - $low) : 0.0;
            $score = $weights['votes'] * $vote + $weights['name'] * $name + $weights['meaning'] * $near;

            if ($score > 0) {
                $scored[] = ['id' => (int) $id, 'title' => (string) $category->title, 'path' => (string) $category->path, 'score' => round($score, 3), 'votes' => (float) ($votes[$id] ?? 0), 'meaning' => $close === null ? null : round($close, 3), 'name' => $name, 'new_child' => false];
            }
        }

        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $best = \array_slice($scored, 0, 3);

        // Nothing that sounds like guides: offer a "guides" category under the one with most sources
        if ($votes && !array_filter($best, static fn ($row) => $row['name'] > 0)) {
            arsort($votes);
            $top = (int) array_key_first($votes);

            $best[] = ['id' => $top, 'title' => (string) $categories[$top]->title, 'path' => (string) $categories[$top]->path, 'score' => 0.0, 'votes' => (float) $votes[$top], 'meaning' => null, 'name' => 0.0, 'new_child' => true];
        }

        return $best;
    }

    /**
     * How close a text is to what each category holds: the mean similarity to the first passage of
     * each of its articles in Ask the Site's index. Null without Ask the Site, its index or its AI.
     *
     * @return  array<int, float>|null  By category ID
     */
    private function meaning(string $text): ?array
    {
        $settingsClass = 'Gss\\Component\\Mcpask\\Administrator\\Ask\\Settings';
        $vectorClass   = 'Gss\\Component\\Mcpask\\Administrator\\Ask\\Vector';

        if (!ComponentHelper::isEnabled('com_mcpask') || !class_exists($settingsClass) || !class_exists($vectorClass)) {
            return null;
        }

        try {
            $ask = $settingsClass::load();

            if (!$ask->ready()) {
                return null;
            }

            $db   = $this->db;
            $rows = $db->setQuery(
                $db->getQuery(true)
                    ->select('a.catid, c.embedding')
                    ->from($db->quoteName('#__mcpask_documents', 'd'))
                    ->join('INNER', $db->quoteName('#__mcpask_chunks', 'c'), 'c.document_id = d.id AND c.position = 0')
                    ->join('INNER', $db->quoteName('#__content', 'a'), 'a.id = d.item_id')
                    ->whereIn('d.source', ['content', 'flexicontent'], \Joomla\Database\ParameterType::STRING)
                    ->where('d.status = ' . $db->quote('ok'))
                    ->setLimit(self::MAX_DOCUMENTS)
            )->loadObjectList();

            if (!$rows) {
                return null;
            }

            $embedding = $ask->client()->embeddings($ask->embeddingModel(), [mb_substr($text, 0, 2000)], $ask->embeddingDimensions())[0] ?? null;

            if (!$embedding) {
                return null;
            }

            $question = $vectorClass::unpack($vectorClass::pack($embedding));
            $sums     = [];

            foreach ($rows as $row) {
                $sums[(int) $row->catid][] = $vectorClass::similarity($question, $vectorClass::unpack((string) $row->embedding));
            }

            return array_map(static fn ($scores) => array_sum($scores) / \count($scores), $sums);
        } catch (\Throwable) {
            // Weighing by meaning is a help, not a need
            return null;
        }
    }
}
