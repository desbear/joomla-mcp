<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Controller;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Utilities\IpHelper;

\defined('_JEXEC') or die;

/**
 * Activity log actions.
 *
 * @since  0.1.0
 */
class LogsController extends BaseController
{
    /**
     * Delete all activity log entries.
     */
    public function purge(): void
    {
        $this->checkToken();

        // Destroying the audit trail needs more than core.delete, which Joomla grants to Manager by
        // default - weaker than the core.manage needed just to see this page
        if (!$this->app->getIdentity()->authorise('core.admin', 'com_mcp')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $stats = $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*) AS total, MAX(' . $db->quoteName('id') . ') AS max_id')
                ->from($db->quoteName('#__mcp_logs'))
        )->loadAssoc() ?: [];

        $removed = (int) ($stats['total'] ?? 0);
        $marker  = (int) ($stats['max_id'] ?? 0);

        // Recorded before the delete, and left out of it by the id boundary, so the first entry
        // remaining after a purge names who emptied the log and how much was lost
        $this->logAdminAction($db, 'admin:purge_logs', Text::sprintf('COM_MCP_LOG_AUDIT_PURGE', $removed));

        // DELETE, not truncateTable(): TRUNCATE resets AUTO_INCREMENT, which hides that ids are missing
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__mcp_logs'))
            ->where($db->quoteName('id') . ' <= :marker')
            ->bind(':marker', $marker, ParameterType::INTEGER);

        $db->setQuery($query)->execute();

        $this->setRedirect(Route::_('index.php?option=com_mcp&view=logs', false), Text::_('COM_MCP_LOGS_PURGED'));
    }

    /**
     * Record an administrative action in #__mcp_logs itself, filling every NOT NULL column.
     */
    private function logAdminAction(DatabaseInterface $db, string $tool, string $message): void
    {
        $row = (object) [
            'created'     => Factory::getDate()->toSql(),
            'user_id'     => (int) $this->app->getIdentity()->id,
            'tool'        => $tool,
            'arguments'   => '',
            'status'      => 'success',
            'message'     => mb_substr($message, 0, 2000),
            'duration_ms' => 0,
            'ip'          => mb_substr((string) IpHelper::getIp(), 0, 64),
            'user_agent'  => mb_substr($this->input->server->getString('HTTP_USER_AGENT', ''), 0, 255),
        ];

        try {
            $db->insertObject('#__mcp_logs', $row);
        } catch (\Throwable) {
            // A missing audit row must not stop the action the admin asked for
        }
    }
}
