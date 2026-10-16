<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_content
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Content\Extension;

use Gss\Plugin\Mcp\Content\Tool\ArticleTools;
use Gss\Plugin\Mcp\Content\Tool\TaxonomyTools;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Provides the article, category, tag and custom field tools.
 *
 * @since  0.1.0
 */
final class Content extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onMcpRegisterTools' => 'onMcpRegisterTools'];
    }

    public function onMcpRegisterTools(EventInterface $event): void
    {
        $registry = $event->getArgument('registry');

        foreach (array_merge(ArticleTools::tools(), TaxonomyTools::tools()) as $tool) {
            $registry->add($tool);
        }
    }
}
