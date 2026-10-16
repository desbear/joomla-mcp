<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Helper;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Menu\AbstractMenu;
use Joomla\CMS\Router\Router;

\defined('_JEXEC') or die;

/**
 * Fills two gaps of Joomla's API application that make third party code fail.
 *
 * Extensions written for the site and the administrator routinely call $app->getRouter() and
 * $app->getMenu(). Joomla builds both from the application name, so in the API application it
 * looks for the classes "JRouterApi" and "Joomla\CMS\Menu\ApiMenu", which do not exist: the
 * request then dies with "Unable to load router: api" or "Error loading menu: api".
 *
 * Providing an empty router and an empty menu lets that code run harmlessly. Nothing is routed
 * and no menu item is ever active, which is correct for an API request.
 *
 * @since  0.5.0
 */
final class ApiCompat
{
    /**
     * Apply the compatibility classes when this is an API request.
     */
    public static function apply(?CMSApplicationInterface $app = null): void
    {
        try {
            $app ??= Factory::getApplication();
        } catch (\Throwable) {
            return;
        }

        if (!$app->isClient('api')) {
            return;
        }

        if (!class_exists('JRouterApi', false)) {
            class_alias(Router::class, 'JRouterApi');
        }

        if (!class_exists('Joomla\\CMS\\Menu\\ApiMenu', false)) {
            class_alias(ApiMenu::class, 'Joomla\\CMS\\Menu\\ApiMenu');
        }

        self::loadLegacyClassAliases();
    }

    /**
     * Register Joomla's deprecated class aliases, as the site and administrator do.
     *
     * Saving anything through a core model imports the "content" plugin group, which loads every
     * enabled content plugin. A plugin still written as "extends JPlugin" then dies with
     * 'Class "JPlugin" not found', and the save fails - not because of anything the plugin does,
     * but because that alias was never registered.
     *
     * The site and administrator get the aliases from the Behaviour - Compat plugin, which
     * registers them in its constructor and is reached through an event the API application never
     * fires. Importing the group here instantiates it, which is all that is needed.
     *
     * Nothing happens when the plugin is disabled: the aliases are then deliberately absent and a
     * legacy plugin would fail in the back end too.
     */
    private static function loadLegacyClassAliases(): void
    {
        // Autoloading on purpose: the aliases are registered with JLoader and only resolve
        // through the autoloader, so a check that skips it always says "missing"
        if (class_exists('JPlugin')) {
            return;
        }

        try {
            PluginHelper::importPlugin('behaviour');
        } catch (\Throwable) {
            // A site without the plugin simply does not get the aliases
        }
    }
}
