<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\View\Logs;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

\defined('_JEXEC') or die;

/**
 * MCP activity log.
 *
 * @since  0.1.0
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
        $user = Factory::getApplication()->getIdentity();

        // The component itself is only gated on core.manage (Administrator by default), but this log
        // holds every user's raw tool arguments, so reading it needs its own deny-by-default action.
        // Super Users pass because core.admin short-circuits authorise().
        if (!$user->authorise('mcp.viewlog', 'com_mcp')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        /** @var \Gss\Component\Mcp\Administrator\Model\LogsModel $model */
        $model = $this->getModel();

        $this->items         = $model->getItems() ?: [];
        $this->pagination    = $model->getPagination();
        $this->state         = $model->getState();
        $this->filterForm    = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();

        ToolbarHelper::title(Text::_('COM_MCP') . ' — ' . Text::_('COM_MCP_SUBMENU_LOGS'), 'list');

        if ($user->authorise('core.admin', 'com_mcp') && $this->items) {
            ToolbarHelper::custom('logs.purge', 'delete', '', 'COM_MCP_LOGS_PURGE', false);
        }

        if ($user->authorise('core.admin', 'com_mcp') || $user->authorise('core.options', 'com_mcp')) {
            ToolbarHelper::preferences('com_mcp');
        }

        parent::display($tpl);
    }
}
