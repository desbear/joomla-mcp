<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_jlexhelpful
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Jlexhelpful\Extension;

use Gss\Plugin\Mcp\Jlexhelpful\Tool\HelpfulTools;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Provides read-only tools over JLex Helpful's article feedback, when that extension is installed.
 *
 * The votes carry visitor comments and addresses, so these tools are offered to Super Users only.
 *
 * @since  0.8.0
 */
final class Jlexhelpful extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onMcpRegisterTools' => 'onMcpRegisterTools'];
    }

    public function onMcpRegisterTools(EventInterface $event): void
    {
        if (!HelpfulTools::isAvailable()) {
            return;
        }

        $registry = $event->getArgument('registry');

        foreach (HelpfulTools::tools() as $tool) {
            $registry->add($tool);
        }
    }
}
