<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_task_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Task\Mcp\Extension;

use Gss\Component\Mcp\Administrator\Mcp\Maintenance;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * The scheduled task "MCP Server - Forget old records" (0.11.0): once a day, the activity log after
 * its retention period and the addresses in it after a week, OAuth codes, revoked and expired
 * tokens, and clients nobody uses.
 *
 * @since  0.11.0
 */
final class Mcp extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    private const TASKS_MAP = [
        'mcp.forget' => [
            'langConstPrefix' => 'PLG_TASK_MCP_FORGET',
            'method'          => 'forget',
        ],
    ];

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList' => 'advertiseRoutines',
            'onExecuteTask'     => 'standardRoutineHandler',
        ];
    }

    private function forget(ExecuteTaskEvent $event): int
    {
        if (!ComponentHelper::isEnabled('com_mcp')) {
            return Status::KNOCKOUT;
        }

        $counts = Maintenance::run(Factory::getContainer()->get(DatabaseInterface::class));

        $this->logTask(\sprintf(
            'Calls deleted %d, addresses cleared %d; OAuth codes %d, tokens %d, clients %d.',
            $counts['calls_deleted'],
            $counts['addresses_cleared'],
            $counts['codes'],
            $counts['tokens'],
            $counts['clients']
        ));

        return Status::OK;
    }
}
