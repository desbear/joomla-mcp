<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Controller;

use Gss\Component\Mcp\Administrator\OAuth\OAuthServer;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Utilities\IpHelper;

\defined('_JEXEC') or die;

/**
 * Revoke OAuth connections.
 *
 * @since  0.2.0
 */
class ConnectionsController extends BaseController
{
    public function revoke(): void
    {
        $this->checkToken();
        $this->assertAllowed();

        $families = array_filter((array) $this->input->get('cid', [], 'array'), fn ($f) => preg_match('/^[a-f0-9]{32}$/', (string) $f));
        $server   = OAuthServer::create();

        foreach ($families as $family) {
            $server->revokeFamily($family);
        }

        // Revoking somebody else's grant is an administrative act; record it in the activity log
        $this->logAdminAction('admin:revoke_connections', Text::sprintf('COM_MCP_LOG_AUDIT_REVOKE', \count($families), implode(', ', $families)));

        $this->setRedirect(
            Route::_('index.php?option=com_mcp&view=connections', false),
            Text::plural('COM_MCP_CONNECTIONS_N_REVOKED', \count($families))
        );
    }

    public function revokeAll(): void
    {
        $this->checkToken();
        $this->assertAllowed();

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $db->setQuery($db->getQuery(true)->update($db->quoteName('#__mcp_oauth_tokens'))->set($db->quoteName('revoked') . ' = 1'))->execute();

        // This signs out every user's assistants at once; without a record nothing would show it happened
        $this->logAdminAction('admin:revoke_connections', Text::sprintf('COM_MCP_LOG_AUDIT_REVOKE_ALL', (int) $db->getAffectedRows()));

        $this->setRedirect(Route::_('index.php?option=com_mcp&view=connections', false), Text::_('COM_MCP_CONNECTIONS_ALL_REVOKED'));
    }

    /**
     * Every registered client that holds no live token, deleted (0.11.0): what was registered and
     * never connected, or whose connections have all ended.
     */
    public function deleteClients(): void
    {
        $this->checkToken();
        $this->assertAllowed();

        $deleted = OAuthServer::create()->deleteUnusedClients();

        $this->logAdminAction('admin:delete_clients', Text::sprintf('COM_MCP_LOG_AUDIT_DELETE_CLIENTS', $deleted));

        $this->setRedirect(Route::_('index.php?option=com_mcp&view=connections', false), Text::plural('COM_MCP_CONNECTIONS_N_CLIENTS_DELETED', $deleted));
    }

    private function assertAllowed(): void
    {
        // core.admin on com_mcp, not core.delete: revoking reaches into other users' connections
        if (!$this->app->getIdentity()->authorise('core.admin', 'com_mcp')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * Record an administrative action in #__mcp_logs, filling every NOT NULL column.
     */
    private function logAdminAction(string $tool, string $message): void
    {
        $db  = Factory::getContainer()->get(DatabaseInterface::class);
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
