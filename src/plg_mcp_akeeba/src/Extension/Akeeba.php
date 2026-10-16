<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_akeeba
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Akeeba\Extension;

use Gss\Plugin\Mcp\Akeeba\Tool\AkeebaTools;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Provides the Akeeba Backup tools when Akeeba Backup (com_akeebabackup) is installed.
 *
 * @since  0.3.0
 */
final class Akeeba extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onMcpRegisterTools' => 'onMcpRegisterTools'];
    }

    public function onMcpRegisterTools(EventInterface $event): void
    {
        if (!ComponentHelper::isInstalled('com_akeebabackup') || !ComponentHelper::isEnabled('com_akeebabackup')) {
            return;
        }

        $registry = $event->getArgument('registry');

        foreach (AkeebaTools::tools($this->params) as $tool) {
            $registry->add($tool);
        }
    }
}
