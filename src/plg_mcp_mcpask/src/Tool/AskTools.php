<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Mcpask\Tool;

use Gss\Component\Mcp\Administrator\Helper\Resolver;
use Gss\Component\Mcp\Administrator\Mcp\Tool;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;

\defined('_JEXEC') or die;

/**
 * Read-only tools over the "Ask the Site" question log, for reviewing what visitors are asking.
 *
 * Every tool is Super-User-only and read-only. Nothing here can change or delete a row.
 *
 * @since  0.8.0
 */
final class AskTools
{
    /** Most questions one call will return in a digest. */
    private const DIGEST_SAMPLE = 50;

    public static function isAvailable(): bool
    {
        return AskData::isAvailable();
    }

    /**
     * @return Tool[]
     */
    public static function tools(): array
    {
        $onlyAdmins = [AskData::class, 'superUserOnly'];

        return [
            new Tool(
                'list_questions',
                'List the questions visitors asked the site\'s "Ask the Site" answer box, newest first. '
                . 'Filter by date, outcome, whether the visitor was signed in, and text in the question. '
                . 'Use status=unanswered (or status=empty) to find the questions the site could not answer, which are the content gaps.',
                [
                    'properties' => AskData::filterSchema() + [
                        'order_by'  => ['type' => 'string', 'enum' => ['asked_at', 'duration', 'tokens'], 'description' => 'Default asked_at.'],
                        'direction' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'description' => 'Default desc.'],
                        'limit'     => ['type' => 'integer', 'description' => 'Default 20.', 'minimum' => 1],
                        'offset'    => ['type' => 'integer', 'minimum' => 0],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'listQuestions'],
                'List visitor questions',
                Tool::SCOPE_READ,
                'mcpask',
                false,
                $onlyAdmins
            ),
            new Tool(
                'ask_stats',
                'Count the questions asked of "Ask the Site", grouped by day, week, month, hour of day, outcome or whether the '
                . 'visitor was signed in. Reports how many were answered, how many found nothing, and the token usage.',
                [
                    'properties' => [
                        'group_by' => ['type' => 'string', 'enum' => ['day', 'week', 'month', 'hour_of_day', 'status', 'asked_by']],
                        'limit'     => ['type' => 'integer', 'description' => 'Maximum number of groups (default 100).', 'minimum' => 1],
                    ] + AskData::filterSchema(),
                    'required'             => ['group_by'],
                    'additionalProperties' => false,
                ],
                [self::class, 'stats'],
                'Question statistics',
                Tool::SCOPE_READ,
                'mcpask',
                false,
                $onlyAdmins
            ),
            new Tool(
                'ask_overview',
                'A digest of "Ask the Site" activity over the last few days: how many questions were asked and answered, and the '
                . 'questions the site could not answer, listed in full because those are the content gaps worth writing about. '
                . 'Call this for a daily or weekly review.',
                [
                    'properties' => [
                        'days' => ['type' => 'integer', 'description' => 'Days back from today, including today. Default 1 (today only).', 'minimum' => 1, 'maximum' => 90],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'overview'],
                'Question digest',
                Tool::SCOPE_READ,
                'mcpask',
                false,
                $onlyAdmins
            ),
        ];
    }

    /**
     * A date this many days before today, as YYYY-MM-DD in the site's own time zone, so "today"
     * means the same day the administrator sees in the back end.
     */
    private static function dayInSiteTime(ToolContext $ctx, int $daysAgo): string
    {
        $zone = new \DateTimeZone((string) $ctx->app->get('offset', 'UTC'));
        $date = new \DateTimeImmutable('now', $zone);

        return $date->modify('-' . max(0, $daysAgo) . ' days')->format('Y-m-d');
    }

    public static function listQuestions(array $args, ToolContext $ctx): array
    {
        AskData::assertQuestionsRecorded();

        $db      = $ctx->db;
        $query   = AskData::baseQuery($ctx);
        $filters = AskData::applyFilters($ctx, $query, $args);

        $total  = (int) $db->setQuery((clone $query)->select('COUNT(*)'))->loadResult();
        $limit  = $ctx->limit($args['limit'] ?? null);
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $dir    = strtolower($args['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $order  = match ($args['order_by'] ?? 'asked_at') {
            'duration' => 'l.duration_ms',
            'tokens'   => '(l.tokens_in + l.tokens_out)',
            default    => 'l.created',
        };

        $query->select('l.id, l.created, l.status, l.question, l.user_id, l.sources, l.tokens_in, l.tokens_out, l.duration_ms, l.message')
            ->select(AskData::hasRatings() ? 'l.rating, l.feedback, l.hint_id' : '0 AS rating')
            ->select(AskData::hasFlags() ? 'l.flags, l.answer, l.answer_raw' : "'' AS flags")
            ->select('u.name AS user_name')
            ->order($order . ' ' . $dir . ', l.id ' . $dir)
            ->setLimit($limit, $offset);

        $items = array_map(fn ($row) => AskData::describe($ctx, $row), $db->setQuery($query)->loadObjectList());

        return [
            'total'            => $total,
            'offset'           => $offset,
            'has_more'         => $offset + \count($items) < $total,
            'filters'          => $filters ?: null,
            'retention_days'   => AskData::retentionDays(),
            'questions'        => $items,
            'untrusted_input'  => AskData::UNTRUSTED_NOTE,
        ];
    }

    public static function stats(array $args, ToolContext $ctx): array
    {
        $db      = $ctx->db;
        $groupBy = $args['group_by'];
        $query   = AskData::baseQuery($ctx);
        $filters = AskData::applyFilters($ctx, $query, $args);

        $measures = 'COUNT(*) AS n'
            . ", SUM(CASE WHEN l.status = 'ok' THEN 1 ELSE 0 END) AS answered"
            . ", SUM(CASE WHEN l.status = 'empty' THEN 1 ELSE 0 END) AS found_nothing"
            . ", SUM(CASE WHEN l.status = 'error' THEN 1 ELSE 0 END) AS failed"
            . ", SUM(CASE WHEN l.status = 'blocked' THEN 1 ELSE 0 END) AS blocked"
            . ', SUM(l.tokens_in) AS tokens_in, SUM(l.tokens_out) AS tokens_out, AVG(NULLIF(l.duration_ms, 0)) AS avg_ms'
            . (AskData::hasFlags()
                ? ", SUM(CASE WHEN l.flags LIKE '%injection_suspect%' THEN 1 ELSE 0 END) AS injection_suspect"
                . ", SUM(CASE WHEN l.flags LIKE '%html_payload%' THEN 1 ELSE 0 END) AS html_payload"
                . ", SUM(CASE WHEN l.flags LIKE '%output_filtered%' THEN 1 ELSE 0 END) AS output_filtered"
                : '');

        $totals = $db->setQuery((clone $query)->select($measures))->loadObject();

        // Grouping expressions are built here, never from the argument. Dates are shifted into the
        // site's time zone first, so a "day" is the administrator's day and not a UTC one.
        $local  = Resolver::inSiteTime($ctx, 'l.created');
        $select = match ($groupBy) {
            'week'        => "DATE_FORMAT(" . $local . ", '%x-W%v')",
            'month'       => "DATE_FORMAT(" . $local . ", '%Y-%m')",
            'hour_of_day' => 'HOUR(' . $local . ')',
            'status'      => 'l.status',
            'asked_by'    => "CASE WHEN l.user_id > 0 THEN 'member' ELSE 'guest' END",
            default       => 'DATE(' . $local . ')',
        };

        $query->select($select . ' AS k, ' . $measures)->group('k');

        $groups = array_map(fn ($row) => array_filter([
            'key'           => \is_numeric($row->k) ? $row->k + 0 : $row->k,
            'label'         => $groupBy === 'status' ? (AskData::STATUSES[$row->k] ?? null) : null,
            'questions'     => (int) $row->n,
            'answered'      => (int) $row->answered,
            'found_nothing' => (int) $row->found_nothing ?: null,
            'failed'        => (int) $row->failed ?: null,
            'blocked'       => (int) $row->blocked ?: null,
            'tokens_in'     => (int) $row->tokens_in ?: null,
            'tokens_out'    => (int) $row->tokens_out ?: null,
            'avg_ms'        => $row->avg_ms !== null ? (int) round((float) $row->avg_ms) : null,
        ], fn ($v) => $v !== null), $db->setQuery($query)->loadObjectList());

        usort($groups, fn ($a, $b) => \in_array($groupBy, ['status', 'asked_by'], true)
            ? $b['questions'] <=> $a['questions']
            : strcmp((string) $a['key'], (string) $b['key']));

        return array_filter([
            'group_by'       => $groupBy,
            'filters'        => $filters ?: null,
            'retention_days' => AskData::retentionDays(),
            'total_questions' => (int) $totals->n,
            'answered'       => (int) $totals->answered,
            'found_nothing'  => (int) $totals->found_nothing,
            'failed'         => (int) $totals->failed,
            'blocked'        => (int) $totals->blocked,
            'answer_rate'    => (int) $totals->n > 0 ? round((int) $totals->answered / (int) $totals->n, 3) : null,
            'tokens_in'      => (int) $totals->tokens_in,
            'tokens_out'     => (int) $totals->tokens_out,
            'flags'          => AskData::hasFlags() ? [
                'injection_suspect' => (int) $totals->injection_suspect,
                'html_payload'      => (int) $totals->html_payload,
                'output_filtered'   => (int) $totals->output_filtered,
            ] : null,
            'groups'         => \array_slice($groups, 0, max(1, min((int) ($args['limit'] ?? 100), 1000))),
        ], fn ($v) => $v !== null);
    }

    public static function overview(array $args, ToolContext $ctx): array
    {
        $days      = max(1, min((int) ($args['days'] ?? 1), 90));
        $from      = self::dayInSiteTime($ctx, $days - 1);
        $recording = AskData::recording();

        $byStatus = self::stats(['group_by' => 'status', 'date_from' => $from], $ctx);
        $byDay    = $days > 1 ? self::stats(['group_by' => 'day', 'date_from' => $from], $ctx)['groups'] : null;

        // The questions worth acting on: the site had nothing relevant to say
        $gaps = $recording['questions']
            ? self::listQuestions(['status' => 'empty', 'date_from' => $from, 'limit' => self::DIGEST_SAMPLE], $ctx)['questions']
            : [];

        $failures = self::listQuestions(['status' => 'error', 'date_from' => $from, 'limit' => 10], $ctx)['questions'] ?? [];

        // What visitors said about the answers they got
        $rated = AskData::hasRatings() && $recording['questions']
            ? self::listQuestions(['rating' => 'bad', 'date_from' => $from, 'limit' => self::DIGEST_SAMPLE], $ctx)['questions']
            : [];
        $since = $ctx->db->quote(Resolver::sqlDate($ctx, $from));
        $count = fn (string $condition) => (int) $ctx->db->setQuery(
            AskData::baseQuery($ctx)->select('COUNT(*)')->where($condition)->where('l.created >= ' . $since)
        )->loadResult();
        $votes = AskData::hasRatings() ? ['good' => $count('l.rating > 0'), 'bad' => $count('l.rating < 0')] : null;

        return array_filter([
            'period'          => ['from' => $from, 'days' => $days],
            'recording'       => $recording,
            'retention_days'  => AskData::retentionDays(),
            'questions'       => $byStatus['total_questions'],
            'answered'        => $byStatus['answered'],
            'answer_rate'     => $byStatus['answer_rate'] ?? null,
            'by_outcome'      => $byStatus['groups'],
            'by_day'          => $byDay,
            'tokens_in'       => $byStatus['tokens_in'],
            'tokens_out'      => $byStatus['tokens_out'],
            'content_gaps'    => $gaps,
            'content_gaps_note' => $gaps
                ? 'These questions found nothing relevant in the site content. Each one is a candidate for an article, or for adding the missing wording to an existing one.'
                : null,
            'failures'        => $failures ?: null,
            'ratings'         => $votes,
            'flags'           => $byStatus['flags'] ?? null,
            'flagged'         => AskData::hasFlags() && $recording['questions']
                ? (self::listQuestions(['flag' => 'any_flag', 'date_from' => $from, 'limit' => 20], $ctx)['questions'] ?: null)
                : null,
            'rated_not_helpful' => $rated ?: null,
            'rated_not_helpful_note' => $rated
                ? 'Visitors said these answers did not help. Look at what they asked and why, then point the question to the right pages with save_source_rule (see list_pending_questions and search_indexed_pages), or improve the content.'
                : null,
            'untrusted_input' => AskData::UNTRUSTED_NOTE,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
