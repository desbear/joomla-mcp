<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_media
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Media\Extension;

use Gss\Plugin\Mcp\Media\Tool\MediaTools;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Provides tools for inspecting the media folders and uploading images.
 *
 * Reading needs the Media Manager permission; uploading is off until an administrator turns it on.
 *
 * @since  0.8.0
 */
final class Media extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onMcpRegisterTools' => 'onMcpRegisterTools'];
    }

    public function onMcpRegisterTools(EventInterface $event): void
    {
        if (!MediaTools::isAvailable()) {
            return;
        }

        $registry = $event->getArgument('registry');

        foreach (MediaTools::tools() as $tool) {
            $registry->add($tool);
        }
    }
}
