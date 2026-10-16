<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_stats
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Stats\Extension;

use Gss\Plugin\Mcp\Stats\Tool\StatsTools;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Provides the content statistics tools.
 *
 * @since  0.1.0
 */
final class Stats extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onMcpRegisterTools' => 'onMcpRegisterTools'];
    }

    public function onMcpRegisterTools(EventInterface $event): void
    {
        $registry = $event->getArgument('registry');

        foreach (StatsTools::tools() as $tool) {
            $registry->add($tool);
        }
    }
}
