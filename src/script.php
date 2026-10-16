<?php

/**
 * @package     MCP Server for Joomla
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

    private string $previousVersion = '';

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

    /**
     * 0.7.0 made the server read-only until an administrator decides otherwise, which is the right
     * default for a site that has just installed it. A site that was already writing content did
     * decide, so an update records that decision explicitly instead of silently taking the ability
     * away. Only an option that was never saved is filled in.
     */
    private function preserveWriteAccess(): void
    {
        try {
            $db     = Factory::getContainer()->get(DatabaseInterface::class);
            $query  = $db->getQuery(true)
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_mcp'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'));
            $params = json_decode((string) $db->setQuery($query)->loadResult(), true);

            if (!\is_array($params) || \array_key_exists('read_only', $params)) {
                return;
            }

            $params['read_only'] = '0';
            $encoded             = json_encode($params);
            $update              = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = :params')
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_mcp'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->bind(':params', $encoded);

            $db->setQuery($update)->execute();

            Factory::getApplication()->enqueueMessage(Text::_('PKG_MCP_READ_ONLY_KEPT'), 'notice');
        } catch (\Throwable) {
            // Never fail an update over this
        }
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        if (version_compare(PHP_VERSION, $this->minimumPhp, '<') || version_compare(JVERSION, $this->minimumJoomla, '<')) {
            Factory::getApplication()->enqueueMessage(Text::sprintf('PKG_MCP_REQUIRES', $this->minimumJoomla, $this->minimumPhp), 'error');

            return false;
        }

        if ($type === 'update') {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName('manifest_cache'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('pkg_mcp'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('package'));

            $this->previousVersion = (string) (json_decode((string) $db->setQuery($query)->loadResult(), true)['version'] ?? '');
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'update') {
            $this->preserveWriteAccess();
        }

        // New installs: enable everything the MCP endpoint relies on.
        // Updates: only plugins that are new since the installed version (never re-enable what an admin disabled).
        if ($type === 'install' || $type === 'discover_install') {
            // Only this package's own plugins. The core token plugins (api-authentication/token and
            // user/token) are deliberately NOT enabled here: they switch bearer-token authentication
            // on for the whole Joomla API, not just this endpoint. The dashboard health check reports
            // them, so an admin can turn them on deliberately if they want API tokens instead of OAuth.
            $plugins = [
                ['webservices', 'mcp'], ['system', 'mcp'], ['mcp', 'content'], ['mcp', 'stats'], ['mcp', 'menus'], ['mcp', 'modules'], ['mcp', 'akeeba'], ['mcp', 'flexicontent'], ['mcp', 'easyblog'], ['mcp', 'jreviews'], ['mcp', 'jlexhelpful'], ['mcp', 'media'],
            ];
        } else {
            $plugins = [];

            if ($this->previousVersion !== '' && version_compare($this->previousVersion, '0.2.0', '<')) {
                $plugins[] = ['system', 'mcp'];
            }

            if ($this->previousVersion !== '' && version_compare($this->previousVersion, '0.3.0', '<')) {
                $plugins[] = ['mcp', 'menus'];
                $plugins[] = ['mcp', 'modules'];
                $plugins[] = ['mcp', 'akeeba'];
            }

            if ($this->previousVersion !== '' && version_compare($this->previousVersion, '0.4.0', '<')) {
                $plugins[] = ['mcp', 'flexicontent'];
            }

            if ($this->previousVersion !== '' && version_compare($this->previousVersion, '0.5.0', '<')) {
                $plugins[] = ['mcp', 'easyblog'];
            }

            if ($this->previousVersion !== '' && version_compare($this->previousVersion, '0.6.0', '<')) {
                $plugins[] = ['mcp', 'jreviews'];
            }

            if ($this->previousVersion !== '' && version_compare($this->previousVersion, '0.8.0', '<')) {
                $plugins[] = ['mcp', 'jlexhelpful'];
            }

            if ($this->previousVersion !== '' && version_compare($this->previousVersion, '0.9.2', '<')) {
                $plugins[] = ['mcp', 'media'];
            }
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        foreach ($plugins as [$folder, $element]) {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = :folder')
                ->where($db->quoteName('element') . ' = :element')
                ->bind(':folder', $folder)
                ->bind(':element', $element);

            $db->setQuery($query)->execute();
        }

        // Joomla caches the list of plugins; without clearing it, new tool plugins stay invisible
        // on sites with caching enabled until the cache expires
        foreach (['com_plugins', '_system'] as $group) {
            try {
                Factory::getCache($group, '')->clean();
            } catch (\Throwable) {
                // A failing cache clean must not fail the installation
            }
        }

        if ($type === 'install' || $type === 'discover_install') {
            Factory::getApplication()->enqueueMessage(Text::sprintf('PKG_MCP_INSTALLED', 'index.php?option=com_mcp'), 'message');
        }

        return true;
    }
};
