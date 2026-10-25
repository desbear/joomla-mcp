<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_system_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\System\Mcp\Extension;

use Gss\Component\Mcp\Administrator\Mcp\Server;
use Gss\Component\Mcp\Administrator\OAuth\OAuthServer;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Answers OAuth discovery requests at the root of the domain (/.well-known/...).
 *
 * Current MCP clients find everything below /api/index.php/v1/mcp, so this is only a fallback for
 * clients that look at the domain root. It works when the web server passes unknown URLs to
 * Joomla (Joomla's .htaccess, or try_files on nginx).
 *
 * @since  0.2.0
 */
final class Mcp extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onAfterInitialise' => 'onAfterInitialise', 'onUserAfterLogin' => 'onUserAfterLogin'];
    }

    /** When the session's user signed in through Joomla's own login, for the OAuth consent page */
    public const SIGNED_IN = 'mcp.signed_in';

    /**
     * Joomla's login (a password, an authentication plugin, single sign-on through it, a remembered
     * login) ends here; a session made otherwise, by an extension that loads a user straight into
     * it, does not. MCP Server only lets an assistant be connected within a while of such a sign-in
     * (0.11.0).
     */
    public function onUserAfterLogin(): void
    {
        try {
            $this->getApplication()->getSession()->set(self::SIGNED_IN, time());
        } catch (\Throwable) {
            // No session (the API): nothing to remember
        }
    }

    public function onAfterInitialise(): void
    {
        $app  = $this->getApplication();
        $path = (string) parse_url($app->getInput()->server->getString('REQUEST_URI', ''), PHP_URL_PATH);

        if (!str_starts_with($path, '/.well-known/')) {
            return;
        }

        // Discovery documents are read-only; anything else on these URLs is Joomla's business, not ours
        if (!\in_array(strtoupper($app->getInput()->getMethod()), ['GET', 'HEAD'], true)) {
            return;
        }

        $server = OAuthServer::create();

        if (!$server->isEnabled()) {
            return;
        }

        // e.g. /.well-known/oauth-authorization-server/api/index.php/v1/mcp/oauth -> suffix must be ours or empty
        $resourcePath = (string) parse_url($server->resource(), PHP_URL_PATH);
        $issuerPath   = (string) parse_url($server->issuer(), PHP_URL_PATH);

        $documents = [
            '/.well-known/oauth-protected-resource'   => [[$resourcePath, ''], fn () => $server->resourceMetadata()],
            '/.well-known/oauth-authorization-server' => [[$issuerPath, ''], fn () => $server->serverMetadata()],
            '/.well-known/openid-configuration'       => [[$issuerPath, ''], fn () => $server->openidMetadata()],
        ];

        foreach ($documents as $prefix => [$suffixes, $build]) {
            if (!str_starts_with($path, $prefix) || !\in_array(rtrim(substr($path, \strlen($prefix)), '/'), $suffixes, true)) {
                continue;
            }

            // Only for sites installed at the domain root: the metadata must describe this host
            if (rtrim(Uri::root(true), '/') !== '') {
                return;
            }

            $liveSite = $app->get('live_site');

            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');

            // With $live_site set, the body is pinned to that URL and is safe to cache publicly.
            // Without it the body follows the Host header, so a poisoned cache could hand other
            // visitors OAuth endpoints on an attacker's host - never cache that.
            header(\is_string($liveSite) && trim($liveSite) !== '' ? 'Cache-Control: public, max-age=3600' : 'Cache-Control: no-store');

            // Written directly and followed by close(), so onAfterRespond - and with it the site's
            // HTTP Headers plugin - never runs for this response
            header('X-Content-Type-Options: nosniff');

            echo Server::json($build());

            $app->close();
        }
    }
}
