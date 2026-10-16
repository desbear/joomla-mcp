<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Api\Controller;

use Gss\Component\Mcp\Administrator\Mcp\JoomlaTokenAuthenticator;
use Gss\Component\Mcp\Administrator\Mcp\Logger;
use Gss\Component\Mcp\Administrator\Mcp\Server;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolRegistry;
use Gss\Component\Mcp\Administrator\OAuth\OAuthServer;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Utilities\IpHelper;

\defined('_JEXEC') or die;

/**
 * The MCP endpoint: /api/index.php/v1/mcp
 *
 * The route is public so this controller can answer authentication failures itself with a proper
 * 401 + WWW-Authenticate response; the actual check uses Joomla's api-authentication plugins
 * (API token as "Authorization: Bearer" or "X-Joomla-Token" header).
 *
 * @since  0.1.0
 */
class McpController extends BaseController
{
    /** Largest JSON-RPC request body accepted, leaving room for a long article. */
    private const MAX_BODY_BYTES = 4194304;

    /** Rejected credentials allowed from one address per hour before it is turned away. */
    private const MAX_AUTH_FAILURES = 30;

    /** Tool calls allowed per user per minute. */
    private const MAX_CALLS_PER_MINUTE = 120;

    /**
     * POST /v1/mcp - handle a JSON-RPC message.
     */
    public function handle(): void
    {
        $params = ComponentHelper::getParams('com_mcp');

        if (!$params->get('enabled', 1)) {
            $this->send(503, Server::error(null, -32000, 'The MCP server is disabled in Components > MCP Server > Options.'));
        }

        $db     = \Joomla\CMS\Factory::getContainer()->get(DatabaseInterface::class);
        $oauth  = OAuthServer::create();
        $bearer = $this->bearerToken();
        $scopes = null;
        $ip     = (string) IpHelper::getIp();

        // The route is public so this controller can answer with a proper 401, which also means
        // anyone can reach the credential checks below. Turn away an address that keeps failing.
        if (Logger::countRecent($db, 'auth:failed', 3600, null, $ip) >= self::MAX_AUTH_FAILURES) {
            $this->send(
                429,
                Server::error(null, -32000, 'Too many failed authentication attempts from this address. Try again later.'),
                ['Retry-After' => '3600']
            );
        }

        if ($bearer !== '' && OAuthServer::looksLikeAccessToken($bearer)) {
            // OAuth access token issued by this site
            $grant = $oauth->validateAccessToken($bearer);

            if ($grant === null) {
                Logger::security($db, 'auth:failed', 'Invalid or expired OAuth access token.', 0, $this->userAgent());

                $this->send(
                    401,
                    Server::error(null, -32001, 'The access token is invalid or has expired.'),
                    ['WWW-Authenticate' => $oauth->challenge() . ', error="invalid_token"']
                );
            }

            $user = new User($grant['user_id']);

            // The same account checks the API token path makes, so an account that was blocked,
            // never activated or forced to reset its password cannot keep using an issued token
            if (!$user->id || $user->block || !empty(trim((string) $user->activation)) || $user->requireReset) {
                Logger::security($db, 'auth:failed', 'OAuth token for an account that cannot sign in.', (int) $user->id, $this->userAgent());

                $this->send(401, Server::error(null, -32001, 'This user account cannot be used.'), ['WWW-Authenticate' => $oauth->challenge() . ', error="invalid_token"']);
            }

            $this->app->loadIdentity($user);
            $scopes = OAuthServer::toolScopes($grant['scopes']);
        } else {
            // Joomla API token (Authorization: Bearer or X-Joomla-Token), checked without running
            // third party authentication plugins that could end the request (see JoomlaTokenAuthenticator)
            $token = $bearer !== '' ? $bearer : trim($this->app->getInput()->server->getString('HTTP_X_JOOMLA_TOKEN', ''));
            $user  = JoomlaTokenAuthenticator::authenticate($token);

            if ($user === null) {
                Logger::security($db, 'auth:failed', 'Invalid or missing Joomla API token.', 0, $this->userAgent());

                $this->send(
                    401,
                    Server::error(null, -32001, 'Authentication required: sign in with OAuth, or send a Joomla API token as "Authorization: Bearer <token>" or "X-Joomla-Token: <token>".'),
                    ['WWW-Authenticate' => $oauth->challenge()]
                );
            }

            $this->app->loadIdentity($user);
        }

        $user = $this->app->getIdentity();

        // Core text filtering (ComponentHelper::filterText) and a number of extensions read the
        // user from the session through Factory::getUser(). Without this they see a guest, and
        // the "No HTML" filter of the Public group would strip the HTML out of saved content.
        try {
            $this->app->getSession()->set('user', $user);
        } catch (\Throwable) {
            // A session that cannot hold the user must not break the request
        }

        if (!$user || !$user->id || !$user->authorise('mcp.use', 'com_mcp')) {
            Logger::security($db, 'auth:failed', 'Authenticated but without the "Use MCP Server" permission.', (int) ($user->id ?? 0), $this->userAgent());

            $this->send(403, Server::error(null, -32001, 'This user is not allowed to use the MCP server (permission "Use MCP Server" in com_mcp).'));
        }

        // The per-user limit counts activity log rows, so it only works while logging is on.
        // Failed credentials are recorded regardless, so the check above always applies.
        $perMinute = max(1, (int) $params->get('rate_limit_per_minute', self::MAX_CALLS_PER_MINUTE));

        if ($params->get('log_enabled', 1) && Logger::countRecent($db, '*', 60, (int) $user->id) >= $perMinute) {
            $this->send(
                429,
                Server::error(null, -32000, \sprintf('Rate limit reached: at most %d tool calls per minute. Wait a moment and try again.', $perMinute)),
                ['Retry-After' => '60']
            );
        }

        $context = new ToolContext($this->app, $user, $db, $params, $scopes);
        $server  = new Server(ToolRegistry::load(), $context, new Logger($context));

        $body = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);

        if (\strlen($body) > self::MAX_BODY_BYTES) {
            $this->send(413, Server::error(null, -32600, \sprintf('The request body may be at most %d bytes.', self::MAX_BODY_BYTES)));
        }

        // Tools may call models that print warnings; never let them corrupt the JSON response
        ob_start();
        $response = $server->handle($body);
        ob_end_clean();

        if ($response === null) {
            $this->send(202, null);
        }

        $this->send(200, $response);
    }

    private function userAgent(): string
    {
        return $this->app->getInput()->server->getString('HTTP_USER_AGENT', '');
    }

    private function bearerToken(): string
    {
        $server = $this->app->getInput()->server;
        $header = $server->getString('HTTP_AUTHORIZATION', '') ?: $server->getString('REDIRECT_HTTP_AUTHORIZATION', '');

        if ($header === '' && \function_exists('apache_request_headers')) {
            $headers = array_change_key_case((array) apache_request_headers(), CASE_LOWER);
            $header  = (string) ($headers['authorization'] ?? '');
        }

        return str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : '';
    }

    /**
     * GET / DELETE /v1/mcp - this server does not offer an SSE stream or sessions.
     */
    public function notAllowed(): void
    {
        // Clients probing for an SSE stream without credentials expect the OAuth challenge
        if ($this->bearerToken() === '' && !$this->app->getInput()->server->getString('HTTP_X_JOOMLA_TOKEN', '')) {
            $this->send(401, Server::error(null, -32001, 'Authentication required.'), ['WWW-Authenticate' => OAuthServer::create()->challenge()]);
        }

        $this->send(405, Server::error(null, -32000, 'Method not allowed. Send JSON-RPC messages with POST.'), ['Allow' => 'POST']);
    }

    /**
     * GET /v1/mcp/health - public check used by the dashboard to detect whether the web server
     * passes the Authorization header on to PHP (a very common hosting problem). The
     * X-Joomla-Token header is checked too, as it is the fallback when Authorization is stripped.
     */
    public function health(): void
    {
        $server = $this->app->getInput()->server;
        $header = $server->getString('HTTP_AUTHORIZATION', '') ?: $server->getString('REDIRECT_HTTP_AUTHORIZATION', '');

        if ($header === '' && \function_exists('apache_request_headers')) {
            $headers = array_change_key_case((array) apache_request_headers(), CASE_LOWER);
            $header  = (string) ($headers['authorization'] ?? '');
        }

        $this->send(200, [
            'server'               => Server::NAME,
            'authorization_header' => str_starts_with($header, 'Bearer mcp-health-check'),
            'x_joomla_token'       => $server->getString('HTTP_X_JOOMLA_TOKEN', '') === 'mcp-health-check',
        ]);
    }

    /**
     * Write the response and stop, bypassing the JSON:API document rendering.
     */
    private function send(int $status, ?array $payload, array $headers = []): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Cache-Control: no-store');
            // This response is written directly and bypasses onAfterRespond, so the site's
            // HTTP headers plugin never sees it
            header('X-Content-Type-Options: nosniff');

            if ($payload !== null) {
                header('Content-Type: application/json; charset=utf-8');
            }

            foreach ($headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        if ($payload !== null) {
            echo Server::json($payload);
        }

        $this->app->close();
    }
}
