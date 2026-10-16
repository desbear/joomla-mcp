<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  mod_mcp_ask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Module\McpAsk\Site\Dispatcher;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Search box that turns into a conversation with the site's content.
 */
class Dispatcher extends AbstractModuleDispatcher
{
    /** @var boolean  The page-change script only has to be added once per page */
    private static bool $revealAdded = false;

    private static bool $themeAdded = false;

    /**
     * Without the answer service there is nothing to ask, so nothing is shown (demo mode aside).
     */
    public function dispatch()
    {
        $params = new Registry($this->module->params);

        if ($params->get('demo', 0) || $this->serviceReady()) {
            parent::dispatch();
        }
    }

    protected function getLayoutData(): array
    {
        $data   = parent::getLayoutData();
        $params = $data['params'];
        $demo   = (bool) $params->get('demo', 0);

        $title       = trim((string) $params->get('title')) ?: Text::_('MOD_MCP_ASK_TITLE');
        $placeholder = trim((string) $params->get('placeholder')) ?: Text::_('MOD_MCP_ASK_PLACEHOLDER');

        $data['mode']        = \in_array($params->get('mode'), ['box', 'float'], true) ? $params->get('mode') : 'icon';
        $data['start']       = $params->get('align', 'end') === 'start';
        $data['floatAt']     = \in_array($params->get('float_position'), ['top', 'middle'], true) ? $params->get('float_position') : 'bottom';
        // Embedded on another website (the component's embed page): its key, the website, the sign-in window
        $embed               = (array) $params->get('embed', []);
        $data['embed']       = !empty($embed['key']) ? array_intersect_key($embed, array_flip(['key', 'host', 'side', 'login'])) : null;
        $data['spot']        = $data['mode'] === 'float' && !$data['embed'] ? $this->spot() : null;
        $data['title']       = $title;
        $data['placeholder'] = $placeholder;
        // Routed, so the language of the page is the language the answer service works in
        $data['endpoint']    = $demo ? '' : Route::_('index.php?option=com_mcpask&task=ask.stream&format=raw', false);
        $data['feedback']    = $demo ? '' : Route::_('index.php?option=com_mcpask&task=ask.feedback&format=raw', false);
        $data['allowedHosts'] = $this->allowedHosts();
        $data['pageItem']    = $data['embed'] ? 0 : $this->pageItem();
        $data['sourcesShown'] = max(0, min(50, (int) $params->get('sources_shown', 3)));
        $data['pet']         = $data['mode'] === 'float' && !$demo ? $this->pet((string) $params->get('pet', '')) : null;
        $data['demoSources'] = $demo ? $this->demoSources() : [];
        $data['accent']      = preg_match('/^#[0-9a-f]{3,8}$/i', (string) $params->get('accent')) ? $params->get('accent') : '';
        $data['strings']     = [
            'title'        => $title,
            'placeholder'  => $placeholder,
            'followup'     => Text::_('MOD_MCP_ASK_FOLLOWUP'),
            'empty'        => $placeholder,
            'send'         => Text::_('MOD_MCP_ASK_SEND'),
            'close'        => Text::_('MOD_MCP_ASK_CLOSE'),
            'newChat'      => Text::_('MOD_MCP_ASK_NEW'),
            'resume'       => Text::_('MOD_MCP_ASK_RESUME'),
            'sources'      => Text::_('MOD_MCP_ASK_SOURCES'),
            'here'         => Text::_('MOD_MCP_ASK_HERE'),
            'more'         => Text::_('MOD_MCP_ASK_MORE'),
            'others'       => Text::_('MOD_MCP_ASK_MORE_OTHERS'),
            'less'         => Text::_('MOD_MCP_ASK_LESS'),
            'searching'    => Text::_('MOD_MCP_ASK_SEARCHING'),
            'note'         => Text::_('MOD_MCP_ASK_NOTE'),
            'noAnswer'     => Text::_('MOD_MCP_ASK_NO_ANSWER'),
            'error'        => Text::_('MOD_MCP_ASK_ERROR'),
            'unconfigured' => Text::_('MOD_MCP_ASK_UNCONFIGURED'),
            'helpful'      => Text::_('MOD_MCP_ASK_HELPFUL'),
            'notHelpful'   => Text::_('MOD_MCP_ASK_NOT_HELPFUL'),
            'why'          => Text::_('MOD_MCP_ASK_WHY'),
            'thanks'       => Text::_('MOD_MCP_ASK_THANKS'),
            'previous'       => Text::_('MOD_MCP_ASK_PREVIOUS'),
            'next'           => Text::_('MOD_MCP_ASK_NEXT'),
            'calloutNote'    => Text::_('MOD_MCP_ASK_CALLOUT_NOTE'),
            'calloutTip'     => Text::_('MOD_MCP_ASK_CALLOUT_TIP'),
            'calloutWarning' => Text::_('MOD_MCP_ASK_CALLOUT_WARNING'),
            'agentWith'      => Text::_('MOD_MCP_ASK_AGENT_WITH'),
            'agentLeave'     => Text::_('MOD_MCP_ASK_AGENT_LEAVE'),
            'agentReply'     => Text::_('MOD_MCP_ASK_AGENT_REPLY'),
            'agentStart'     => Text::_('MOD_MCP_ASK_AGENT_START'),
            'agentTry'       => Text::_('MOD_MCP_ASK_AGENT_TRY'),
            'required'       => Text::_('MOD_MCP_ASK_REQUIRED'),
            'reportWriting'  => Text::_('MOD_MCP_ASK_REPORT_WRITING'),
            'reportOpen'     => Text::_('MOD_MCP_ASK_REPORT_OPEN'),
            'reportEmailed'  => Text::_('MOD_MCP_ASK_REPORT_EMAILED'),
            'reportSources'  => Text::_('MOD_MCP_ASK_REPORT_SOURCES'),
            'handoff'        => Text::_('MOD_MCP_ASK_HANDOFF'),
            'handoffOffer'   => Text::_('MOD_MCP_ASK_HANDOFF_OFFER'),
            'signIn'         => Text::_('MOD_MCP_ASK_SIGN_IN'),
            'signOut'        => Text::_('MOD_MCP_ASK_SIGN_OUT'),
        ];

        // Helpers that take over the conversation for one task (the component's agents)
        $data['agentStart'] = $demo ? '' : Route::_('index.php?option=com_mcpask&task=agent.start&format=raw', false);
        $data['agentStep']  = $demo ? '' : Route::_('index.php?option=com_mcpask&task=agent.step&format=raw', false);
        $data['agentReport'] = $demo ? '' : Route::_('index.php?option=com_mcpask&task=agent.report&format=raw', false);
        $data['agentHandoff'] = $demo ? '' : Route::_('index.php?option=com_mcpask&task=agent.handoff&format=raw', false);
        [$data['agents'], $data['handoff'], $data['agentLooks']] = $demo ? [[], null, []] : $this->agents();

        $this->addAssets($demo, $params->get('theme', 'site') !== 'own');

        return $data;
    }

    /**
     * Agents listed when the conversation opens (their ID and button text), and the one that passes
     * visitors on to a person, if any, listed or not.
     *
     * @return  array{0: array<int, array{id: int, label: string}>, 1: array{id: int, label: string}|null, 2: array<int, array>}
     */
    private function agents(): array
    {
        $class = 'Gss\\Component\\Mcpask\\Administrator\\Agent\\Agents';
        $app   = $this->getApplication();
        $user  = $app->getIdentity();

        try {
            if (!class_exists($class) || !$user) {
                return [[], null, []];
            }

            $agents = (new $class(\Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class)))
                ->active($app->getLanguage()->getTag(), array_map('intval', $user->getAuthorisedViewLevels()));
        } catch (\Throwable) {
            return [[], null, []];
        }

        $listed  = [];
        $handoff = null;
        $looks   = [];
        $pets    = 'Gss\\Component\\Mcpask\\Administrator\\Pet\\Pets';
        $pets    = class_exists($pets) ? new $pets(\Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class)) : null;

        foreach ($agents as $agent) {
            $item = ['id' => (int) $agent->id, 'label' => trim((string) $agent->params->get('suggest_label', '')) ?: (string) $agent->title];

            // How each shows itself: its name, and its picture or pet
            $avatar = $pets && method_exists($pets, 'avatar') ? $pets->avatar((string) $agent->params->get('avatar', ''), (string) $agent->params->get('avatar_image', '')) : null;
            $looks[(int) $agent->id] = array_filter(['title' => (string) $agent->title, 'avatar' => $avatar]);

            if ($agent->params->get('suggest', 1)) {
                $listed[] = $item;
            }

            if ($agent->type === 'livechat' && !$handoff) {
                $handoff = $item;
            }
        }

        return [\array_slice($listed, 0, 4), $handoff, $looks];
    }

    /**
     * The pet the floating button is (the component's pet library), if one is chosen and there.
     */
    private function pet(string $choice): ?array
    {
        $pets = 'Gss\\Component\\Mcpask\\Administrator\\Pet\\Pets';

        if ($choice === '' || !class_exists($pets)) {
            return null;
        }

        try {
            return (new $pets(\Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class)))->display($choice);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The article or FLEXIcontent item this page shows, so a question asked here can be understood
     * with it in mind. 0 on any other page.
     */
    private function pageItem(): int
    {
        $input = $this->getApplication()->getInput();
        $shown = [$input->getCmd('option'), $input->getCmd('view')];

        return \in_array($shown, [['com_content', 'article'], ['com_flexicontent', 'item']], true) ? max(0, $input->getInt('id')) : 0;
    }

    /**
     * Where the visitor last moved the floating button to, so the page arrives with it there
     * (ask.js writes the cookie, and applies it again if a cached page was sent without it).
     *
     * @return  array{side: string, y: float}|null
     */
    private function spot(): ?array
    {
        $value = (string) $this->getApplication()->getInput()->cookie->getString('mcp_ask_float', '');

        if (!preg_match('/^(left|right):([01](?:\.\d{1,6})?)$/', $value, $match)) {
            return null;
        }

        return ['side' => $match[1], 'y' => min(1.0, (float) $match[2])];
    }

    /**
     * Sites links in answers may lead to, as the component allows them.
     *
     * @return  string[]
     */
    private function allowedHosts(): array
    {
        $settings = 'Gss\\Component\\Mcpask\\Administrator\\Ask\\Settings';

        try {
            return class_exists($settings) ? $settings::load()->allowedHosts() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The colours chosen on the component's Appearance page, as CSS; empty when none were.
     */
    private function themeCss(): string
    {
        $settings = 'Gss\\Component\\Mcpask\\Administrator\\Ask\\Settings';
        $theme    = 'Gss\\Component\\Mcpask\\Administrator\\Ask\\Theme';

        try {
            return class_exists($settings) && class_exists($theme) ? $theme::css($settings::load()->theme()) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function serviceReady(): bool
    {
        if (!ComponentHelper::isEnabled('com_mcpask')) {
            return false;
        }

        $service = ComponentHelper::getParams('com_mcpask');

        if (!$service->get('enabled', 1) || trim((string) $service->get('api_key', '')) === '') {
            return false;
        }

        // Not shown to a visitor the component's permissions do not let ask
        $settings = 'Gss\\Component\\Mcpask\\Administrator\\Ask\\Settings';
        $user     = $this->getApplication()->getIdentity();

        return !class_exists($settings) || !$user || $settings::load()->mayAsk($user);
    }

    private function addAssets(bool $demo, bool $siteTheme): void
    {
        $wa = $this->getApplication()->getDocument()->getWebAssetManager();

        $wa->getRegistry()->addExtensionRegistryFile('mod_mcp_ask');
        $wa->useStyle('mod_mcp_ask.ask')->useScript('mod_mcp_ask.ask');

        if ($demo) {
            $wa->useScript('mod_mcp_ask.demo');
        }

        // The site's colours (Appearance page), after the module's own styles, once a page
        if ($siteTheme && !self::$themeAdded) {
            self::$themeAdded = true;
            $css              = $this->themeCss();

            if ($css !== '') {
                $wa->addInlineStyle($css, [], [], ['mod_mcp_ask.ask']);
            }
        }

        // Inline, because it has to run before the first paint of a page reached from a source link
        $reveal = JPATH_ROOT . '/media/mod_mcp_ask/js/ask-reveal.js';

        if (!self::$revealAdded && is_file($reveal)) {
            $wa->addInlineScript(file_get_contents($reveal));
            self::$revealAdded = true;
        }
    }

    /**
     * Real pages of this site for the canned answer to cite, so the page change can be tried.
     *
     * @return  array<int, array{title: string, url: string}>
     */
    private function demoSources(): array
    {
        $menu    = $this->getApplication()->getMenu();
        $default = $menu->getDefault();
        $tag     = $this->getApplication()->getLanguage()->getTag();
        $items   = $default ? $menu->getItems(['menutype', 'language'], [$default->menutype, [$tag, '*']]) : [];
        $sources = [];

        foreach ($items as $item) {
            if ($item->type !== 'component' || \count($sources) === 3) {
                continue;
            }

            $sources[] = [
                'title' => $item->title,
                'url'   => Route::_('index.php?Itemid=' . $item->id, false, Route::TLS_IGNORE, true),
            ];
        }

        return $sources ?: [['title' => $this->getApplication()->get('sitename'), 'url' => Uri::root()]];
    }
}
