<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_easyblog
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Easyblog\Extension;

use Gss\Plugin\Mcp\Easyblog\Tool\EasyblogTools;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Provides the EasyBlog tools when EasyBlog is installed.
 *
 * EasyBlog is a blog beside Joomla's articles, so these tools are added next to the article
 * tools instead of replacing them.
 *
 * @since  0.5.0
 */
final class Easyblog extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onMcpRegisterTools' => 'onMcpRegisterTools'];
    }

    public function onMcpRegisterTools(EventInterface $event): void
    {
        if (!EasyblogTools::isAvailable()) {
            return;
        }

        $registry = $event->getArgument('registry');

        foreach (EasyblogTools::tools() as $tool) {
            $registry->add($tool);
        }
    }
}
