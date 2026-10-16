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
 * One row per question in #__mcpask_log. The rows are what the limits count, so they are written
 * even when questions are not recorded. For the limits the address is kept as a keyed hash, for a
 * day. Who asked (the account, and the address in full) is recorded only when the administrator
 * wants it, and goes when the row does.
 *
 * @since  0.1.0
 */
final class UsageLog
{
    /** Questions one address may have in progress at the same time. */
    private const AT_ONCE = 2;

    /** Seconds after which a question that never finished no longer counts as in progress. */
    private const STALE = 300;

    public function __construct(private DatabaseInterface $db, private Settings $settings, private string $secret)
    {
    }

    /**
     * Record a question and check the limits. The row is written first and counted with the
     * others, so questions sent at the same moment cannot all slip under a limit together.
     *
     * @param   array{minute: int, day: int}|null  $limits  This visitor's limits (0 is no limit), null for none at all
     *
     * @return  array{id: int, limit: ?string}  The ID for finish(), or the limit that was reached
     *                                          ("blocked", "minute", "day" or "site") and no row
     */
    public function start(string $ip, string $question, int $userId = 0, ?array $limits = ['minute' => -1, 'day' => -1]): array
    {
        // The general limits unless the visitor's own were given
        $minute = $limits === null ? 0 : ($limits['minute'] < 0 ? $this->settings->perMinute() : $limits['minute']);
        $day    = $limits === null ? 0 : ($limits['day'] < 0 ? $this->settings->perDay() : $limits['day']);

        $hash   = $this->hash($ip);
        $who    = $this->settings->logWho();
        $record = (object) [
            'created'  => Factory::getDate()->toSql(),
            'ip_hash'  => $hash,
            'user_id'  => $who ? $userId : 0,
            'ip'       => $who ? mb_substr($ip, 0, 45) : '',
            'question' => $this->settings->logQuestions() ? $question : null,
            'status'   => 'started',
        ];

        $this->db->insertObject('#__mcpask_log', $record, 'id');

        // A member is counted by account, so colleagues behind one address do not share a limit
        // (when who asked is recorded; otherwise by address, as for guests)
        $byUser = $who && $userId > 0 ? $userId : 0;

        $limit = match (true) {
            // Too much has been turned away from this address lately: it waits out the hour
            $this->settings->strikes() > 0 && $this->turnedAway($hash) >= $this->settings->strikes() => 'blocked',
            $minute > 0 && $this->count(60, $hash, $byUser) > $minute,
            $this->inProgress($hash) > self::AT_ONCE                                  => 'minute',
            $day > 0 && $this->count(86400, $hash, $byUser) > $day                    => 'day',
            // What was turned away cost nothing, so it does not count towards the site's day either
            $limits !== null && $this->count(86400, null, 0, true) > $this->settings->sitePerDay() => 'site',
            default                                                                   => null,
        };

        if ($limit !== null) {
            // A refused question leaves nothing behind: a flood must not fill the table either
            $this->db->setQuery(
                $this->db->getQuery(true)->delete($this->db->quoteName('#__mcpask_log'))->where('id = ' . (int) $record->id)
            )->execute();

            return ['id' => 0, 'limit' => $limit];
        }

        // Now and then, forget what is older than the administrator wants kept
        if (random_int(1, 20) === 1) {
            $this->prune();
        }

        return ['id' => (int) $record->id, 'limit' => null];
    }

    /**
     * @param  array{status: string, sources?: int, input?: int, output?: int, message?: string}  $result
     */
    public function finish(int $id, array $result, float $startedAt): void
    {
        $record = (object) [
            'id'          => $id,
            'status'      => $result['status'],
            'message'     => mb_substr((string) ($result['message'] ?? ''), 0, 250),
            'sources'     => min(255, (int) ($result['sources'] ?? 0)),
            'tokens_in'   => (int) ($result['input'] ?? 0),
            'tokens_out'  => (int) ($result['output'] ?? 0),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'hint_id'     => (int) (($result['hints'] ?? [])[0] ?? 0),
            'flags'       => mb_substr(implode(',', array_unique((array) ($result['flags'] ?? []))), 0, 255),
        ];

        // The answer as shown, and as the model wrote it when the filter changed it; kept like the question
        if ($this->settings->logQuestions() && isset($result['answer'])) {
            $record->answer     = mb_substr((string) $result['answer'], 0, 60000);
            $record->answer_raw = !empty($result['removed']) ? mb_substr((string) ($result['answer_raw'] ?? ''), 0, 60000) : null;
        }

        $this->db->updateObject('#__mcpask_log', $record, 'id');
    }

    /**
     * What the visitor's browser is given to rate an answer with: the log ID, signed, so no one
     * can rate answers that were given to others.
     */
    public function reference(int $id): string
    {
        return $id . '.' . substr(hash_hmac('sha256', 'rating:' . $id, $this->secret), 0, 24);
    }

    /**
     * Record how the visitor rated an answer: 1 good, -1 bad, with an optional word of why. Only
     * for an answer given in the last day; rating again replaces the rating.
     */
    public function rate(string $reference, int $rating, string $feedback): bool
    {
        if (!preg_match('/^(\d{1,10})\.[0-9a-f]{24}$/', $reference, $match) || !hash_equals($this->reference((int) $match[1]), $reference) || !\in_array($rating, [1, -1], true)) {
            return false;
        }

        $since  = Factory::getDate('-1 day')->toSql();
        $text   = mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($feedback))), 0, 255);
        $query  = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__mcpask_log'))
            ->set('rating = ' . $rating)
            ->set('feedback = :feedback')
            ->where('id = ' . (int) $match[1])
            ->where('created >= :since')
            ->bind(':feedback', $text)
            ->bind(':since', $since);

        $this->db->setQuery($query)->execute();

        return $this->db->getAffectedRows() > 0;
    }

    public function prune(): void
    {
        $before = Factory::getDate('-' . $this->settings->logDays() . ' days')->toSql();
        $query  = $this->db->getQuery(true)
            ->delete($this->db->quoteName('#__mcpask_log'))
            ->where('created < :before')
            ->bind(':before', $before);

        $this->db->setQuery($query)->execute();

        // The limits look back one day. After that the address hash only serves to link a
        // visitor's questions to each other, which nobody needs.
        $before = Factory::getDate('-1 day')->toSql();
        $query  = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__mcpask_log'))
            ->set('ip_hash = ' . $this->db->quote(''))
            ->where('created < :before')
            ->where('ip_hash <> ' . $this->db->quote(''))
            ->bind(':before', $before);

        $this->db->setQuery($query)->execute();
    }

    /**
     * Questions of one address that were turned away as probes within the last hour.
     */
    private function turnedAway(string $hash): int
    {
        $since = Factory::getDate('-1 hour')->toSql();
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__mcpask_log'))
            ->where('status = ' . $this->db->quote('blocked'))
            ->where('ip_hash = :hash')
            ->where('created >= :since')
            ->bind(':hash', $hash)
            ->bind(':since', $since);

        return (int) $this->db->setQuery($query)->loadResult();
    }

    /**
     * Questions of one address that are being answered right now.
     */
    private function inProgress(string $hash): int
    {
        $since = Factory::getDate('-' . self::STALE . ' seconds')->toSql();
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__mcpask_log'))
            ->where('status = ' . $this->db->quote('started'))
            ->where('ip_hash = :hash')
            ->where('created >= :since')
            ->bind(':hash', $hash)
            ->bind(':since', $since);

        return (int) $this->db->setQuery($query)->loadResult();
    }

    /**
     * Questions asked in the last so many seconds, by one account, one address or everyone.
     */
    public function count(int $seconds, ?string $hash = null, int $userId = 0, bool $paidOnly = false): int
    {
        $since = Factory::getDate('-' . $seconds . ' seconds')->toSql();
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__mcpask_log'))
            ->where('created >= :since')
            ->bind(':since', $since);

        if ($paidOnly) {
            $query->where('status <> ' . $this->db->quote('blocked'));
        }

        if ($userId > 0) {
            $query->where('user_id = ' . $userId);
        } elseif ($hash !== null) {
            $query->where('ip_hash = :hash')->bind(':hash', $hash);
        }

        return (int) $this->db->setQuery($query)->loadResult();
    }

    /**
     * @return  object[]  The latest questions, newest first, with the name of who asked when that is known
     */
    /** Marks a question can carry in the log. */
    public const FLAGS = ['injection_suspect', 'html_payload', 'output_filtered'];

    public function recent(int $limit, string $flag = ''): array
    {
        $query = $this->db->getQuery(true)
            ->select('l.created, l.question, l.status, l.message, l.sources, l.tokens_in, l.tokens_out, l.duration_ms, l.user_id, l.ip, l.rating, l.feedback, l.hint_id, l.flags, l.answer, l.answer_raw, u.name, u.username')
            ->from($this->db->quoteName('#__mcpask_log', 'l'))
            ->join('LEFT', $this->db->quoteName('#__users', 'u'), 'u.id = l.user_id')
            ->order('l.id DESC')
            ->setLimit($limit);

        // Only questions with this mark (a known one: the name goes into the query)
        if (\in_array($flag, self::FLAGS, true)) {
            $query->where($this->db->quoteName('l.flags') . ' LIKE ' . $this->db->quote('%' . $flag . '%'));
        }

        return $this->db->setQuery($query)->loadObjectList();
    }

    private function hash(string $ip): string
    {
        // One IPv6 customer has a whole /64 of addresses to choose from, so that is what is counted
        $packed = @inet_pton($ip);

        // An IPv4 address written as IPv6 (::ffff:a.b.c.d) is that IPv4 address, not the /64 of all of them
        if ($packed !== false && \strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
            $ip     = (string) inet_ntop($packed);
        }

        if ($packed !== false && \strlen($packed) === 16) {
            $ip = (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8));
        }

        return hash_hmac('sha256', $ip, $this->secret);
    }
}
