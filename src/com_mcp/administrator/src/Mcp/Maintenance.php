<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Mcp;

use Gss\Component\Mcp\Administrator\OAuth\OAuthServer;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * What MCP Server keeps, forgotten every day (0.11.0): the activity log after its retention period
 * (and the addresses in it after a week), OAuth codes, revoked and expired tokens, and clients
 * nobody uses. Run by the scheduled task "MCP Server - Forget old records"; each run is recorded in
 * the activity log, for the dashboard.
 *
 * @since  0.11.0
 */
final class Maintenance
{
    public const EVENT = 'maintenance:forget';

    /**
     * @return  array<string, int>  What was removed, by kind
     */
    public static function run(DatabaseInterface $db): array
    {
        $params = ComponentHelper::getParams('com_mcp');
        $log    = Logger::forget($db, (int) $params->get('log_retention_days', 30));
        $oauth  = OAuthServer::create()->cleanup(true);

        $counts = [
            'calls_deleted'     => $log['deleted'],
            'addresses_cleared' => $log['addresses'],
            'codes'             => $oauth['codes'],
            'tokens'            => $oauth['tokens'],
            'clients'           => $oauth['clients'],
        ];

        Logger::security($db, self::EVENT, (string) json_encode($counts), 0, '', 'success');

        return $counts;
    }

    /**
     * When it last ran (UTC, SQL format) and what it removed; null when it has not in the log's time.
     *
     * @return  array{at: string, counts: array<string, int>}|null
     */
    public static function last(DatabaseInterface $db): ?array
    {
        $row = $db->setQuery(
            $db->getQuery(true)->select([$db->quoteName('created'), $db->quoteName('message')])->from($db->quoteName('#__mcp_logs'))
                ->where($db->quoteName('tool') . ' = ' . $db->quote(self::EVENT))->order($db->quoteName('id') . ' DESC')->setLimit(1)
        )->loadObject();

        return $row ? ['at' => (string) $row->created, 'counts' => array_map('intval', (array) json_decode((string) $row->message, true))] : null;
    }
}
