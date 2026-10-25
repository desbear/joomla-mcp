<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Mcp;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Utilities\IpHelper;

\defined('_JEXEC') or die;

/**
 * Writes the MCP activity log (#__mcp_logs), shown in Components > MCP Server > Activity Log.
 *
 * @since  0.1.0
 */
final class Logger
{
    /** Characters of a call's arguments kept, at most (0.11.0) */
    private const ARGUMENTS_KEPT = 2000;

    /** Days the address of a call is kept; the rate limit only looks back minutes (0.11.0) */
    public const IP_DAYS = 7;

    /** Argument names whose values are never kept */
    private const SECRET_NAMES = '/pass|secret|token|api[_-]?key|auth|credential|cookie|session|private/i';

    public function __construct(private readonly ToolContext $context)
    {
    }

    public function log(string $tool, array $arguments, bool $success, string $message, int $durationMs): void
    {
        $params = $this->context->params;

        if (!$params->get('log_enabled', 1)) {
            return;
        }

        $db     = $this->context->db;
        $server = $this->context->app->getInput()->server;

        self::write(
            $db,
            (int) $this->context->user->id,
            $tool,
            $success ? 'success' : 'error',
            $message,
            // Kept only when asked for, with what opens an account or names a person hidden, and short (0.11.0)
            $params->get('log_arguments', 0) ? mb_substr(Server::json(self::redact($arguments)), 0, self::ARGUMENTS_KEPT) : '',
            $durationMs,
            $server->getString('HTTP_USER_AGENT', '')
        );

        try {
            // Occasionally remove entries older than the retention period
            if (random_int(1, 100) === 1) {
                self::forget($this->context->db, (int) $params->get('log_retention_days', 30));
            }
        } catch (\Throwable) {
            // Logging must never break a tool call
        }
    }

    /**
     * Record a security event: a rejected credential, or an administrator destroying the trail.
     *
     * Unlike tool calls these are written whatever the logging options say. They are what makes
     * abuse visible afterwards, and the rate limit counts them.
     */
    public static function security(DatabaseInterface $db, string $event, string $message, int $userId = 0, string $userAgent = '', string $status = 'error'): void
    {
        self::write($db, $userId, $event, $status, $message, '', 0, $userAgent);
    }

    /**
     * How many rows a tool (or a group of tools sharing a prefix) produced recently, for one IP
     * address or one user. Used by the endpoint's rate limit.
     */
    public static function countRecent(DatabaseInterface $db, string $tool, int $seconds, ?int $userId = null, ?string $ip = null): int
    {
        $since = Factory::getDate('-' . max(1, $seconds) . ' seconds')->toSql();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__mcp_logs'))
            ->where($db->quoteName('created') . ' >= :since')
            ->bind(':since', $since);

        if (str_ends_with($tool, '*')) {
            $like = $db->escape(rtrim($tool, '*'), true) . '%';
            $query->where($db->quoteName('tool') . ' LIKE ' . $db->quote($like, false));
        } else {
            $query->where($db->quoteName('tool') . ' = :tool')->bind(':tool', $tool);
        }

        if ($userId !== null) {
            $query->where($db->quoteName('user_id') . ' = :user')->bind(':user', $userId, ParameterType::INTEGER);
        }

        if ($ip !== null) {
            $query->where($db->quoteName('ip') . ' = :ip')->bind(':ip', $ip);
        }

        try {
            return (int) $db->setQuery($query)->loadResult();
        } catch (\Throwable) {
            // A rate limit that cannot be counted must not block legitimate traffic
            return 0;
        }
    }

    /**
     * Insert one row, never throwing.
     */
    private static function write(
        DatabaseInterface $db,
        int $userId,
        string $tool,
        string $status,
        string $message,
        string $arguments,
        int $durationMs,
        string $userAgent
    ): void {
        $row = (object) [
            'created'     => Factory::getDate()->toSql(),
            'user_id'     => $userId,
            'tool'        => mb_substr($tool, 0, 100),
            'arguments'   => mb_substr($arguments, 0, 20000),
            'status'      => $status,
            'message'     => mb_substr($message, 0, 2000),
            'duration_ms' => $durationMs,
            'ip'          => mb_substr((string) IpHelper::getIp(), 0, 64),
            'user_agent'  => mb_substr($userAgent, 0, 255),
        ];

        try {
            $db->insertObject('#__mcp_logs', $row);
        } catch (\Throwable) {
            // Logging must never break a request
        }
    }

    /**
     * Calls older than the retention period deleted, and the address of those older than a week
     * cleared (0.11.0). 0 days keeps them all.
     *
     * @return  array{deleted: int, addresses: int}
     */
    public static function forget(DatabaseInterface $db, int $days): array
    {
        $counts = ['deleted' => 0, 'addresses' => 0];

        if ($days > 0) {
            $limit = Factory::getDate('-' . $days . ' days')->toSql();
            $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__mcp_logs'))->where($db->quoteName('created') . ' < :limit')->bind(':limit', $limit))->execute();
            $counts['deleted'] = $db->getAffectedRows();
        }

        $limit = Factory::getDate('-' . self::IP_DAYS . ' days')->toSql();
        $db->setQuery(
            $db->getQuery(true)->update($db->quoteName('#__mcp_logs'))->set($db->quoteName('ip') . ' = ' . $db->quote(''))
                ->where($db->quoteName('created') . ' < :limit')->where($db->quoteName('ip') . ' <> ' . $db->quote(''))->bind(':limit', $limit)
        )->execute();
        $counts['addresses'] = $db->getAffectedRows();

        return $counts;
    }

    /**
     * Arguments with secrets (by name) and e-mail addresses, telephone and card numbers hidden.
     */
    public static function redact(mixed $value, string $name = ''): mixed
    {
        if ($name !== '' && preg_match(self::SECRET_NAMES, $name)) {
            return '[secret]';
        }

        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::redact($item, \is_string($key) ? $key : $name);
            }

            return $value;
        }

        if (!\is_string($value)) {
            return $value;
        }

        return (string) preg_replace([
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u',
            '/(?<![\d\-])(?:\+\d{1,3}[\s\-]?|\(?0)\d{1,4}\)?[\s\-]?\d{3,4}[\s\-]?\d{3,4}(?![\d\-])/u',
            '/\b(?:\d[ \-]?){13,19}\b/u',
            '/\b(bearer|basic)\s+[A-Za-z0-9._~+\/=\-]{12,}/iu',
        ], ['[email]', '[phone]', '[number]', '$1 [secret]'], $value);
    }
}
