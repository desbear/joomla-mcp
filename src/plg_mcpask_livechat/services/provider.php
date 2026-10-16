<?php

/**
 * @package     Ask the Site
 * @subpackage  plg_mcpask_livechat
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Gss\Plugin\Mcpask\Livechat\Extension\Livechat;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
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
                $plugin = new Livechat($container->get(DispatcherInterface::class), (array) PluginHelper::getPlugin('mcpask', 'livechat'));
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
