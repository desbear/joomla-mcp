<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\View\Dashboard;

use Gss\Component\Mcp\Administrator\Helper\HealthCheck;
use Gss\Component\Mcp\Administrator\Mcp\Server;
use Gss\Component\Mcp\Administrator\Mcp\Tool;
use Gss\Component\Mcp\Administrator\Mcp\ToolRegistry;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Dashboard: health checks, connection instructions per client, tool overview, recent activity.
 *
 * @since  0.1.0
 */
class HtmlView extends BaseHtmlView
{
    public string $endpoint = '';

    public string $healthUrl = '';

    public array $checks = [];

    /** @var Tool[] */
    public array $tools = [];

    public array $disabledTools = [];

    public array $recent = [];

    public int $userId = 0;

    public string $version = Server::VERSION;

    public bool $readOnly = false;

    public bool $oauthEnabled = true;

    public string $oauthMetadataUrl = '';

    public function display($tpl = null): void
    {
        $app  = Factory::getApplication();
        $user = $app->getIdentity();

        // Prefer the configured site URL: Uri::root() follows the Host header, and this endpoint is
        // meant to be copied into an AI client together with an API token
        $liveSite        = $app->get('live_site');
        $root            = rtrim(\is_string($liveSite) && trim($liveSite) !== '' ? trim($liveSite) : Uri::root(), '/');
        $this->endpoint  = $root . '/api/index.php/v1/mcp';
        $this->healthUrl = $this->endpoint . '/health';
        $this->userId    = (int) $user->id;
        $this->checks    = HealthCheck::run($user);

        $params              = ComponentHelper::getParams('com_mcp');
        $this->readOnly      = (bool) $params->get('read_only', 1);
        $this->disabledTools = (array) $params->get('disabled_tools', []);
        $this->oauthEnabled  = (bool) $params->get('oauth_enabled', 1);
        $this->oauthMetadataUrl = $this->endpoint . '/oauth/.well-known/oauth-authorization-server';

        try {
            $this->tools = ToolRegistry::load()->all();
        } catch (\Throwable $e) {
            $app->enqueueMessage($e->getMessage(), 'error');
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select('l.created, l.tool, l.status, l.duration_ms, u.name AS user_name')
            ->from($db->quoteName('#__mcp_logs', 'l'))
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = l.user_id')
            ->order('l.id DESC')
            ->setLimit(10);

        $this->recent = $db->setQuery($query)->loadObjectList();

        ToolbarHelper::title(Text::_('COM_MCP') . ' — ' . Text::_('COM_MCP_SUBMENU_DASHBOARD'), 'plug');

        if ($user->authorise('core.admin', 'com_mcp') || $user->authorise('core.options', 'com_mcp')) {
            ToolbarHelper::preferences('com_mcp');
        }

        parent::display($tpl);
    }

    /**
     * Whether a tool is currently offered to clients by the configuration (ACL aside).
     */
    public function toolEnabled(Tool $tool): bool
    {
        $params = ComponentHelper::getParams('com_mcp');

        if (\in_array($tool->name, $this->disabledTools, true)) {
            return false;
        }

        if (!$tool->isReadOnly() && $this->readOnly) {
            return false;
        }

        if ($tool->scope === Tool::SCOPE_PUBLISH && !$params->get('allow_publish', 1)) {
            return false;
        }

        return !($tool->scope === Tool::SCOPE_DELETE && !$params->get('allow_delete', 0));
    }

    /**
     * Render a snippet with the default header (the browser adjusts it after the health check).
     */
    public function snippet(string $template): string
    {
        return strtr($template, ['{URL}' => $this->endpoint, '{HEADER}' => 'Authorization', '{VALUE}' => 'Bearer YOUR_API_TOKEN']);
    }

    /**
     * Configuration snippets per client. {URL}, {HEADER} and {VALUE} are replaced; the token only in the browser.
     *
     * @return array<string, array{title: string, file: string, code: string}>
     */
    public function clients(): array
    {
        $json = fn (array $data) => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $oauth = [];

        if ($this->oauthEnabled) {
            $oauth = [
                'claude-ai' => [
                    'title' => 'claude.ai / ' . Text::_('COM_MCP_CLIENT_MOBILE'),
                    'file'  => Text::_('COM_MCP_CLIENT_CONNECTOR_URL'),
                    'code'  => '{URL}',
                ],
                'chatgpt' => [
                    'title' => 'ChatGPT',
                    'file'  => Text::_('COM_MCP_CLIENT_CONNECTOR_URL'),
                    'code'  => '{URL}',
                ],
                'claude-code-oauth' => [
                    'title' => 'Claude Code (OAuth)',
                    'file'  => Text::_('COM_MCP_CLIENT_TERMINAL'),
                    'code'  => 'claude mcp add --transport http joomla {URL}',
                ],
            ];
        }

        return $oauth + [
            'claude-code' => [
                'title' => 'Claude Code (API token)',
                'file'  => Text::_('COM_MCP_CLIENT_TERMINAL'),
                'code'  => 'claude mcp add --transport http joomla {URL} --header "{HEADER}: {VALUE}"',
            ],
            'claude-desktop' => [
                'title' => 'Claude Desktop',
                'file'  => 'claude_desktop_config.json',
                'code'  => $json(['mcpServers' => ['joomla' => [
                    'command' => 'npx',
                    'args'    => ['-y', 'mcp-remote', '{URL}', '--header', '{HEADER}:${JOOMLA_AUTH}'],
                    'env'     => ['JOOMLA_AUTH' => '{VALUE}'],
                ]]]),
            ],
            'cursor' => [
                'title' => 'Cursor',
                'file'  => '~/.cursor/mcp.json',
                'code'  => $json(['mcpServers' => ['joomla' => [
                    'url'     => '{URL}',
                    'headers' => ['{HEADER}' => '{VALUE}'],
                ]]]),
            ],
            'vscode' => [
                'title' => 'VS Code',
                'file'  => '.vscode/mcp.json',
                'code'  => $json(['servers' => ['joomla' => [
                    'type'    => 'http',
                    'url'     => '{URL}',
                    'headers' => ['{HEADER}' => '{VALUE}'],
                ]]]),
            ],
            'other' => [
                'title' => Text::_('COM_MCP_CLIENT_OTHER'),
                'file'  => 'Streamable HTTP',
                'code'  => "URL: {URL}\nHeader: {HEADER}: {VALUE}",
            ],
        ];
    }
}
