<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Helper;

use Gss\Component\Mcp\Administrator\Mcp\Maintenance;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Server-side checks shown on the dashboard. The Authorization header check runs in the
 * browser (see media/js/dashboard.js) because it needs a real HTTP request.
 *
 * @since  0.1.0
 */
final class HealthCheck
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    /**
     * @return  array<int, array{status: string, label: string, detail: string, fix: string}>
     */
    public static function run(User $user): array
    {
        $checks = [];
        $params = ComponentHelper::getParams('com_mcp');

        $checks[] = self::check(
            version_compare(PHP_VERSION, '8.1', '>=') ? self::OK : self::ERROR,
            Text::_('COM_MCP_CHECK_PHP'),
            PHP_VERSION,
            Text::_('COM_MCP_CHECK_PHP_FIX')
        );

        $https = str_starts_with(Uri::root(), 'https://') || Uri::getInstance()->isSsl();
        $checks[] = self::check(
            $https ? self::OK : self::WARNING,
            Text::_('COM_MCP_CHECK_HTTPS'),
            $https ? 'HTTPS' : 'HTTP',
            Text::_('COM_MCP_CHECK_HTTPS_FIX')
        );

        // The dashboard builds copy-paste endpoint URLs; with $live_site empty they follow the Host
        // header, so a Host-poisoning position can change the URL an admin pastes into an AI client
        $liveSite = trim((string) Factory::getApplication()->get('live_site', ''));
        $checks[] = self::check(
            $liveSite !== '' ? self::OK : self::WARNING,
            Text::_('COM_MCP_CHECK_LIVE_SITE'),
            $liveSite !== '' ? $liveSite : Text::_('JNONE'),
            Text::_('COM_MCP_CHECK_LIVE_SITE_FIX')
        );

        $checks[] = self::check(
            $params->get('enabled', 1) ? self::OK : self::ERROR,
            Text::_('COM_MCP_CHECK_ENABLED'),
            $params->get('enabled', 1) ? Text::_('JENABLED') : Text::_('JDISABLED'),
            Text::_('COM_MCP_CHECK_ENABLED_FIX')
        );

        // The endpoint cannot work without this one
        $wsEnabled = PluginHelper::isEnabled('webservices', 'mcp');
        $checks[]  = self::check(
            $wsEnabled ? self::OK : self::ERROR,
            Text::sprintf('COM_MCP_CHECK_PLUGIN', self::pluginName('webservices', 'mcp')),
            $wsEnabled ? Text::_('JENABLED') : Text::_('JDISABLED'),
            Text::sprintf('COM_MCP_CHECK_PLUGIN_FIX', self::e('webservices'), self::e('mcp'))
        );

        // Only needed for API token login. The installer deliberately leaves these alone because they
        // switch bearer-token authentication on for the whole Joomla API; OAuth sign-in works without
        // them, so this is a warning rather than an error.
        foreach ([['api-authentication', 'token'], ['user', 'token']] as [$group, $element]) {
            $enabled  = PluginHelper::isEnabled($group, $element);
            $checks[] = self::check(
                $enabled ? self::OK : self::WARNING,
                Text::sprintf('COM_MCP_CHECK_PLUGIN', self::pluginName($group, $element)),
                $enabled ? Text::_('JENABLED') : Text::_('JDISABLED'),
                Text::sprintf('COM_MCP_CHECK_PLUGIN_TOKEN_FIX', self::e($group), self::e($element))
            );
        }

        $toolPlugins = PluginHelper::getPlugin('mcp');
        $checks[]    = self::check(
            $toolPlugins ? self::OK : self::WARNING,
            Text::_('COM_MCP_CHECK_TOOL_PLUGINS'),
            implode(', ', array_map(fn ($p) => $p->name, $toolPlugins)) ?: Text::_('JNONE'),
            Text::_('COM_MCP_CHECK_TOOL_PLUGINS_FIX')
        );

        $checks[] = self::check(
            $user->authorise('mcp.use', 'com_mcp') ? self::OK : self::WARNING,
            Text::_('COM_MCP_CHECK_PERMISSION'),
            $user->authorise('mcp.use', 'com_mcp') ? Text::_('JYES') : Text::_('JNO'),
            Text::_('COM_MCP_CHECK_PERMISSION_FIX')
        );

        [$hasToken, $groupAllowed] = self::tokenState($user);
        $checks[] = self::check(
            $hasToken && $groupAllowed ? self::OK : self::WARNING,
            Text::_('COM_MCP_CHECK_TOKEN'),
            !$groupAllowed ? Text::_('COM_MCP_CHECK_TOKEN_GROUP') : ($hasToken ? Text::_('JENABLED') : Text::_('COM_MCP_CHECK_TOKEN_NONE')),
            !$groupAllowed ? Text::_('COM_MCP_CHECK_TOKEN_GROUP_FIX') : Text::_('COM_MCP_CHECK_TOKEN_FIX')
        );

        // Old records forgotten every day by the scheduled task (0.11.0)
        try {
            $last = Maintenance::last(Factory::getContainer()->get(DatabaseInterface::class));
        } catch (\Throwable) {
            $last = null;
        }

        $late     = !$last || $last['at'] < Factory::getDate('-2 days')->toSql();
        $checks[] = self::check(
            $late ? self::WARNING : self::OK,
            Text::_('COM_MCP_CHECK_FORGET'),
            $last ? Text::sprintf('COM_MCP_CHECK_FORGET_LAST', HTMLHelper::_('date', $last['at'], Text::_('DATE_FORMAT_LC5')), array_sum($last['counts'])) : Text::_('JNONE'),
            Text::_('COM_MCP_CHECK_FORGET_FIX')
        );

        return $checks;
    }

    /**
     * Whether the user has an enabled API token, and whether their user group may use tokens.
     *
     * @return array{0: bool, 1: bool}
     */
    public static function tokenState(User $user): array
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $id    = (int) $user->id;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['profile_key', 'profile_value']))
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = :id')
            ->where($db->quoteName('profile_key') . ' LIKE ' . $db->quote('joomlatoken.%'))
            ->bind(':id', $id, ParameterType::INTEGER);

        $profile  = $db->setQuery($query)->loadAssocList('profile_key', 'profile_value');
        $hasToken = !empty($profile['joomlatoken.token']) && (int) ($profile['joomlatoken.enabled'] ?? 0) === 1;

        $plugin  = PluginHelper::getPlugin('user', 'token');
        $allowed = $plugin ? (array) (new Registry($plugin->params))->get('allowedUserGroups', [8]) : [8];

        return [$hasToken, (bool) array_intersect(array_map('intval', $allowed), array_map('intval', $user->getAuthorisedGroups()))];
    }

    private static function pluginName(string $group, string $element): string
    {
        $key  = strtoupper('plg_' . str_replace('-', '', $group) . '_' . $element);
        $lang = Factory::getApplication()->getLanguage();
        $lang->load(strtolower('plg_' . $group . '_' . $element) . '.sys', JPATH_ADMINISTRATOR);
        $lang->load(strtolower('plg_' . $group . '_' . $element) . '.sys', JPATH_PLUGINS . '/' . $group . '/' . $element);

        return $lang->hasKey($key) ? Text::_($key) : $group . '/' . $element;
    }

    /**
     * Escape a value that is interpolated into a "fix" string.
     *
     * "fix" is the one field the dashboard template prints unescaped, because the language strings
     * deliberately contain links and <code> markup. Every dynamic value put into one must therefore
     * be escaped here, at the point the string is built.
     */
    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * "label" and "detail" are escaped by the template; "fix" is language markup rendered as HTML,
     * so any dynamic part of it must already have gone through self::e().
     */
    private static function check(string $status, string $label, string $detail, string $fix): array
    {
        return ['status' => $status, 'label' => $label, 'detail' => $detail, 'fix' => $status === self::OK ? '' : $fix];
    }
}
