<?php

/**
 * @package     Guides
 * @subpackage  pkg_guides
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

return new class () implements InstallerScriptInterface {
    private string $minimumJoomla = '4.4.0';

    private string $minimumPhp = '8.1.0';

    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type !== 'uninstall' && (version_compare(PHP_VERSION, $this->minimumPhp, '<') || version_compare(JVERSION, $this->minimumJoomla, '<'))) {
            Factory::getApplication()->enqueueMessage(Text::sprintf('PKG_GUIDES_REQUIRES', $this->minimumJoomla, $this->minimumPhp), 'error');

            return false;
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'install' || $type === 'discover_install') {
            $this->showOnHome();
            Factory::getApplication()->enqueueMessage(Text::sprintf('PKG_GUIDES_INSTALLED', 'index.php?option=com_guides'), 'message');
        }

        return true;
    }

    /**
     * Put "Guides to review" on the administrator home page: the module Joomla created when it
     * installed it, as long as nobody placed it yet.
     */
    private function showOnHome(): void
    {
        try {
            $db     = Factory::getContainer()->get(DatabaseInterface::class);
            $module = $db->setQuery(
                $db->getQuery(true)
                    ->select('id, position, published')
                    ->from($db->quoteName('#__modules'))
                    ->where($db->quoteName('module') . ' = ' . $db->quote('mod_guides_review'))
                    ->where($db->quoteName('client_id') . ' = 1')
                    ->order('id')
            )->loadObject();

            if (!$module || $module->position !== '') {
                return;
            }

            Factory::getApplication()->getLanguage()->load('mod_guides_review.sys', JPATH_ADMINISTRATOR);

            $row = (object) [
                'id'        => (int) $module->id,
                'title'     => Text::_('MOD_GUIDES_REVIEW') !== 'MOD_GUIDES_REVIEW' ? Text::_('MOD_GUIDES_REVIEW') : 'MCP - Guides to review',
                'position'  => 'cpanel',
                'published' => 1,
                'access'    => 1,
                'ordering'  => 1,
                'params'    => json_encode(['count' => 5, 'bootstrap_size' => '6', 'header_tag' => 'h2', 'module_tag' => 'div']),
            ];
            $db->updateObject('#__modules', $row, 'id');

            $assigned = $db->setQuery($db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__modules_menu'))->where('moduleid = ' . (int) $module->id))->loadResult();

            if (!$assigned) {
                $menu = (object) ['moduleid' => (int) $module->id, 'menuid' => 0];
                $db->insertObject('#__modules_menu', $menu);
            }
        } catch (\Throwable) {
            // The module can still be placed by hand
        }
    }
};
