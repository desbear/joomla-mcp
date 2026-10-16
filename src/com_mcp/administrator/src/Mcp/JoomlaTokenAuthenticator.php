<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Mcp;

use Joomla\CMS\Crypt\Crypt;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Verifies Joomla API tokens for the MCP endpoint with the same rules as the core
 * "API Authentication - Token" plugin.
 *
 * The endpoint does not run all api-authentication plugins: third party ones may end the request
 * themselves (for example a JWT plugin answering every "Bearer" header with its own error), which
 * would make MCP unusable. Only the two credential types MCP supports are checked: its own OAuth
 * tokens and Joomla API tokens. The core token plugin must still be enabled.
 *
 * @since  0.3.0
 */
final class JoomlaTokenAuthenticator
{
    private const ALGORITHMS = ['sha256', 'sha512'];

    /**
     * @return  User|null  The user, or null when the token is missing, invalid or not allowed
     */
    public static function authenticate(string $token): ?User
    {
        $app = Factory::getApplication();

        // Administrators can switch API token login off by disabling the core plugin
        if ($token === '' || !PluginHelper::isEnabled('api-authentication', 'token')) {
            return null;
        }

        $decoded = base64_decode($token, true);

        if (!$decoded || substr_count($decoded, ':') < 2) {
            return null;
        }

        [$algo, $userId, $hmac] = explode(':', $decoded, 3);
        $userId = (int) $userId;
        $secret = (string) $app->get('secret');

        if (!\in_array($algo, self::ALGORITHMS, true) || $userId <= 0 || $secret === '') {
            return null;
        }

        $profile = self::profile($userId);
        $seed    = base64_decode((string) ($profile['joomlatoken.token'] ?? ''));

        if ($seed === '' || (int) ($profile['joomlatoken.enabled'] ?? 0) !== 1) {
            return null;
        }

        if (!Crypt::timingSafeCompare(hash_hmac($algo, $seed, $secret), $hmac)) {
            return null;
        }

        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);

        if (!$user->id || $user->block || !empty(trim((string) $user->activation)) || $user->requireReset) {
            return null;
        }

        if (!array_intersect(self::allowedGroups(), array_map('intval', $user->getAuthorisedGroups()))) {
            return null;
        }

        return $user->authorise('core.login.api') ? $user : null;
    }

    /**
     * @return array<string, string>
     */
    private static function profile(int $userId): array
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['profile_key', 'profile_value']))
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = :id')
            ->whereIn($db->quoteName('profile_key'), ['joomlatoken.token', 'joomlatoken.enabled'], ParameterType::STRING)
            ->bind(':id', $userId, ParameterType::INTEGER);

        return $db->setQuery($query)->loadAssocList('profile_key', 'profile_value') ?: [];
    }

    /**
     * User groups allowed to use tokens, configured in the "User - Joomla API Token" plugin.
     *
     * @return int[]
     */
    private static function allowedGroups(): array
    {
        $plugin = PluginHelper::getPlugin('user', 'token');
        $groups = $plugin ? (array) (new Registry($plugin->params))->get('allowedUserGroups', [8]) : [8];

        return array_map('intval', $groups ?: [8]);
    }
}
