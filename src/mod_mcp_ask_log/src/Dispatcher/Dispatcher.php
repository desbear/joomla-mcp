<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  mod_mcp_ask_log
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Module\McpAskLog\Administrator\Dispatcher;

use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Gss\Component\Mcpask\Administrator\Ask\UsageLog;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The latest questions asked through "Ask the Site", for the back-end home page.
 */
class Dispatcher extends AbstractModuleDispatcher
{
    /**
     * Only for those who may open the component, and only while it is there.
     */
    public function dispatch()
    {
        $user = $this->getApplication()->getIdentity();

        if (ComponentHelper::isEnabled('com_mcpask') && $user && $user->authorise('core.manage', 'com_mcpask')) {
            parent::dispatch();
        }
    }

    protected function getLayoutData(): array
    {
        $data = parent::getLayoutData();
        $app  = $this->getApplication();
        $log  = new UsageLog(Factory::getContainer()->get(DatabaseInterface::class), Settings::load(), (string) $app->get('secret'));

        // The list is the component's, and so are its texts
        $app->getLanguage()->load('com_mcpask', JPATH_ADMINISTRATOR);

        $data['rows']  = $log->recent(max(1, min(100, (int) $data['params']->get('count', 10))));
        $data['today'] = $log->count(86400);

        return $data;
    }
}
