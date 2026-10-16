<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_system_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Gss\Plugin\System\Mcp\Extension\Mcp;
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
        // Joomla's API application has no router and no menu class, so third party code calling
        // $app->getRouter() or $app->getMenu() ends the request. Supply empty ones (see ApiCompat).
        \Gss\Component\Mcp\Administrator\Helper\ApiCompat::apply();

        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $config = (array) PluginHelper::getPlugin('system', 'mcp');

                // Joomla 5.4+ plugins take only the config; older versions expect the dispatcher first
                $first  = (new \ReflectionMethod(CMSPlugin::class, '__construct'))->getParameters()[0]->getName();
                // Joomla 4 takes the dispatcher by reference, so it must be a variable
                $dispatcher = $container->get(DispatcherInterface::class);
                $plugin     = $first === 'config'
                    ? new Mcp($config)
                    : new Mcp($dispatcher, $config);

                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
