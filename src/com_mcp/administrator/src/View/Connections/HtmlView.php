<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\View\Connections;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

\defined('_JEXEC') or die;

/**
 * Connected AI apps (OAuth grants).
 *
 * @since  0.2.0
 */
class HtmlView extends BaseHtmlView
{
    public array $items = [];

    public $pagination;

    public $state;

    public $filterForm;

    public array $activeFilters = [];

    public function display($tpl = null): void
    {
        /** @var \Gss\Component\Mcp\Administrator\Model\ConnectionsModel $model */
        $model = $this->getModel();

        $this->items         = $model->getItems() ?: [];
        $this->pagination    = $model->getPagination();
        $this->state         = $model->getState();
        $this->filterForm    = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();

        $user = Factory::getApplication()->getIdentity();

        ToolbarHelper::title(Text::_('COM_MCP') . ' — ' . Text::_('COM_MCP_SUBMENU_CONNECTIONS'), 'lock');

        if ($user->authorise('core.admin', 'com_mcp') && $this->items) {
            ToolbarHelper::custom('connections.revoke', 'unpublish', '', 'COM_MCP_CONNECTIONS_REVOKE', true);
            ToolbarHelper::custom('connections.revokeAll', 'delete', '', 'COM_MCP_CONNECTIONS_REVOKE_ALL', false);
        }

        if ($user->authorise('core.admin', 'com_mcp') || $user->authorise('core.options', 'com_mcp')) {
            ToolbarHelper::preferences('com_mcp');
        }

        parent::display($tpl);
    }
}
