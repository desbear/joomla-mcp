<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Api\Controller;

use Gss\Component\Mcp\Administrator\Mcp\Server;
use Gss\Component\Mcp\Administrator\OAuth\OAuthException;
use Gss\Component\Mcp\Administrator\OAuth\OAuthServer;
use Joomla\CMS\MVC\Controller\BaseController;

\defined('_JEXEC') or die;

/**
 * OAuth endpoints below /api/index.php/v1/mcp: metadata, dynamic client registration, token and
 * revocation. The browser part (sign-in and consent) lives in the site application.
 *
 * @since  0.2.0
 */
class OauthController extends BaseController
{
    /**
     * Authorization server metadata, served at every discovery location clients may try.
     */
    public function metadata(): void
    {
        $server = OAuthServer::create();

        if (!$server->isEnabled()) {
            $this->send(404, ['error' => 'not_found']);
        }

        $this->send(200, $server->serverMetadata());
    }

    public function openidConfiguration(): void
    {
        $server = OAuthServer::create();

        if (!$server->isEnabled()) {
            $this->send(404, ['error' => 'not_found']);
        }

        $this->send(200, $server->openidMetadata());
    }

    /**
     * Empty key set: no ID tokens or signed JWTs are issued (see OAuthServer::openidMetadata()).
     */
    public function jwks(): void
    {
        $this->send(200, ['keys' => []]);
    }

    public function protectedResource(): void
    {
        $server = OAuthServer::create();

        if (!$server->isEnabled()) {
            $this->send(404, ['error' => 'not_found']);
        }

        $this->send(200, $server->resourceMetadata());
    }

    public function register(): void
    {
        try {
            $this->send(201, OAuthServer::create()->register($this->body()));
        } catch (OAuthException $e) {
            $this->send($e->status, $e->toArray());
        }
    }

    public function token(): void
    {
        try {
            $this->send(200, OAuthServer::create()->token($this->body(), $this->basicCredentials()));
        } catch (OAuthException $e) {
            $this->send($e->status, $e->toArray(), $e->status === 401 ? ['WWW-Authenticate' => 'Basic realm="joomla-mcp"'] : []);
        }
    }

    public function revoke(): void
    {
        $body = $this->body();

        try {
            OAuthServer::create()->revoke((string) ($body['token'] ?? ''), $body, $this->basicCredentials());
            $this->send(200, []);
        } catch (OAuthException $e) {
            $this->send($e->status, $e->toArray());
        }
    }

    /**
     * Form encoded (token, revoke) or JSON (register) request body.
     */
    private function body(): array
    {
        $raw  = (string) file_get_contents('php://input');
        $type = strtolower($this->app->getInput()->server->getString('CONTENT_TYPE', ''));

        if (str_contains($type, 'json')) {
            $data = json_decode($raw, true);

            return \is_array($data) ? $data : [];
        }

        parse_str($raw, $data);

        return $data;
    }

    private function basicCredentials(): ?string
    {
        $server = $this->app->getInput()->server;
        $header = $server->getString('HTTP_AUTHORIZATION', '') ?: $server->getString('REDIRECT_HTTP_AUTHORIZATION', '');

        if (str_starts_with($header, 'Basic ')) {
            $decoded = base64_decode(substr($header, 6), true);

            return $decoded === false ? null : $decoded;
        }

        $user = $server->getString('PHP_AUTH_USER', '');

        return $user !== '' ? $user . ':' . $server->getString('PHP_AUTH_PW', '') : null;
    }

    private function send(int $status, array $payload, array $headers = []): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('Pragma: no-cache');
            // Written directly, so Joomla's onAfterRespond never runs for this response and the
            // site's HTTP headers plugin does not get to add this
            header('X-Content-Type-Options: nosniff');

            // Browser based clients (MCP Inspector and web clients) read the discovery documents
            // and call the token endpoint cross-origin, so OAuth needs this. It is safe here only
            // because Access-Control-Allow-Credentials is never sent and a grant still requires a
            // code and verifier that the calling page cannot know: do not add credentials support.
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version');

            foreach ($headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo Server::json($payload ?: new \stdClass());

        $this->app->close();
    }
}
