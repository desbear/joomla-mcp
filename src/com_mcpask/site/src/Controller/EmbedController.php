<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Site\Controller;

use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Gss\Component\Mcpask\Administrator\Ask\Theme;
use Gss\Component\Mcpask\Administrator\Embed\Embeds;
use Gss\Component\Mcpask\Administrator\Embed\Identity;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Document\FactoryInterface as DocumentFactory;
use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;

\defined('_JEXEC') or die;

/**
 * Ask the Site on other websites (docs/embed-spec.md): the page shown in their frame (the floating
 * button and the conversation, without this site's template), and the small window a visitor
 * signs in with.
 *
 * @since  0.11.0
 */
class EmbedController extends BaseController
{
    /**
     * The page in the other website's frame ({key, host}). Only the websites the key lists may
     * show it: the browser enforces that by the frame-ancestors header.
     */
    public function page(): void
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $input = $this->app->getInput();
        $embed = (new Embeds($db))->byKey($input->getString('key', ''));
        $host  = strtolower(rtrim($input->getString('host', ''), '/'));

        $this->clean();
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cache-Control: no-store');
        header_remove('X-Frame-Options');

        if (!$embed) {
            http_response_code(404);
            header("Content-Security-Policy: frame-ancestors 'none'");
            $this->app->close();
        }

        header('Content-Type: text/html; charset=utf-8');
        header('Content-Security-Policy: frame-ancestors ' . Embeds::frameAncestors($embed));

        $settings = Settings::load();

        // Shown only to the websites listed, and only while there is something to ask
        if (!Embeds::allows($embed, $host) || !$settings->ready()) {
            echo '<!doctype html><meta charset="utf-8"><title></title>';
            $this->app->close();
        }

        $params = $embed->params;
        $theme  = $params->get('theme', 'site') === 'own' ? '' : Theme::css($settings->theme());

        // The module as it is on this site, in a document of its own
        $this->app->loadDocument(Factory::getContainer()->get(DocumentFactory::class)->createDocument('html', [
            'charset'   => 'utf-8',
            'language'  => $this->app->getLanguage()->getTag(),
            'direction' => $this->app->getLanguage()->isRtl() ? 'rtl' : 'ltr',
        ]));

        $module = (object) [
            'id'        => 0,
            'title'     => '',
            'module'    => 'mod_mcp_ask',
            'name'      => 'mcp_ask',
            'position'  => 'embed',
            'content'   => '',
            'showtitle' => 0,
            'menuid'    => 0,
            'style'     => 'none',
            'params'    => json_encode([
                'mode'        => 'float',
                'title'       => (string) $params->get('title', ''),
                'placeholder' => (string) $params->get('placeholder', ''),
                'accent'      => (string) $params->get('accent', ''),
                'pet'         => (string) $params->get('pet', ''),
                'sources_shown' => (int) $params->get('sources_shown', 3),
                'theme'       => 'own',
                'embed'       => [
                    'key'   => $embed->public_key,
                    'host'  => $host,
                    'side'  => $params->get('side', 'right') === 'left' ? 'left' : 'right',
                    'login' => $params->get('login', 1) ? Route::_('index.php?option=com_mcpask&task=embed.login&key=' . $embed->public_key . '&format=raw', false) : '',
                ],
            ], JSON_UNESCAPED_UNICODE),
        ];

        $html = ModuleHelper::renderModule($module, ['style' => 'none']);
        $wa   = $this->app->getDocument()->getWebAssetManager();
        $css  = $wa->assetExists('style', 'mod_mcp_ask.ask') ? $wa->getAsset('style', 'mod_mcp_ask.ask') : null;
        $js   = $wa->assetExists('script', 'mod_mcp_ask.ask') ? $wa->getAsset('script', 'mod_mcp_ask.ask') : null;
        $e    = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        // The version, and when the file last changed: a frame on another website is cached apart from this site
        $url  = static function ($asset): string {
            $uri  = $asset->getUri();
            $file = JPATH_ROOT . substr((string) strtok($uri, '?'), \strlen(Uri::root(true)));

            return $uri . '?' . $asset->getVersion() . (is_file($file) ? '-' . filemtime($file) : '');
        };
        $lang = $this->app->getLanguage();

        echo '<!doctype html><html lang="', $e($lang->getTag()), '" dir="', $lang->isRtl() ? 'rtl' : 'ltr', '" class="mcp-ask-embedded"><head>',
            '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">',
            '<title>', $e($params->get('title', '') ?: $this->app->get('sitename')), '</title>',
            $css ? '<link rel="stylesheet" href="' . $e($url($css)) . '">' : '',
            // Nothing of this page shows but the button and the conversation: the other website shows through
            '<style>html,body{margin:0;padding:0;background:transparent!important;color-scheme:normal}</style>',
            $theme !== '' ? '<style>' . $theme . '</style>' : '',
            '</head><body>', $html,
            $js ? '<script src="' . $e($url($js)) . '" defer></script>' : '',
            '</body></html>';

        $this->app->close();
    }

    /**
     * The small window a visitor of an embedded Ask the Site signs in with ({key}). It goes to the
     * account centre's login as this site does (or this site's own login page), comes back here
     * signed in, hands the frame that opened it a token for the key, and closes.
     */
    public function login(): void
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $embed = (new Embeds($db))->byKey($this->app->getInput()->getString('key', ''));
        $user  = $this->app->getIdentity();

        if (!$embed || !$embed->params->get('login', 1)) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        // Back here once signed in, whichever way that goes
        $self = rtrim(Uri::root(), '/') . '/index.php?option=com_mcpask&task=embed.login&key=' . $embed->public_key . '&format=raw';

        if (!$user || $user->guest) {
            $this->app->redirect($this->loginUrl((int) $embed->params->get('login_server', 0), $self));
        }

        $token = (new Identity($db, (string) $this->app->get('secret')))->issue($user, $embed);
        $data  = json_encode(['mcpAsk' => 'identity', 'token' => $token, 'name' => (string) $user->name], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $done  = htmlspecialchars(Text::sprintf('COM_MCPASK_EMBED_SIGNED_IN', $user->name), ENT_QUOTES, 'UTF-8');

        $this->clean();
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex');

        // Only to a page of this site: the frame that opened the window is this site's own embed page
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>', $done, '</title>',
            '<p style="font:15px/1.6 system-ui,sans-serif;margin:40px 24px;text-align:center">', $done, '</p>',
            '<script>(function(){try{if(window.opener){window.opener.postMessage(', $data, ',window.location.origin);window.close();}}catch(e){}})();</script>';

        $this->app->close();
    }

    /**
     * Where a visitor signs in: the account centre (an External Login server) or this site's login page.
     */
    private function loginUrl(int $server, string $return): string
    {
        if ($server > 0 && ComponentHelper::isEnabled('com_externallogin')) {
            try {
                $table = $this->app->bootComponent('com_externallogin')->getMVCFactory()->createTable('Server', 'Administrator');

                if ($table && $table->load($server) && (int) $table->published === 1) {
                    // As External Login's own login link does: the server is remembered, and its plugin gives the address
                    $this->app->setUserState('com_externallogin.server', $server);

                    $event = new ContentPrepareEvent('onGetLoginUrl', ['context' => 'com_externallogin', 'subject' => $table]);
                    $event->setArgument('service', Uri::getInstance($return));
                    Factory::getContainer()->get(DispatcherInterface::class)->dispatch('onGetLoginUrl', $event);

                    $result = $event->getArgument('result', []);
                    $url    = (string) (\is_array($result) ? ($result[0] ?? '') : $result);

                    if ($url !== '') {
                        return $url;
                    }
                }
            } catch (\Throwable) {
                // This site's own login page instead
            }
        }

        return Route::_('index.php?option=com_users&view=login&return=' . base64_encode($return), false);
    }

    private function clean(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
