<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Agent;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * The log of visitors passed on to people (live chat): when, from where, whether within office
 * hours, whether someone was there, and what came of it (a chat, a message left). Only a summary
 * is kept: the question (cut short), the page, whether there was a report; no names or addresses.
 *
 * A visitor's page tells what happens in the chat window by a key it was given, for a day.
 *
 * @since  0.10.0
 */
final class Handoffs
{
    /** A link to this anywhere on the site (a report's button among them) passes the visitor on */
    public const LINK = '#mcp-ask-handoff';

    /** How a visitor came to ask for a person */
    public const SOURCES = ['list', 'offer', 'unhelpful', 'link', 'button'];

    /** What may come of it, in order: a later outcome never gives way to an earlier one */
    public const OUTCOMES = ['requested', 'failed', 'opened', 'message', 'chatting'];

    private const KEEP_DAYS = 365;

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * @param  array{source?: string, question?: string, page?: string, report?: string}  $entry  What the page said about it
     *
     * @return  string  The key the page reports with
     */
    public function create(int $agentId, string $visitor, array $entry, bool $hours, string $language): string
    {
        $key = bin2hex(random_bytes(24));
        $now = Factory::getDate()->toSql();

        $row = (object) [
            'token'        => hash('sha256', $key),
            'agent_id'     => $agentId,
            'visitor'      => $visitor,
            'source'       => \in_array($entry['source'] ?? '', self::SOURCES, true) ? $entry['source'] : 'list',
            'hours'        => (int) $hours,
            'availability' => '',
            'outcome'      => 'requested',
            'question'     => mb_substr(trim((string) ($entry['question'] ?? '')), 0, 300),
            'page'         => mb_substr((string) ($entry['page'] ?? ''), 0, 500),
            'report'       => (int) (($entry['report'] ?? '') !== ''),
            'language'     => mb_substr($language, 0, 7),
            'created'      => $now,
            'modified'     => $now,
        ];

        $this->db->insertObject('#__mcpask_handoffs', $row);
        $this->forgetOld();

        return $key;
    }

    /**
     * What happened in the chat window: it opened (and whether someone was there), a chat began,
     * a message was left, or it would not load. Only the visitor it was made for may tell it.
     */
    public function event(string $key, string $visitor, string $event, string $availability = ''): bool
    {
        if (!preg_match('/^[0-9a-f]{48}$/', $key) || !\in_array($event, self::OUTCOMES, true) || $event === 'requested') {
            return false;
        }

        $db    = $this->db;
        $token = hash('sha256', $key);
        $since = Factory::getDate('-1 day')->toSql();
        $row   = $db->setQuery(
            $db->getQuery(true)->select('id, visitor, outcome, availability')->from($db->quoteName('#__mcpask_handoffs'))
                ->where('token = :token')->where('created > :since')
                ->bind(':token', $token)->bind(':since', $since)
        )->loadObject();

        if (!$row || !hash_equals((string) $row->visitor, $visitor)) {
            return false;
        }

        $update = (object) ['id' => (int) $row->id, 'modified' => Factory::getDate()->toSql()];

        if (array_search($event, self::OUTCOMES, true) > array_search($row->outcome, self::OUTCOMES, true)) {
            $update->outcome = $event;
        }

        if (\in_array($availability, ['online', 'offline'], true)) {
            $update->availability = $availability;
        }

        $db->updateObject('#__mcpask_handoffs', $update, 'id');

        return true;
    }

    /**
     * Handoffs, newest first.
     *
     * @param  array{agent?: int, outcome?: string, availability?: string, days?: int}  $filter
     *
     * @return  array{0: object[], 1: int}  The rows of the page, and how many there are
     */
    public function list(array $filter = [], int $limit = 50, int $offset = 0): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('h.id, h.agent_id, a.title AS agent, h.source, h.hours, h.availability, h.outcome, h.question, h.page, h.report, h.language, h.created, h.modified')
            ->from($db->quoteName('#__mcpask_handoffs', 'h'))
            ->join('LEFT', $db->quoteName('#__mcpask_agents', 'a'), 'a.id = h.agent_id');

        if (!empty($filter['agent'])) {
            $agent = (int) $filter['agent'];
            $query->where('h.agent_id = :agent')->bind(':agent', $agent, ParameterType::INTEGER);
        }

        if (\in_array($filter['outcome'] ?? '', self::OUTCOMES, true)) {
            $outcome = $filter['outcome'];
            $query->where('h.outcome = :outcome')->bind(':outcome', $outcome);
        }

        if (\in_array($filter['availability'] ?? '', ['online', 'offline'], true)) {
            $availability = $filter['availability'];
            $query->where('h.availability = :availability')->bind(':availability', $availability);
        }

        if (!empty($filter['days'])) {
            $since = Factory::getDate('-' . max(1, (int) $filter['days']) . ' days')->toSql();
            $query->where('h.created >= :since')->bind(':since', $since);
        }

        $count = clone $query;
        $count->clear('select')->select('COUNT(*)');
        $total = (int) $db->setQuery($count)->loadResult();

        $rows = $db->setQuery($query->order('h.id DESC'), max(0, $offset), max(1, min(1000, $limit)))->loadObjectList();

        return [$rows, $total];
    }

    /**
     * How the handoffs of the last days came out, by outcome and by whether someone was there.
     *
     * @return  array{total: int, outcome: array<string, int>, availability: array<string, int>, hours: int}
     */
    public function counts(int $days = 30): array
    {
        $db     = $this->db;
        $since  = Factory::getDate('-' . max(1, $days) . ' days')->toSql();
        $counts = ['total' => 0, 'outcome' => array_fill_keys(self::OUTCOMES, 0), 'availability' => ['online' => 0, 'offline' => 0], 'hours' => 0];

        foreach ($db->setQuery(
            $db->getQuery(true)->select('outcome, availability, hours, COUNT(*) AS n')->from($db->quoteName('#__mcpask_handoffs'))
                ->where('created >= :since')->bind(':since', $since)->group('outcome, availability, hours')
        )->loadObjectList() as $row) {
            $n = (int) $row->n;
            $counts['total'] += $n;
            $counts['hours'] += (int) $row->hours ? $n : 0;

            if (isset($counts['outcome'][$row->outcome])) {
                $counts['outcome'][$row->outcome] += $n;
            }

            if (isset($counts['availability'][$row->availability])) {
                $counts['availability'][$row->availability] += $n;
            }
        }

        return $counts;
    }

    private function forgetOld(): void
    {
        $before = Factory::getDate('-' . self::KEEP_DAYS . ' days')->toSql();
        $this->db->setQuery($this->db->getQuery(true)->delete($this->db->quoteName('#__mcpask_handoffs'))->where('created < :before')->bind(':before', $before))->execute();
    }
}
