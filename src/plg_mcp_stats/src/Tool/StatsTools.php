<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_stats
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Stats\Tool;

use Gss\Component\Mcp\Administrator\Helper\Resolver;
use Gss\Component\Mcp\Administrator\Mcp\Tool;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Joomla\CMS\Factory;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

\defined('_JEXEC') or die;

/**
 * Content statistics computed with aggregate queries, so they stay fast on large sites.
 *
 * @since  0.1.0
 */
final class StatsTools
{
    private const STATES = [1 => 'published', 0 => 'unpublished', 2 => 'archived', -2 => 'trashed'];

    private const PERIODS = ['year' => 'Y', 'quarter' => 'Q', 'month' => 'Y-m', 'week' => 'o-\WW', 'day' => 'Y-m-d', 'weekday' => 'N', 'hour' => 'H'];

    /** Time groupings bucket dates in PHP, so the number of rows they may read is capped. */
    private const MAX_SCAN_ROWS = 50000;

    /**
     * @return Tool[]
     */
    public static function tools(): array
    {
        $ref = ['type' => ['integer', 'string']];

        // These tools report on com_content, so they need one of its back-end permissions
        $canRead = fn (ToolContext $c) => $c->user->authorise('core.manage', 'com_content')
            || $c->user->authorise('core.edit', 'com_content')
            || $c->user->authorise('core.edit.own', 'com_content')
            || $c->user->authorise('core.create', 'com_content');

        return [
            new Tool(
                'content_overview',
                'Overview of the site content: article counts by state, featured, total hits, recent activity, category/tag/field counts, '
                . 'most read and most recent articles. A good first call for any statistics question.',
                ['properties' => [], 'additionalProperties' => false],
                [self::class, 'overview'],
                'Content overview',
                Tool::SCOPE_READ,
                'stats',
                false,
                $canRead
            ),
            new Tool(
                'content_stats',
                'Count articles (and sum their hits) grouped by a dimension, with optional filters. '
                . 'Examples: articles per month in 2025 (group_by=month, date_from=2025-01-01, date_to=2025-12-31); '
                . 'most active authors (group_by=author); tag usage in a category (group_by=tag, category=News).',
                [
                    'properties' => [
                        'group_by'              => ['type' => 'string', 'enum' => ['category', 'author', 'state', 'tag', 'language', 'featured', 'access', 'year', 'quarter', 'month', 'week', 'day', 'weekday', 'hour']],
                        'date_field'            => ['type' => 'string', 'enum' => ['created', 'publish_up', 'modified'], 'description' => 'Date used for time grouping and date filters (default created).'],
                        'date_from'             => ['type' => 'string', 'description' => 'YYYY-MM-DD, site time zone.'],
                        'date_to'               => ['type' => 'string', 'description' => 'YYYY-MM-DD, inclusive.'],
                        'state'                 => ['type' => 'string', 'enum' => ['published', 'unpublished', 'archived', 'trashed', 'any'], 'description' => 'Default: everything except trashed.'],
                        'category'              => $ref + ['description' => 'Only this category (ID, title or path).'],
                        'include_subcategories' => ['type' => 'boolean', 'description' => 'Default true.'],
                        'author'                => $ref,
                        'tag'                   => $ref,
                        'language'              => ['type' => 'string'],
                        'sort'                  => ['type' => 'string', 'enum' => ['count', 'hits', 'key'], 'description' => 'Default: key for time groupings, count otherwise.'],
                        'limit'                 => ['type' => 'integer', 'description' => 'Maximum number of groups (default 100).'],
                    ],
                    'required'             => ['group_by'],
                    'additionalProperties' => false,
                ],
                [self::class, 'stats'],
                'Content statistics',
                Tool::SCOPE_READ,
                'stats',
                false,
                $canRead
            ),
        ];
    }

    public static function overview(array $args, ToolContext $ctx): array
    {
        $db = $ctx->db;

        $base = fn () => self::base($ctx);

        $byState = [];

        foreach ($db->setQuery($base()->select('a.state, COUNT(*) AS n, SUM(a.hits) AS hits')->group('a.state'))->loadObjectList() as $row) {
            $byState[self::STATES[(int) $row->state] ?? (string) $row->state] = ['count' => (int) $row->n, 'hits' => (int) $row->hits];
        }

        $recent = [];

        foreach (['last_7_days' => 7, 'last_30_days' => 30, 'last_365_days' => 365] as $key => $days) {
            $since = Factory::getDate('-' . $days . ' days')->toSql();

            $recent[$key] = [
                'created'  => (int) $db->setQuery($base()->select('COUNT(*)')->where('a.state <> -2')->where('a.created >= :since')->bind(':since', $since))->loadResult(),
                'modified' => (int) $db->setQuery($base()->select('COUNT(*)')->where('a.state <> -2')->where('a.modified >= :since')->bind(':since', $since))->loadResult(),
            ];
        }

        $range = $db->setQuery($base()->select('MIN(a.created) AS first, MAX(a.created) AS last')->where('a.state <> -2'))->loadObject();

        $list = function (string $order) use ($ctx, $db, $base) {
            $query = $base()->select('a.id, a.title, a.hits, a.created')->where('a.state = 1')->order($order)->setLimit(10);

            return array_map(fn ($r) => [
                'id'      => (int) $r->id,
                'title'   => $r->title,
                'hits'    => (int) $r->hits,
                'created' => Resolver::localDate($ctx, $r->created),
            ], $db->setQuery($query)->loadObjectList());
        };

        $count = fn (string $table, string $alias, string $where) => (int) $db->setQuery(
            $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName($table, $alias))->where($where)
        )->loadResult();

        // A confined server must not reveal how much exists outside the categories it may use. The IDs
        // are integers, so they are safe to inline in the correlated conditions below.
        $allowed       = Resolver::allowedCategoryIds($ctx);
        $ids           = $allowed === null ? null : implode(',', array_map('intval', $allowed ?: [0]));
        $categoryScope = $ids === null ? '' : ' AND c.id IN (' . $ids . ')';

        // A custom field with no category assignment applies everywhere, so it stays counted
        $fieldScope = $ids === null ? '' : ' AND (NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__fields_categories', 'fc')
            . ' WHERE fc.field_id = f.id) OR EXISTS (SELECT 1 FROM ' . $db->quoteName('#__fields_categories', 'fc2')
            . ' WHERE fc2.field_id = f.id AND fc2.category_id IN (' . $ids . ')))';

        $featured = (int) $db->setQuery($base()->select('COUNT(*)')->where('a.state = 1')->where('a.featured = 1'))->loadResult();

        return [
            'articles' => [
                'total_excluding_trashed' => array_sum(array_map(fn ($s, $k) => $k === 'trashed' ? 0 : $s['count'], $byState, array_keys($byState))),
                'by_state'                => $byState,
                'featured_published'      => $featured,
                'first_created'           => Resolver::localDate($ctx, $range->first ?? null),
                'last_created'            => Resolver::localDate($ctx, $range->last ?? null),
            ],
            'activity'      => $recent,
            'categories'    => $count('#__categories', 'c', 'c.extension = ' . $db->quote('com_content') . ' AND c.published IN (0, 1)' . $categoryScope),
            // Tags have no category relationship, so this count cannot be scoped to the allowed categories
            'tags'          => $count('#__tags', 't', 't.id > 1 AND t.published IN (0, 1)'),
            'custom_fields' => $count('#__fields', 'f', 'f.context = ' . $db->quote('com_content.article') . ' AND f.state = 1' . $fieldScope),
            'most_read'     => $list('a.hits DESC'),
            'most_recent'   => $list('a.created DESC'),
            'time_zone'     => $ctx->app->get('offset', 'UTC'),
        ];
    }

    public static function stats(array $args, ToolContext $ctx): array
    {
        $db        = $ctx->db;
        $groupBy   = $args['group_by'];
        $dateField = match ($args['date_field'] ?? 'created') {
            'modified'   => 'a.modified',
            'publish_up' => 'a.publish_up',
            default      => 'a.created',
        };
        $query     = self::base($ctx);
        $filters   = [];

        $state = $args['state'] ?? null;

        if ($state === null) {
            $query->where('a.state <> -2');
        } elseif ($state !== 'any') {
            $query->where('a.state = ' . (int) array_search($state, self::STATES, true));
            $filters['state'] = $state;
        }

        if (isset($args['category'])) {
            $category = Resolver::category($ctx, $args['category']);
            $query->whereIn('a.catid', ($args['include_subcategories'] ?? true) ? Resolver::categoryTree($ctx, $category->id) : [$category->id]);
            $filters['category'] = $category->path;
        }

        if (isset($args['author'])) {
            $author = Resolver::userId($ctx, $args['author']);
            $query->where('a.created_by = :author')->bind(':author', $author, ParameterType::INTEGER);
            $filters['author'] = $author;
        }

        if (isset($args['tag'])) {
            $tagIds = Resolver::tags($ctx, [$args['tag']]);
            $query->where('EXISTS (SELECT 1 FROM ' . $db->quoteName('#__contentitem_tag_map') . ' ft WHERE ft.content_item_id = a.id'
                . ' AND ft.type_alias = ' . $db->quote('com_content.article') . ' AND ft.tag_id IN (' . implode(',', array_map('intval', $tagIds)) . '))');
            $filters['tag'] = $args['tag'];
        }

        if (!empty($args['language'])) {
            $query->where('a.language = :language')->bind(':language', $args['language']);
            $filters['language'] = $args['language'];
        }

        if (!empty($args['date_from'])) {
            $from = Resolver::sqlDate($ctx, $args['date_from']);
            $query->where($dateField . ' >= :from')->bind(':from', $from);
            $filters['date_from'] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $to = Resolver::sqlDate($ctx, $args['date_to'], true);
            $query->where($dateField . ' <= :to')->bind(':to', $to);
            $filters['date_to'] = $args['date_to'];
        }

        $totals = $db->setQuery((clone $query)->select('COUNT(*) AS n, SUM(a.hits) AS hits'))->loadObject();

        $truncated = false;

        $groups = isset(self::PERIODS[$groupBy])
            ? self::groupByPeriod($ctx, $query, $dateField, $groupBy, $truncated)
            : self::groupByColumn($ctx, $query, $groupBy);

        $sort = $args['sort'] ?? (isset(self::PERIODS[$groupBy]) ? 'key' : 'count');

        usort($groups, fn ($a, $b) => match ($sort) {
            'key'   => strcmp((string) $a['key'], (string) $b['key']),
            'hits'  => $b['hits'] <=> $a['hits'],
            default => $b['count'] <=> $a['count'],
        });

        $limit = max(1, min((int) ($args['limit'] ?? 100), 1000));
        $notes = [];

        if ($groupBy === 'tag') {
            $notes[] = 'Articles with several tags are counted once per tag; untagged articles are not listed.';
        }

        if ($truncated) {
            $notes[] = \sprintf(
                'The groups cover only the %d most recent articles by %s (the totals are complete); use date_from and date_to to get complete counts for a smaller range.',
                self::MAX_SCAN_ROWS,
                substr($dateField, 2)
            );
        }

        return [
            'group_by'       => $groupBy,
            'date_field'     => isset(self::PERIODS[$groupBy]) || isset($filters['date_from']) || isset($filters['date_to']) ? substr($dateField, 2) : null,
            'filters'        => $filters ?: null,
            'total_articles' => (int) $totals->n,
            'total_hits'     => (int) $totals->hits,
            'group_count'    => \count($groups),
            'groups'         => \array_slice($groups, 0, $limit),
            'truncated'      => $truncated,
            'note'           => $notes ? implode(' ', $notes) : null,
        ];
    }

    /**
     * Group in SQL by a column; labels are joined in.
     */
    private static function groupByColumn(ToolContext $ctx, QueryInterface $query, string $groupBy): array
    {
        $db = $ctx->db;

        switch ($groupBy) {
            case 'category':
                $query->select('a.catid AS k, c.title AS label, c.path')
                    ->join('LEFT', $db->quoteName('#__categories', 'c'), 'c.id = a.catid')
                    ->group('a.catid, c.title, c.path');
                break;

            case 'author':
                $query->select('a.created_by AS k, u.name AS label')
                    ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = a.created_by')
                    ->group('a.created_by, u.name');
                break;

            case 'tag':
                $query->select('t.id AS k, t.title AS label, t.path')
                    ->join('INNER', $db->quoteName('#__contentitem_tag_map', 'm'), 'm.content_item_id = a.id AND m.type_alias = ' . $db->quote('com_content.article'))
                    ->join('INNER', $db->quoteName('#__tags', 't'), 't.id = m.tag_id')
                    ->group('t.id, t.title, t.path');
                break;

            case 'access':
                $query->select('a.access AS k, v.title AS label')
                    ->join('LEFT', $db->quoteName('#__viewlevels', 'v'), 'v.id = a.access')
                    ->group('a.access, v.title');
                break;

            default:
                $column = ['state' => 'a.state', 'language' => 'a.language', 'featured' => 'a.featured'][$groupBy];
                $query->select($column . ' AS k, NULL AS label')->group($column);
        }

        $query->select('COUNT(*) AS n, SUM(a.hits) AS hits');

        return array_map(function ($row) use ($groupBy) {
            $label = $row->label;

            if ($groupBy === 'state') {
                $label = self::STATES[(int) $row->k] ?? (string) $row->k;
            } elseif ($groupBy === 'featured') {
                $label = (int) $row->k ? 'featured' : 'not featured';
            } elseif ($groupBy === 'language') {
                $label = $row->k === '*' ? 'All languages' : $row->k;
            }

            $group = ['key' => is_numeric($row->k) ? (int) $row->k : $row->k, 'label' => $label];

            if (isset($row->path)) {
                $group['path'] = $row->path;
            }

            return $group + ['count' => (int) $row->n, 'hits' => (int) $row->hits];
        }, $ctx->db->setQuery($query)->loadObjectList());
    }

    /**
     * Group by a period of the date in the site time zone. Dates are bucketed in PHP so the
     * result is correct for every database and time zone.
     */
    private static function groupByPeriod(ToolContext $ctx, QueryInterface $query, string $dateField, string $groupBy, bool &$truncated): array
    {
        $db     = $ctx->db;
        $tz     = new \DateTimeZone($ctx->app->get('offset', 'UTC'));
        $utc    = new \DateTimeZone('UTC');
        $format = self::PERIODS[$groupBy];
        $groups = [];

        // Newest first and capped: without a limit this reads (and converts) every article on the site
        $query->select($dateField . ' AS d, a.hits')
            ->where($dateField . ' IS NOT NULL')
            ->order($dateField . ' DESC')
            ->setLimit(self::MAX_SCAN_ROWS);

        $db->setQuery($query);

        $scanned = 0;

        foreach ($db->getIterator() as $row) {
            $scanned++;

            if (!$row->d || str_starts_with($row->d, '0000')) {
                continue;
            }

            $date = (new \DateTimeImmutable($row->d, $utc))->setTimezone($tz);
            $key  = $format === 'Q' ? $date->format('Y') . '-Q' . (int) ceil($date->format('n') / 3) : $date->format($format);

            $groups[$key] ??= ['key' => $key, 'label' => null, 'count' => 0, 'hits' => 0];
            $groups[$key]['count']++;
            $groups[$key]['hits'] += (int) $row->hits;
        }

        $truncated = $scanned >= self::MAX_SCAN_ROWS;

        if ($groupBy === 'weekday') {
            $names = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

            foreach ($groups as &$group) {
                $group['label'] = $names[(int) $group['key']];
            }
        }

        return array_values($groups);
    }

    private static function base(ToolContext $ctx): QueryInterface
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)->from($db->quoteName('#__content', 'a'));

        Resolver::applyArticleVisibility($ctx, $query);

        return $query;
    }
}
