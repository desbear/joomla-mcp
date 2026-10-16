<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_akeeba
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Akeeba\Tool;

use Akeeba\Component\AkeebaBackup\Administrator\Mixin\AkeebaEngineTrait;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Joomla\Session\Session;
use Joomla\Session\Storage\RuntimeStorage;

\defined('_JEXEC') or die;

/**
 * Boots the Akeeba Backup engine inside the API application, using Akeeba's own loader.
 *
 * Only autoload this class after checking that the trait exists (AkeebaTools::engineAvailable()).
 *
 * @since  0.3.0
 */
final class AkeebaEngine
{
    use AkeebaEngineTrait;

    /**
     * @return \Akeeba\Component\AkeebaBackup\Administrator\Model\BackupModel
     */
    public static function backupModel(ToolContext $ctx): object
    {
        $app = $ctx->app;

        // The engine keeps the active profile in the session; the API application has none
        try {
            $app->getSession();
        } catch (\RuntimeException) {
            $session = new Session(new RuntimeStorage());
            $session->start();
            $app->setSession($session);
        }

        $lang = $app->getLanguage();
        $lang->load('com_akeebabackup', JPATH_ADMINISTRATOR, 'en-GB', true, true);
        $lang->load('com_akeebabackup', JPATH_ADMINISTRATOR, null, true, false);

        static $loaded = false;

        if (!$loaded) {
            $engine = new self();
            $engine->loadAkeebaEngine();
            $engine->loadAkeebaEngineConfiguration();
            $loaded = true;
        }

        return $app->bootComponent('com_akeebabackup')->getMVCFactory()
            ->createModel('Backup', 'Administrator', ['ignore_request' => true]);
    }
}
