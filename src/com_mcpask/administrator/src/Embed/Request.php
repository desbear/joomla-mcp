<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Embed;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * A request from Ask the Site embedded on another website: the key it is embedded with
 * (X-Mcp-Ask-Embed) and, when the visitor is known, the token that says who they are
 * (X-Mcp-Ask-Identity). Such requests come from this site's own page in the frame, so the
 * same-origin checks hold as for the module.
 *
 * @since  0.11.0
 */
final class Request
{
    /** @var object|null|false  The key of this request, once looked up (false: not yet) */
    private static object|null|false $embed = false;

    /**
     * The embed key of this request, if any; the visitor its token stands for becomes the
     * request's user (for this request only, nothing is kept in the session).
     */
    public static function apply(CMSApplicationInterface $app): ?object
    {
        if (self::$embed !== false) {
            return self::$embed;
        }

        $server = $app->getInput()->server;
        $key    = $server->getString('HTTP_X_MCP_ASK_EMBED', '');

        self::$embed = null;

        if ($key === '') {
            return null;
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $embed = (new Embeds($db))->byKey($key);

        if (!$embed) {
            return null;
        }

        self::$embed = $embed;
        $token       = $server->getString('HTTP_X_MCP_ASK_IDENTITY', '');
        $current     = $app->getIdentity();

        if ($token !== '' && (!$current || $current->guest) && method_exists($app, 'loadIdentity')) {
            $user = (new Identity($db, (string) $app->get('secret')))->user(mb_substr($token, 0, 4000), $embed);

            if ($user) {
                $app->loadIdentity($user);
            }
        }

        return $embed;
    }
}
