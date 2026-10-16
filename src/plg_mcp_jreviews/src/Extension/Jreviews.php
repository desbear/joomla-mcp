<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_jreviews
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Jreviews\Extension;

use Gss\Plugin\Mcp\Jreviews\Tool\JreviewsTools;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Provides the JReviews tools when JReviews is installed.
 *
 * JReviews listings are Joomla articles in JReviews categories, so these tools are added next
 * to the article tools: the article tools still work, and these add the listing types, custom
 * field values, ratings and reviews that only JReviews knows about.
 *
 * @since  0.6.0
 */
final class Jreviews extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onMcpRegisterTools' => 'onMcpRegisterTools'];
    }

    public function onMcpRegisterTools(EventInterface $event): void
    {
        if (!JreviewsTools::isAvailable()) {
            return;
        }

        $registry = $event->getArgument('registry');

        foreach (JreviewsTools::tools() as $tool) {
            $registry->add($tool);
        }
    }
}
