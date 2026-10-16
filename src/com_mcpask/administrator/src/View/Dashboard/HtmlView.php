<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\View\Dashboard;

use Gss\Component\Mcpask\Administrator\Ask\Indexer;
use Gss\Component\Mcpask\Administrator\Ask\PeerSync;
use Gss\Component\Mcpask\Administrator\Ask\State;
use Gss\Component\Mcpask\Administrator\Ask\WebSync;
use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Gss\Component\Mcpask\Administrator\Ask\Source\Sources;
use Gss\Component\Mcpask\Administrator\Ask\UsageLog;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Dashboard: what is still to be set up, the state of the index, and what visitors ask.
 *
 * @since  0.1.0
 */
class HtmlView extends BaseHtmlView
{
    /** @var array<int, array{status: string, text: string}> */
    public array $checks = [];

    public array $stats = [];

    public string $source = '';

    public int $pages = 0;

    /** EasyBlog posts a guest may read, when EasyBlog is answered from; null when it is not */
    public ?int $blogPosts = null;

    public int $today = 0;

    /** @var object[] */
    public array $recent = [];

    /** The mark the list of questions is narrowed to, if any */
    public string $flag = '';

    public bool $canManage = false;

    /** Emptying the index takes the component's options permission */
    public bool $canClear = false;

    /** @var array<string, array>  The other sites that are read, see PeerSync::status() */
    public array $peers = [];

    /** @var array<string, array>  The websites that are read from their feed or sitemap, see WebSync::status() */
    public array $websites = [];

    /** @var array{sites: array, running: bool, busy: bool, wanted: bool}  What dashboard.js starts from */
    public array $websiteStatus = [];

    /** "ON", "OFF", or "SHORT" when a key was entered that is too short to count */
    public string $exports = 'OFF';

    public function display($tpl = null): void
    {
        $app      = Factory::getApplication();
        $user     = $app->getIdentity();
        $db       = Factory::getContainer()->get(DatabaseInterface::class);
        $settings = Settings::load();
        $source   = Sources::current($db, $settings);
        $log      = new UsageLog($db, $settings, (string) $app->get('secret'));

        $this->stats     = (new Indexer($db, $settings))->stats();
        $this->source    = $source->name();
        $this->pages     = \count($source->ids());
        $own             = Sources::own($db, $settings);
        $this->blogPosts = isset($own['easyblog']) ? \count($own['easyblog']->ids()) : null;
        $this->today     = $log->count(86400);
        $this->flag      = $app->getInput()->getCmd('flag', '');
        $this->recent    = $log->recent(20, $this->flag);
        $this->canManage = $user->authorise('core.manage', 'com_mcpask');
        $this->canClear  = $user->authorise('core.admin', 'com_mcpask') || $user->authorise('core.options', 'com_mcpask');
        $this->peers     = (new PeerSync($db, $settings))->status();
        $this->websites  = (new WebSync($db, $settings))->status();
        $this->websiteStatus = [
            'sites'   => array_values($this->websites),
            'busy'    => false,
            'running' => WebSync::running($db),
            'wanted'  => (bool) (new State($db))->get(WebSync::WANTED),
        ];
        $this->exports   = $settings->exportKey() !== '' ? 'ON' : ($settings->exportKeyTooShort() ? 'SHORT' : 'OFF');
        $this->checks    = $this->checks($db, $settings);

        ToolbarHelper::title(Text::_('COM_MCPASK'), 'comments');
        ToolbarHelper::link('index.php?option=com_mcpask&view=quality', Text::_('COM_MCPASK_QUALITY'), 'thumbs-up');

        if ($user->authorise('core.admin', 'com_mcpask') || $user->authorise('core.options', 'com_mcpask')) {
            ToolbarHelper::link('index.php?option=com_mcpask&view=appearance', Text::_('COM_MCPASK_APPEARANCE'), 'palette');
            ToolbarHelper::link('index.php?option=com_mcpask&view=agents', Text::_('COM_MCPASK_AGENTS'), 'users');
            ToolbarHelper::link('index.php?option=com_mcpask&view=embeds', Text::_('COM_MCPASK_EMBEDS'), 'code');
            ToolbarHelper::link('index.php?option=com_mcpask&view=pets', Text::_('COM_MCPASK_PETS'), 'image');
            ToolbarHelper::preferences('com_mcpask');
        }

        parent::display($tpl);
    }

    /**
     * @return  array<int, array{status: string, text: string}>
     */
    private function checks(DatabaseInterface $db, Settings $settings): array
    {
        $check = static fn (bool $ok, string $key, string $otherwise = 'error') => [
            'status' => $ok ? 'ok' : $otherwise,
            'text'   => Text::_('COM_MCPASK_CHECK_' . $key . ($ok ? '_OK' : '_MISSING')),
        ];

        $modules = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__modules'))
            ->where('module = ' . $db->quote('mod_mcp_ask'))
            ->where('published = 1')
            ->where('client_id = 0');

        try {
            $tasks = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__scheduler_tasks'))
                ->where('type = ' . $db->quote('mcpask.sync'))
                ->where('state = 1');
            $task  = (int) $db->setQuery($tasks)->loadResult() > 0;
        } catch (\Throwable) {
            $task = false;
        }

        $checks = [
            $check(\function_exists('curl_init'), 'CURL'),
            $check($settings->apiKey() !== '', 'KEY'),
            $check($settings->enabled(), 'ENABLED', 'warning'),
            $check($this->stats['chunks'] > 0, 'INDEX'),
            $check((int) $db->setQuery($modules)->loadResult() > 0, 'MODULE', 'warning'),
            $check(PluginHelper::isEnabled('content', 'mcpask'), 'PLUGIN', 'warning'),
            $check($task, 'TASK', 'warning'),
        ];

        // The backup service stepped in during the last day: worth knowing that the main one has trouble
        if ($settings->fallbackReady()) {
            $failover = (new State($db))->get(Settings::FAILOVER_STATE);
            $recent   = ($failover['at'] ?? '') > Factory::getDate('-1 day')->toSql();

            $checks[] = [
                'status' => $recent ? 'warning' : 'ok',
                'text'   => $recent
                    ? Text::sprintf('COM_MCPASK_CHECK_FALLBACK_USED', HTMLHelper::_('date', $failover['at'], Text::_('DATE_FORMAT_LC5')), mb_substr((string) $failover['reason'], 0, 160))
                    : Text::_('COM_MCPASK_CHECK_FALLBACK_READY'),
            ];
        }

        return $checks;
    }
}
