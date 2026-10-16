<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_task_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Task\Mcpask\Extension;

use Gss\Component\Mcpask\Administrator\Ask\Hints;
use Gss\Component\Mcpask\Administrator\Ask\Indexer;
use Gss\Component\Mcpask\Administrator\Ask\PeerSync;
use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Gss\Component\Mcpask\Administrator\Ask\Source\Sources;
use Gss\Component\Mcpask\Administrator\Ask\UsageLog;
use Gss\Component\Mcpask\Administrator\Ask\WebSync;
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
 * Scheduled task that brings the index of "Ask the Site" up to date: pages that became public by
 * date, pages whose update failed, and pages that are no longer public.
 *
 * @since  0.1.0
 */
final class Mcpask extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    private const TASKS_MAP = [
        'mcpask.sync' => [
            'langConstPrefix' => 'PLG_TASK_MCPASK_SYNC',
            'method'          => 'sync',
        ],
    ];

    /** Seconds one run may work for; the rest waits for the next run. */
    private const SECONDS = 40;

    /** Seconds one run spends reading other sites. */
    private const PEER_SECONDS = 40;

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList' => 'advertiseRoutines',
            'onExecuteTask'     => 'standardRoutineHandler',
        ];
    }

    private function sync(ExecuteTaskEvent $event): int
    {
        if (!ComponentHelper::isEnabled('com_mcpask')) {
            return Status::KNOCKOUT;
        }

        $db       = Factory::getContainer()->get(DatabaseInterface::class);
        $settings = Settings::load();

        if ($settings->apiKey() === '') {
            $this->logTask('No API key is set in the options of Ask the Site.', 'warning');

            return Status::KNOCKOUT;
        }

        $indexer = new Indexer($db, $settings);
        $own     = Sources::own($db, $settings);
        $result  = ['indexed' => 0, 'unchanged' => 0, 'empty' => 0, 'fresh' => 0, 'removed' => $indexer->prune($own), 'error' => ''];

        // Each of the site's sources gets its share of the time
        foreach ($own as $source) {
            $part = $indexer->refresh($source, max(5, intdiv(self::SECONDS, \count($own))));

            foreach (['indexed', 'unchanged', 'empty', 'fresh'] as $key) {
                $result[$key] += $part[$key];
            }

            if ($part['error'] !== '') {
                $result['error'] = $part['error'];

                break;
            }
        }

        $indexer->encode(5000);

        // Source rules made under another embedding model are made comparable again
        try {
            (new Hints($db, $settings))->refresh();
        } catch (\Throwable $e) {
            $this->logTask('Source rules could not be embedded again: ' . $e->getMessage(), 'warning');
        }

        (new UsageLog($db, $settings, (string) $this->getApplication()->get('secret')))->prune();

        $this->logTask(\sprintf(
            'Indexed %d, unchanged %d, without text %d, removed %d, not changed since last looked at (passed over) %d.',
            $result['indexed'],
            $result['unchanged'],
            $result['empty'],
            $result['removed'],
            $result['fresh']
        ));

        // A site that cannot be reached is reported, and tried again next time.
        // The sites this one reads, and the websites listed with their feed or sitemap
        $sites = array_merge(
            array_values((new PeerSync($db, $settings))->run(self::PEER_SECONDS)),
            array_values((new WebSync($db, $settings))->run(self::PEER_SECONDS))
        );

        foreach ($sites as $site) {
            $this->logTask(
                \sprintf('%s: %d of %d pages stored, %d new or changed, %d removed, %d still to read.', $site['name'], $site['stored'], $site['total'], $site['imported'], $site['removed'], $site['remaining'])
                . ($site['error'] !== '' ? ' ' . $site['error'] : ''),
                $site['error'] !== '' ? 'warning' : 'info'
            );
        }

        if ($result['error'] !== '') {
            $this->logTask($result['error'], 'error');

            return Status::KNOCKOUT;
        }

        return Status::OK;
    }
}
