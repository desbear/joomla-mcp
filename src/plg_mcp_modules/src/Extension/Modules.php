<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_modules
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Modules\Extension;

use Gss\Plugin\Mcp\Modules\Tool\ModuleTools;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Provides the modules tools.
 *
 * @since  0.1.0
 */
final class Modules extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onMcpRegisterTools' => 'onMcpRegisterTools'];
    }

    public function onMcpRegisterTools(EventInterface $event): void
    {
        $registry = $event->getArgument('registry');

        foreach (ModuleTools::tools() as $tool) {
            $registry->add($tool);
        }
    }
}
