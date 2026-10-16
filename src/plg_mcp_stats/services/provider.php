<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_stats
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Gss\Plugin\Mcp\Stats\Extension\Stats;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $config = (array) PluginHelper::getPlugin('mcp', 'stats');

                // Joomla 5.4+ plugins take only the config; older versions expect the dispatcher first
                $first  = (new \ReflectionMethod(CMSPlugin::class, '__construct'))->getParameters()[0]->getName();
                // Joomla 4 takes the dispatcher by reference, so it must be a variable
                $dispatcher = $container->get(DispatcherInterface::class);
                $plugin     = $first === 'config'
                    ? new Stats($config)
                    : new Stats($dispatcher, $config);

                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
