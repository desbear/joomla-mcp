<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_webservices_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\WebServices\Mcp\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Router\Route;

\defined('_JEXEC') or die;

/**
 * Registers the MCP endpoint /api/index.php/v1/mcp in the Joomla API application.
 *
 * @since  0.1.0
 */
final class Mcp extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onBeforeApiRoute' => 'onBeforeApiRoute'];
    }

    public function onBeforeApiRoute(EventInterface $event): void
    {
        // Joomla 5+ passes a BeforeApiRouteEvent, Joomla 4 a generic event with the router as first argument
        $router = method_exists($event, 'getRouter') ? $event->getRouter() : ($event->getArgument('router') ?? $event->getArgument(0));

        $app = $this->getApplication();

        // MCP clients send "Accept: application/json, text/event-stream"; both are answered with plain JSON
        if (method_exists($app, 'addFormatMap')) {
            $app->addFormatMap('text/event-stream', 'json');
        }

        $defaults = ['component' => 'com_mcp', 'format' => ['application/json', 'text/event-stream']];

        $public = $defaults + ['public' => true];

        // OAuth clients may send any Accept header (or */*) to these JSON endpoints
        $oauth = ['component' => 'com_mcp', 'public' => true, 'format' => ['application/json', 'text/event-stream', 'text/html', 'text/plain']];

        // These two relax Joomla's content negotiation for the whole request, so only add them when
        // this request is actually for an MCP route - otherwise every core webservice would inherit them
        if (method_exists($app, 'addFormatMap') && $this->isMcpRequest()) {
            $app->addFormatMap('text/html', 'json');
            $app->addFormatMap('text/plain', 'json');
        }

        $router->addRoutes([
            // Public: McpController authenticates itself to answer with a proper 401
            new Route(['POST'], 'v1/mcp', 'mcp.handle', [], $public),
            new Route(['GET', 'DELETE'], 'v1/mcp', 'mcp.notAllowed', [], $public),
            new Route(['GET'], 'v1/mcp/health', 'mcp.health', [], $public),

            // OAuth 2.1 (see OAuthServer); the issuer is /v1/mcp/oauth
            new Route(['GET'], 'v1/mcp/oauth-protected-resource', 'oauth.protectedResource', [], $oauth),
            new Route(['GET'], 'v1/mcp/.well-known/oauth-protected-resource', 'oauth.protectedResource', [], $oauth),
            new Route(['GET'], 'v1/mcp/oauth/.well-known/oauth-authorization-server', 'oauth.metadata', [], $oauth),
            new Route(['GET'], 'v1/mcp/oauth/.well-known/openid-configuration', 'oauth.openidConfiguration', [], $oauth),
            new Route(['GET'], 'v1/mcp/oauth/jwks', 'oauth.jwks', [], $oauth),
            new Route(['POST'], 'v1/mcp/oauth/register', 'oauth.register', [], $oauth),
            new Route(['POST'], 'v1/mcp/oauth/token', 'oauth.token', [], $oauth),
            new Route(['POST'], 'v1/mcp/oauth/revoke', 'oauth.revoke', [], $oauth),
        ]);
    }

    /**
     * Whether the current API request addresses one of the v1/mcp routes registered above.
     *
     * The router has not parsed the URL yet when onBeforeApiRoute fires, so the path is read from
     * the request itself - the same way plg_system_mcp finds the /.well-known URLs.
     */
    private function isMcpRequest(): bool
    {
        $path = (string) parse_url($this->getApplication()->getInput()->server->getString('REQUEST_URI', ''), PHP_URL_PATH);

        return (bool) preg_match('#(^|/)v1/mcp(/|$)#', rtrim($path, '/') . '/');
    }
}
