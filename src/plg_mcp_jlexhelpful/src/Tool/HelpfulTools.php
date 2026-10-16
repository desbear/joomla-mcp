<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_jlexhelpful
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Jlexhelpful\Tool;

use Gss\Component\Mcp\Administrator\Helper\Resolver;
use Gss\Component\Mcp\Administrator\Mcp\Tool;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;

\defined('_JEXEC') or die;

/**
 * Read-only tools over JLex Helpful's article feedback, for reviewing which pages are working.
 *
 * Every tool is Super-User-only and read-only.
 *
 * @since  0.8.0
 */
final class HelpfulTools
{
    /** Most votes quoted in a digest. */
    private const DIGEST_SAMPLE = 30;

    public static function isAvailable(): bool
    {
        return HelpfulData::isAvailable();
    }

    /**
     * @return Tool[]
     */
    public static function tools(): array
    {
        $onlyAdmins = [HelpfulData::class, 'superUserOnly'];

        return [
            new Tool(
                'list_article_feedback',
                'List the "was this helpful?" votes visitors left on articles, newest first, with the reason they chose and any '
                . 'comment they typed. Filter by verdict, article, date, and whether a comment was left. '
                . 'Use verdict=not_helpful with with_comment=true to read what people said was wrong.',
                [
                    'properties' => HelpfulData::filterSchema() + [
                        'order_by'  => ['type' => 'string', 'enum' => ['voted_at', 'article'], 'description' => 'Default voted_at.'],
                        'direction' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'description' => 'Default desc.'],
                        'limit'     => ['type' => 'integer', 'description' => 'Default 20.', 'minimum' => 1],
                        'offset'    => ['type' => 'integer', 'minimum' => 0],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'listFeedback'],
                'List article feedback',
                Tool::SCOPE_READ,
                'jlexhelpful',
                false,
                $onlyAdmins
            ),
            new Tool(
                'article_feedback_stats',
                'Count "was this helpful?" votes grouped by article, day, week, month, verdict or the reason the visitor chose, '
                . 'with the helpful ratio for each group. Group by article and sort by worst to find the pages that are failing readers.',
                [
                    'properties' => [
                        'group_by' => ['type' => 'string', 'enum' => ['article', 'day', 'week', 'month', 'verdict', 'reason']],
                        'sort'     => ['type' => 'string', 'enum' => ['votes', 'worst', 'best', 'key'], 'description' => 'Default votes. "worst" sorts by the lowest helpful ratio.'],
                        'min_votes' => ['type' => 'integer', 'description' => 'Only groups with at least this many votes (default 1). Useful with "worst", so one stray vote does not top the list.', 'minimum' => 1],
                        'limit'    => ['type' => 'integer', 'description' => 'Maximum number of groups (default 50).', 'minimum' => 1],
                    ] + HelpfulData::filterSchema(),
                    'required'             => ['group_by'],
                    'additionalProperties' => false,
                ],
                [self::class, 'stats'],
                'Article feedback statistics',
                Tool::SCOPE_READ,
                'jlexhelpful',
                false,
                $onlyAdmins
            ),
            new Tool(
                'article_feedback_overview',
                'A digest of article feedback over the last few days: how many votes, the helpful ratio and how it compares with '
                . 'the period before, the articles that drew negative votes, the reasons given, and the comments people left. '
                . 'Call this for a daily or weekly review.',
                [
                    'properties' => [
                        'days' => ['type' => 'integer', 'description' => 'Days back from today, including today. Default 1 (today only).', 'minimum' => 1, 'maximum' => 365],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'overview'],
                'Article feedback digest',
                Tool::SCOPE_READ,
                'jlexhelpful',
                false,
                $onlyAdmins
            ),
        ];
    }

    /**
     * A date this many days before today, as YYYY-MM-DD in the site's own time zone.
     */
    private static function dayInSiteTime(ToolContext $ctx, int $daysAgo): string
    {
        $zone = new \DateTimeZone((string) $ctx->app->get('offset', 'UTC'));

        return (new \DateTimeImmutable('now', $zone))->modify('-' . max(0, $daysAgo) . ' days')->format('Y-m-d');
    }

    public static function listFeedback(array $args, ToolContext $ctx): array
    {
        $db      = $ctx->db;
        $query   = HelpfulData::baseQuery($ctx);
        $filters = HelpfulData::applyFilters($ctx, $query, $args);

        $total  = (int) $db->setQuery((clone $query)->select('COUNT(*)'))->loadResult();
        $limit  = $ctx->limit($args['limit'] ?? null);
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $dir    = strtolower($args['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $order  = match ($args['order_by'] ?? 'voted_at') {
            'article' => 'v.name_key',
            default   => 'v.created_time',
        };

        $query->select('v.id, v.created_time, v.voting, v.cb_value, v.cb_option, v.created_by, v.section_id, v.title, v.name_key')
            ->select('a.id AS article_id, a.title AS article_title')
            ->order($order . ' ' . $dir . ', v.id ' . $dir)
            ->setLimit($limit, $offset);

        $items = array_map(fn ($row) => HelpfulData::describe($ctx, $row), $db->setQuery($query)->loadObjectList());

        return array_filter([
            'total'           => $total,
            'offset'          => $offset,
            'has_more'        => $offset + \count($items) < $total,
            'filters'         => $filters ?: null,
            'votes'           => $items,
            'untrusted_input' => array_filter($items, fn ($i) => isset($i['comment'])) ? HelpfulData::UNTRUSTED_NOTE : null,
        ], fn ($v) => $v !== null);
    }

    public static function stats(array $args, ToolContext $ctx): array
    {
        $db       = $ctx->db;
        $groupBy  = $args['group_by'];
        $polarity = HelpfulData::polarity($ctx);
        $query    = HelpfulData::baseQuery($ctx);
        $filters  = HelpfulData::applyFilters($ctx, $query, $args);

        // Not "?: '-1'": a single positive vote value of 0 makes the joined string "0", which PHP
        // treats as falsy, and the fallback would then count every vote as not helpful
        $positive = $polarity['positive'] ? implode(',', array_map('intval', $polarity['positive'])) : '-1';
        $measures = 'COUNT(*) AS n'
            . ', SUM(CASE WHEN v.voting IN (' . $positive . ') THEN 1 ELSE 0 END) AS helpful'
            . ", SUM(CASE WHEN v.cb_option IS NOT NULL AND v.cb_option <> '' THEN 1 ELSE 0 END) AS comments";

        $totals = $db->setQuery((clone $query)->select($measures))->loadObject();

        // Built here, never from the argument. Dates are shifted into the site's time zone first,
        // so a "day" is the administrator's day and not a UTC one.
        $local  = Resolver::inSiteTime($ctx, 'v.created_time');
        $select = match ($groupBy) {
            'day'     => 'DATE(' . $local . ')',
            'week'    => "DATE_FORMAT(" . $local . ", '%x-W%v')",
            'month'   => "DATE_FORMAT(" . $local . ", '%Y-%m')",
            'verdict' => 'CASE WHEN v.voting IN (' . $positive . ') THEN ' . $db->quote('helpful') . ' ELSE ' . $db->quote('not_helpful') . ' END',
            'reason'  => 'CONCAT(v.voting, ":", COALESCE(v.cb_value, 0))',
            default   => 'v.name_key',
        };

        $query->select($select . ' AS k, ' . $measures);

        if ($groupBy === 'article') {
            $query->select('MAX(a.id) AS article_id, MAX(a.title) AS article_title, MAX(v.title) AS page_title');
        }

        $query->group('k');

        $rows   = $db->setQuery($query)->loadObjectList();
        $groups = [];

        foreach ($rows as $row) {
            $votes   = (int) $row->n;
            $helpful = (int) $row->helpful;
            $label   = null;
            $key     = $row->k;

            if ($groupBy === 'reason') {
                // The key is "<voting>:<cb_value>", because a reason only means something as a pair
                [$voting, $value]  = array_map('intval', explode(':', (string) $row->k) + [0, 0]);
                $section           = HelpfulData::sections($ctx);
                $button            = null;

                foreach ($section as $one) {
                    if (isset($one['votes'][$voting])) {
                        $button = $one['votes'][$voting];
                        break;
                    }
                }

                $verdict = \in_array($voting, $polarity['positive'], true) ? 'helpful' : 'not_helpful';
                $label   = ($button['reasons'][$value] ?? ('option ' . $value)) . ' (' . $verdict . ')';
            } elseif ($groupBy === 'article') {
                $label = $row->article_title ?? $row->page_title ?? null;
                $key   = $row->article_id ? (int) $row->article_id : (string) $row->k;
            }

            $groups[] = array_filter([
                'key'           => \is_numeric($key) ? $key + 0 : $key,
                'label'         => $label,
                'votes'         => $votes,
                'helpful'       => $helpful,
                'not_helpful'   => $votes - $helpful,
                'helpful_ratio' => $votes > 0 ? round($helpful / $votes, 3) : null,
                'comments'      => (int) $row->comments ?: null,
            ], fn ($v) => $v !== null);
        }

        $minVotes = max(1, (int) ($args['min_votes'] ?? 1));
        $groups   = array_values(array_filter($groups, fn ($g) => $g['votes'] >= $minVotes));
        $sort     = $args['sort'] ?? (\in_array($groupBy, ['day', 'week', 'month'], true) ? 'key' : 'votes');

        usort($groups, fn ($a, $b) => match ($sort) {
            'key'   => strcmp((string) $a['key'], (string) $b['key']),
            'worst' => [$a['helpful_ratio'] ?? 1, -$a['votes']] <=> [$b['helpful_ratio'] ?? 1, -$b['votes']],
            'best'  => [$b['helpful_ratio'] ?? 0, $b['votes']] <=> [$a['helpful_ratio'] ?? 0, $a['votes']],
            default => $b['votes'] <=> $a['votes'],
        });

        return array_filter([
            'group_by'      => $groupBy,
            'filters'       => $filters ?: null,
            'total_votes'   => (int) $totals->n,
            'helpful'       => (int) $totals->helpful,
            'not_helpful'   => (int) $totals->n - (int) $totals->helpful,
            'helpful_ratio' => (int) $totals->n > 0 ? round((int) $totals->helpful / (int) $totals->n, 3) : null,
            'with_comment'  => (int) $totals->comments,
            'group_count'   => \count($groups),
            'groups'        => \array_slice($groups, 0, max(1, min((int) ($args['limit'] ?? 50), 500))),
            'vote_buttons'  => self::buttonLegend($ctx),
        ], fn ($v) => $v !== null);
    }

    public static function overview(array $args, ToolContext $ctx): array
    {
        $days = max(1, min((int) ($args['days'] ?? 1), 365));
        $from = self::dayInSiteTime($ctx, $days - 1);

        // The period of the same length immediately before, for a comparison
        $previousFrom = self::dayInSiteTime($ctx, ($days * 2) - 1);
        $previousTo   = self::dayInSiteTime($ctx, $days);

        $now      = self::stats(['group_by' => 'verdict', 'date_from' => $from], $ctx);
        $previous = self::stats(['group_by' => 'verdict', 'date_from' => $previousFrom, 'date_to' => $previousTo], $ctx);

        $worstArticles = self::stats([
            'group_by' => 'article',
            'sort'     => 'worst',
            'date_from' => $from,
            'limit'    => 10,
        ], $ctx)['groups'];

        $reasons = self::stats(['group_by' => 'reason', 'verdict' => 'not_helpful', 'date_from' => $from, 'limit' => 10], $ctx)['groups'];

        $comments = self::listFeedback([
            'verdict'      => 'not_helpful',
            'with_comment' => true,
            'date_from'    => $from,
            'limit'        => self::DIGEST_SAMPLE,
        ], $ctx)['votes'];

        return array_filter([
            'period'            => ['from' => $from, 'days' => $days],
            'votes'             => $now['total_votes'],
            'helpful'           => $now['helpful'],
            'not_helpful'       => $now['not_helpful'],
            'helpful_ratio'     => $now['helpful_ratio'] ?? null,
            'previous_period'   => [
                'from'          => $previousFrom,
                'to'            => $previousTo,
                'votes'         => $previous['total_votes'],
                'helpful_ratio' => $previous['helpful_ratio'] ?? null,
            ],
            'ratio_change'      => isset($now['helpful_ratio'], $previous['helpful_ratio'])
                ? round($now['helpful_ratio'] - $previous['helpful_ratio'], 3)
                : null,
            'articles_to_look_at' => $worstArticles ?: null,
            'negative_reasons'  => $reasons ?: null,
            'comments'          => $comments ?: null,
            'vote_buttons'      => self::buttonLegend($ctx),
            'untrusted_input'   => $comments ? HelpfulData::UNTRUSTED_NOTE : null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * What this site's vote buttons actually are, so the numbers can be read without guessing.
     */
    private static function buttonLegend(ToolContext $ctx): array
    {
        $polarity = HelpfulData::polarity($ctx);
        $legend   = [];

        foreach (HelpfulData::sections($ctx) as $id => $section) {
            foreach ($section['votes'] as $index => $button) {
                $legend[] = array_filter([
                    'section' => \count(HelpfulData::sections($ctx)) > 1 ? $section['name'] : null,
                    'stored_value' => $index,
                    'label'   => $button['label'],
                    'counts_as' => \in_array($index, $polarity['positive'], true) ? 'helpful' : 'not_helpful',
                ], fn ($v) => $v !== null);
            }
        }

        return $legend;
    }
}
