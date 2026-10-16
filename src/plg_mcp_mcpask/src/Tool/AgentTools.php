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
use Gss\Component\Mcpask\Administrator\Agent\Agents;
use Gss\Component\Mcpask\Administrator\Agent\Handoffs;
use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

/**
 * Tools over the agents of "Ask the Site" (0.8.0 and later): which there are, how often they are
 * offered, started and completed, the visitors passed on to people (0.10.0), and the analysis
 * reports written (0.9.0), without anyone's name or contact details.
 *
 * Super-User-only, like the question log: handoffs keep what visitors asked.
 *
 * @since  0.10.0
 */
final class AgentTools
{
    private const DAYS = 30;

    public static function isAvailable(): bool
    {
        return AskData::isAvailable() && class_exists(Agents::class);
    }

    /**
     * @return Tool[]
     */
    public static function tools(): array
    {
        $onlyAdmins = [AskData::class, 'superUserOnly'];
        $days       = ['type' => 'integer', 'description' => 'Default 30.', 'minimum' => 1, 'maximum' => 365];
        $tools      = [
            new Tool(
                'list_agents',
                'List the agents of "Ask the Site": helpers that take over the conversation for one task (filling in a form, '
                . 'passing the visitor on to a person). For each: its kind, whether it is published, how it is offered (listed, '
                . 'trigger phrases, offered by the AI), whether it writes an analysis report, and how often it was offered, '
                . 'started and completed in the last 30 days.',
                ['properties' => [], 'additionalProperties' => false],
                [self::class, 'agents'],
                'List agents',
                Tool::SCOPE_READ,
                'mcpask',
                true,
                $onlyAdmins
            ),
            new Tool(
                'agent_stats',
                'How the agents of "Ask the Site" did over the last days: offered, started, completed and left, with the rate of '
                . 'completion, and, when there is a live-chat agent, how the handoffs came out (chats, messages left, whether '
                . 'someone was there).',
                ['properties' => ['days' => $days], 'additionalProperties' => false],
                [self::class, 'stats'],
                'Agent statistics',
                Tool::SCOPE_READ,
                'mcpask',
                true,
                $onlyAdmins
            ),
        ];

        if (class_exists(Handoffs::class) && self::hasTable('#__mcpask_handoffs')) {
            $tools[] = new Tool(
                'list_handoffs',
                'The visitors of "Ask the Site" passed on to a person (live chat), newest first: when, by which agent, from where '
                . '(a listed button, an offer below an answer, two unhelpful answers, a link, the button above the conversation), '
                . 'within office hours or not, whether someone was there, and what came of it (window opened, chat began, '
                . 'message left, window did not open). With the question asked before (shortened) and the page; no names or '
                . 'contact details are kept.',
                [
                    'properties'           => [
                        'days'         => $days,
                        'outcome'      => ['type' => 'string', 'enum' => Handoffs::OUTCOMES],
                        'availability' => ['type' => 'string', 'enum' => ['online', 'offline']],
                        'limit'        => ['type' => 'integer', 'description' => 'Default 50.', 'minimum' => 1, 'maximum' => 200],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'handoffs'],
                'List handoffs',
                Tool::SCOPE_READ,
                'mcpask',
                true,
                $onlyAdmins
            );
        }

        if (self::hasTable('#__mcpask_reports')) {
            $tools[] = new Tool(
                'list_agent_reports',
                'The analysis reports "Ask the Site" agents wrote for visitors, newest first: the agent, the title, the result type '
                . 'and scores chosen, the section titles, whether the link was e-mailed, and until when it is kept. Nothing that '
                . 'identifies the visitor, and not the report\'s private address.',
                [
                    'properties'           => [
                        'days'  => $days,
                        'limit' => ['type' => 'integer', 'description' => 'Default 30.', 'minimum' => 1, 'maximum' => 100],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'reports'],
                'List agent reports',
                Tool::SCOPE_READ,
                'mcpask',
                true,
                $onlyAdmins
            );
        }

        return $tools;
    }

    public static function agents(array $args, ToolContext $ctx): array
    {
        $agents = new Agents($ctx->db);
        $stats  = $agents->stats(self::DAYS);
        $kinds  = Agents::types(false);

        return ['agents' => array_map(static function ($agent) use ($stats, $kinds) {
            $params = $agent->params;
            $stat   = $stats[(int) $agent->id] ?? [];

            return array_filter([
                'id'          => (int) $agent->id,
                'title'       => (string) $agent->title,
                'kind'        => (string) $agent->type,
                'kind_title'  => isset($kinds[$agent->type]) ? $kinds[$agent->type]->title() : null,
                'published'   => (bool) $agent->published,
                'listed'      => (bool) $params->get('suggest', 1),
                'phrases'     => \count(Agents::phrases((string) $params->get('phrases', ''))) ?: null,
                'ai_offer'    => (bool) $params->get('ai_offer', 0),
                'report'      => $params->get('report', 0) ? true : null,
                'per_day'     => (int) $params->get('per_day', 5),
                'last_30_days' => [
                    'offered'   => $stat['offered'] ?? 0,
                    'started'   => $stat['started'] ?? 0,
                    'completed' => $stat['completed'] ?? 0,
                    'left'      => $stat['left'] ?? 0,
                ],
            ], static fn ($v) => $v !== null);
        }, $agents->all())];
    }

    public static function stats(array $args, ToolContext $ctx): array
    {
        $days   = self::days($args);
        $agents = new Agents($ctx->db);
        $stats  = $agents->stats($days);
        $result = ['days' => $days, 'agents' => []];

        foreach ($agents->all() as $agent) {
            $stat = $stats[(int) $agent->id] ?? [];
            $started = $stat['started'] ?? 0;

            $result['agents'][] = [
                'id'              => (int) $agent->id,
                'title'           => (string) $agent->title,
                'kind'            => (string) $agent->type,
                'offered'         => $stat['offered'] ?? 0,
                'started'         => $started,
                'completed'       => $stat['completed'] ?? 0,
                'left'            => $stat['left'] ?? 0,
                'completion_rate' => $started ? round(($stat['completed'] ?? 0) / $started, 3) : null,
            ];
        }

        if (class_exists(Handoffs::class) && self::hasTable('#__mcpask_handoffs')) {
            $result['handoffs'] = (new Handoffs($ctx->db))->counts($days);
        }

        return $result;
    }

    public static function handoffs(array $args, ToolContext $ctx): array
    {
        [$rows, $total] = (new Handoffs($ctx->db))->list([
            'days'         => self::days($args),
            'outcome'      => (string) ($args['outcome'] ?? ''),
            'availability' => (string) ($args['availability'] ?? ''),
        ], max(1, min((int) ($args['limit'] ?? 50), 200)));

        return [
            'total'           => $total,
            'handoffs'        => array_map(static fn ($row) => array_filter([
                'id'           => (int) $row->id,
                'when'         => Resolver::localDate($ctx, $row->created),
                'agent'        => $row->agent,
                'source'       => $row->source,
                'within_hours' => (bool) $row->hours,
                'availability' => $row->availability ?: null,
                'outcome'      => $row->outcome,
                'question'     => $row->question !== '' ? $row->question : null,
                'page'         => $row->page !== '' ? $row->page : null,
                'with_report'  => (int) $row->report ? true : null,
                'language'     => $row->language,
            ], static fn ($v) => $v !== null), $rows),
            'untrusted_input' => AskData::UNTRUSTED_NOTE,
        ];
    }

    public static function reports(array $args, ToolContext $ctx): array
    {
        $db    = $ctx->db;
        $since = Factory::getDate('-' . self::days($args) . ' days')->toSql();
        $rows  = $db->setQuery(
            $db->getQuery(true)
                ->select('r.id, r.agent_id, a.title AS agent, r.title, r.status, r.language, r.content, r.emailed, r.created, r.expires')
                ->from($db->quoteName('#__mcpask_reports', 'r'))
                ->join('LEFT', $db->quoteName('#__mcpask_agents', 'a'), 'a.id = r.agent_id')
                ->where('r.created >= :since')
                ->bind(':since', $since)
                ->order('r.id DESC'),
            0,
            max(1, min((int) ($args['limit'] ?? 30), 100))
        )->loadObjectList();

        return [
            'reports'         => array_map(static function ($row) use ($ctx) {
                $content = json_decode((string) $row->content, true) ?: [];

                // The greeting holds the visitor's name, the key the private address: neither is given
                return array_filter([
                    'id'       => (int) $row->id,
                    'agent'    => $row->agent,
                    'title'    => $row->title,
                    'status'   => $row->status,
                    'type'     => $content['type']['name'] ?? null,
                    'scores'   => array_map(static fn ($score) => ['name' => (string) ($score['name'] ?? ''), 'score' => (int) ($score['score'] ?? 0)], (array) ($content['scores'] ?? [])) ?: null,
                    'sections' => array_map(static fn ($section) => (string) ($section['title'] ?? ''), (array) ($content['sections'] ?? [])) ?: null,
                    'pages_found' => \count((array) ($content['sources'] ?? [])) ?: null,
                    'emailed'  => (bool) $row->emailed,
                    'language' => $row->language,
                    'created'  => Resolver::localDate($ctx, $row->created),
                    'kept_until' => Resolver::localDate($ctx, $row->expires),
                ], static fn ($v) => $v !== null);
            }, $rows),
            'untrusted_input' => AskData::UNTRUSTED_NOTE,
        ];
    }

    private static function days(array $args): int
    {
        return max(1, min((int) ($args['days'] ?? self::DAYS), 365));
    }

    private static function hasTable(string $table): bool
    {
        static $tables = null;

        if ($tables === null) {
            $db     = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $tables = $db->getTableList();
        }

        return \in_array(Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class)->replacePrefix($table), $tables, true);
    }
}
