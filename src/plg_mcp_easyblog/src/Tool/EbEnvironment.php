<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_easyblog
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Easyblog\Tool;

use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;
use Gss\Component\Mcp\Administrator\Helper\ApiCompat;
use Joomla\CMS\Component\ComponentHelper;

\defined('_JEXEC') or die;

/**
 * Loads EasyBlog's framework so its post API can be used from the API application.
 *
 * EasyBlog has no service provider: its entry point includes one bootstrap file that registers
 * everything else. That file is all that is needed here; the view, AJAX and controller parts of
 * its entry point are deliberately skipped.
 *
 * @since  0.5.0
 */
final class EbEnvironment
{
    private const BOOTSTRAP = '/components/com_easyblog/includes/easyblog.php';

    private static bool $booted = false;

    public static function isAvailable(): bool
    {
        return ComponentHelper::isInstalled('com_easyblog')
            && ComponentHelper::isEnabled('com_easyblog')
            && is_file(JPATH_ADMINISTRATOR . self::BOOTSTRAP);
    }

    /**
     * Only needed before writing; reading uses plain database queries.
     */
    public static function boot(ToolContext $ctx): void
    {
        if (self::$booted) {
            return;
        }

        if (!self::isAvailable()) {
            throw new ToolException('EasyBlog is not installed or not enabled.');
        }

        // EasyBlog builds links, which asks Joomla for the router and the active menu item
        ApiCompat::apply($ctx->app);

        require_once JPATH_ADMINISTRATOR . self::BOOTSTRAP;

        // EasyBlog needs the Foundry library, which its own plugin provides
        if (!\EB::isFoundryEnabled()) {
            throw new ToolException('The "System - Foundry" plugin is disabled; EasyBlog cannot run without it.');
        }

        \EB::initFoundry();
        \EB::loadLanguages(JPATH_ROOT);

        $ctx->app->getLanguage()->load('com_easyblog', JPATH_ADMINISTRATOR);

        self::$booted = true;
    }

    /**
     * Save options for MCP: dates are given in UTC, no autoposting to social networks and no
     * e-mails, and Joomla's content plugins (search index, action log) still run.
     */
    public static function saveOptions(array $extra = []): array
    {
        return $extra + [
            'applyDateOffset'          => false,
            'validateData'             => false,
            'processAutopost'          => false,
            'skipNotifications'        => true,
            'useAuthorAsRevisionOwner' => true,
            'triggerPlugins'           => true,
        ];
    }
}
