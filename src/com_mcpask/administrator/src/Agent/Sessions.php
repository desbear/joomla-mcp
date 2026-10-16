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

\defined('_JEXEC') or die;

/**
 * Agent conversations: what an agent keeps between turns, on the server, encrypted with the site's
 * secret (what a visitor fills in is personal), for a day at most. The visitor's browser holds
 * only a random key; the record is found by its hash, and only from the same visitor.
 *
 * @since  0.8.0
 */
final class Sessions
{
    /** Turns one conversation may take. */
    public const MAX_STEPS = 80;

    public function __construct(private DatabaseInterface $db, private string $secret)
    {
    }

    /**
     * @return  string  The key the visitor's browser keeps
     */
    public function create(int $agentId, string $visitor, array $state): string
    {
        $key = bin2hex(random_bytes(24));
        $now = Factory::getDate();
        $row = (object) [
            'id'       => hash('sha256', $key),
            'agent_id' => $agentId,
            'visitor'  => $visitor,
            'status'   => 'active',
            'steps'    => 0,
            'state'    => $this->seal($state, $key),
            'created'  => $now->toSql(),
            'modified' => $now->toSql(),
            'expires'  => Factory::getDate('+' . Agents::SESSION_HOURS . ' hours')->toSql(),
        ];

        $this->db->insertObject('#__mcpask_agent_sessions', $row);
        $this->forgetExpired();

        return $key;
    }

    /**
     * The conversation of a key, when it is this visitor's, still open and not too long.
     *
     * @return  array{agent_id: int, steps: int, state: array}|null
     */
    public function open(string $key, string $visitor): ?array
    {
        if (!preg_match('/^[0-9a-f]{48}$/', $key)) {
            return null;
        }

        $db  = $this->db;
        $now = Factory::getDate()->toSql();
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select('agent_id, visitor, status, steps, state')
                ->from($db->quoteName('#__mcpask_agent_sessions'))
                ->where('id = :id')
                ->where('expires > :now')
                ->bind(':id', hash('sha256', $key))
                ->bind(':now', $now)
        )->loadObject();

        if (!$row || !hash_equals((string) $row->visitor, $visitor) || $row->status !== 'active' || (int) $row->steps >= self::MAX_STEPS) {
            return null;
        }

        $state = $this->unseal((string) $row->state, $key);

        return $state === null ? null : ['agent_id' => (int) $row->agent_id, 'steps' => (int) $row->steps, 'state' => $state];
    }

    public function save(string $key, array $state, string $status = 'active'): void
    {
        $db  = $this->db;
        $id  = hash('sha256', $key);
        // A finished conversation keeps nothing of what was filled in
        $sealed = $status === 'active' ? $this->seal($state, $key) : '';
        $now    = Factory::getDate()->toSql();

        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__mcpask_agent_sessions'))
                ->set('state = :state')
                ->set('status = :status')
                ->set('steps = steps + 1')
                ->set('modified = :now')
                ->where('id = :id')
                ->bind(':state', $sealed)
                ->bind(':status', $status)
                ->bind(':now', $now)
                ->bind(':id', $id)
        )->execute();
    }

    /**
     * Conversations a visitor started today with an agent, for its daily limit.
     */
    public function startedToday(int $agentId, string $visitor): int
    {
        $db    = $this->db;
        $since = Factory::getDate('today')->toSql();

        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__mcpask_agent_sessions'))
                ->where('agent_id = ' . $agentId)
                ->where('visitor = :visitor')
                ->where('created >= :since')
                ->bind(':visitor', $visitor)
                ->bind(':since', $since)
        )->loadResult();
    }

    private function forgetExpired(): void
    {
        $db  = $this->db;
        $now = Factory::getDate()->toSql();

        $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__mcpask_agent_sessions'))->where('expires <= :now')->bind(':now', $now))->execute();
    }

    /**
     * Encrypted with a key made of the site's secret and the visitor's own key: neither the
     * database alone nor the key alone opens it.
     */
    private function seal(array $state, string $key): string
    {
        $iv     = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt(json_encode($state, JSON_UNESCAPED_UNICODE), 'aes-256-gcm', $this->keyFor($key), OPENSSL_RAW_DATA, $iv, $tag);

        return base64_encode($iv . $tag . $cipher);
    }

    private function unseal(string $sealed, string $key): ?array
    {
        $raw = base64_decode($sealed, true);

        if ($raw === false || \strlen($raw) < 29) {
            return null;
        }

        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->keyFor($key), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        $state = $plain === false ? null : json_decode($plain, true);

        return \is_array($state) ? $state : null;
    }

    private function keyFor(string $key): string
    {
        return hash_hmac('sha256', $key, 'mcpask-agent|' . $this->secret, true);
    }
}
