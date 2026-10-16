<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Mcpask\Extension;

use Gss\Plugin\Mcp\Mcpask\Tool\AgentTools;
use Gss\Plugin\Mcp\Mcpask\Tool\AskTools;
use Gss\Plugin\Mcp\Mcpask\Tool\RuleTools;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Provides tools over the "Ask the Site" question log, and for its source rules, when that extension is installed.
 *
 * The log records what visitors asked, so these tools are offered to Super Users only.
 *
 * @since  0.8.0
 */
final class Mcpask extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onMcpRegisterTools' => 'onMcpRegisterTools'];
    }

    public function onMcpRegisterTools(EventInterface $event): void
    {
        if (!AskTools::isAvailable()) {
            return;
        }

        $registry = $event->getArgument('registry');

        foreach (AskTools::tools() as $tool) {
            $registry->add($tool);
        }

        // Source rules came with Ask the Site 0.6.0
        if (RuleTools::isAvailable()) {
            foreach (RuleTools::tools() as $tool) {
                $registry->add($tool);
            }
        }

        // Agents came with Ask the Site 0.8.0
        if (AgentTools::isAvailable()) {
            foreach (AgentTools::tools() as $tool) {
                $registry->add($tool);
            }
        }
    }
}
