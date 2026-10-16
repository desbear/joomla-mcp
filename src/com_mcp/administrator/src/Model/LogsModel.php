<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Model;

use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

\defined('_JEXEC') or die;

/**
 * MCP activity log list.
 *
 * @since  0.1.0
 */
class LogsModel extends ListModel
{
    public function __construct($config = [], ?\Joomla\CMS\MVC\Factory\MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id', 'l.id', 'created', 'l.created', 'tool', 'l.tool', 'status', 'l.status', 'user_id', 'l.user_id', 'duration_ms', 'l.duration_ms'];
        }

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'l.id', $direction = 'desc')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getListQuery(): QueryInterface
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('l.*, u.name AS user_name, u.username')
            ->from($db->quoteName('#__mcp_logs', 'l'))
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = l.user_id');

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where('(l.tool LIKE :s1 OR l.arguments LIKE :s2 OR l.message LIKE :s3)')
                ->bind(':s1', $like)
                ->bind(':s2', $like)
                ->bind(':s3', $like);
        }

        if ($status = $this->getState('filter.status')) {
            $query->where('l.status = :status')->bind(':status', $status);
        }

        if ($tool = $this->getState('filter.tool')) {
            $query->where('l.tool = :tool')->bind(':tool', $tool);
        }

        if ($userId = (int) $this->getState('filter.user_id')) {
            $query->where('l.user_id = :user')->bind(':user', $userId, ParameterType::INTEGER);
        }

        $query->order($db->escape($this->getState('list.ordering', 'l.id')) . ' ' . $db->escape($this->getState('list.direction', 'DESC')));

        return $query;
    }

    /**
     * Distinct tool names for the filter.
     */
    public function getToolNames(): array
    {
        $db = $this->getDatabase();

        return $db->setQuery($db->getQuery(true)->select('DISTINCT tool')->from($db->quoteName('#__mcp_logs'))->order('tool'))->loadColumn();
    }
}
