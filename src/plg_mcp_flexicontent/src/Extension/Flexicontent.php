<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_flexicontent
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Flexicontent\Extension;

use Gss\Plugin\Mcp\Flexicontent\Tool\FcEnvironment;
use Gss\Plugin\Mcp\Flexicontent\Tool\FcTools;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Makes the article tools work on FLEXIcontent items when FLEXIcontent is installed.
 *
 * The tools keep the names of the core article tools (search_articles, create_article, ...) and
 * replace them, so queries, statistics and changes never bypass FLEXIcontent's own data.
 *
 * @since  0.4.0
 */
final class Flexicontent extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onMcpAlterTools' => 'onMcpAlterTools'];
    }

    public function onMcpAlterTools(EventInterface $event): void
    {
        if (!FcEnvironment::isAvailable()) {
            return;
        }

        $registry = $event->getArgument('registry');

        foreach (FcTools::tools() as $tool) {
            $registry->replace($tool);
        }
    }
}
