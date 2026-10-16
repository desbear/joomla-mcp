<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

\defined('_JEXEC') or die;

/**
 * Active OAuth connections: one row per grant (token family) that still has a valid refresh token.
 *
 * @since  0.2.0
 */
class ConnectionsModel extends ListModel
{
    public function __construct($config = [], ?\Joomla\CMS\MVC\Factory\MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['created', 't.created', 'last_used', 't.last_used', 'user_id', 't.user_id', 'client_name', 'c.client_name'];
        }

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 't.created', $direction = 'desc')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getListQuery(): QueryInterface
    {
        $db    = $this->getDatabase();
        $now   = Factory::getDate()->toSql();
        $query = $db->getQuery(true)
            ->select('t.family, t.user_id, t.scopes, t.created, t.last_used, t.expires, t.client_id')
            ->select('c.client_name, c.redirect_uris, u.name AS user_name, u.username')
            ->select('(SELECT MIN(f.created) FROM ' . $db->quoteName('#__mcp_oauth_tokens') . ' f WHERE f.family = t.family) AS connected')
            ->from($db->quoteName('#__mcp_oauth_tokens', 't'))
            ->join('LEFT', $db->quoteName('#__mcp_oauth_clients', 'c'), 'c.client_id = t.client_id')
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = t.user_id')
            ->where('t.type = ' . $db->quote('refresh'))
            ->where('t.revoked = 0')
            ->where('t.expires > :now')
            ->bind(':now', $now);

        // The component is only gated on core.manage, so without this every back-end user would see
        // which AI app every other user has connected. filter.user_id below is a UI filter, not a scope.
        $viewer = Factory::getApplication()->getIdentity();

        if (!$viewer->authorise('core.admin', 'com_mcp')) {
            $ownId = (int) $viewer->id;
            $query->where('t.user_id = :owner')->bind(':owner', $ownId, ParameterType::INTEGER);
        }

        if ($userId = (int) $this->getState('filter.user_id')) {
            $query->where('t.user_id = :user')->bind(':user', $userId, ParameterType::INTEGER);
        }

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where('(c.client_name LIKE :s1 OR u.name LIKE :s2 OR u.username LIKE :s3)')
                ->bind(':s1', $like)
                ->bind(':s2', $like)
                ->bind(':s3', $like);
        }

        $query->order($db->escape($this->getState('list.ordering', 't.created')) . ' ' . $db->escape($this->getState('list.direction', 'DESC')));

        return $query;
    }
}
