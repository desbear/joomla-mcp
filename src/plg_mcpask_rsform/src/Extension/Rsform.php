<?php

/**
 * @package     Ask the Site
 * @subpackage  plg_mcpask_rsform
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcpask\Rsform\Extension;

use Gss\Plugin\Mcpask\Rsform\Agent\RsformAgent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Offers Ask the Site an agent that fills in RSForm! Pro forms in conversation.
 *
 * @since  1.0.0
 */
final class Rsform extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return ['onMcpaskAgentTypes' => 'agentTypes'];
    }

    public function agentTypes(Event $event): void
    {
        // Ask the Site's own classes are needed; without it there is nothing to offer
        if (!interface_exists('Gss\\Component\\Mcpask\\Administrator\\Agent\\AgentInterface')) {
            return;
        }

        $this->loadLanguage();
        $event->setArgument('types', [...(array) $event->getArgument('types', []), new RsformAgent()]);
    }
}
