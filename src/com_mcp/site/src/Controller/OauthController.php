<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Site\Controller;

use Gss\Component\Mcp\Administrator\OAuth\OAuthException;
use Gss\Component\Mcp\Administrator\OAuth\OAuthServer;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Utilities\IpHelper;

\defined('_JEXEC') or die;

/**
 * OAuth authorization endpoint: signs the user in with Joomla (including multi-factor
 * authentication) and asks for consent before issuing an authorization code.
 *
 * @since  0.2.0
 */
class OauthController extends BaseController
{
    private const PARAMS = ['response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method', 'resource'];

    /**
     * GET index.php?option=com_mcp&task=oauth.authorize&client_id=...
     */
    public function authorize(): void
    {
        $query  = $this->params('get');
        $server = OAuthServer::create();

        try {
            $request = $server->validateAuthorization($query);
        } catch (OAuthException $e) {
            $this->render('error', ['message' => $e->getMessage()], 400);
        }

        $user = $this->app->getIdentity();

        // Sign in first; Joomla brings the user back here afterwards (after MFA if enabled)
        if (!$user || $user->guest) {
            $return = base64_encode(Uri::getInstance()->toString());
            $this->app->redirect(Route::_('index.php?option=com_users&view=login&return=' . $return, false));
        }

        // OAuth wants these errors delivered to the client, but only once somebody is signed in:
        // redirecting an anonymous visitor would make this page a redirector for anyone with a
        // client ID, on this site's own domain
        if ($request['error']) {
            $this->app->redirect($server->redirectUrl($request['redirect_uri'], [
                'error'             => $request['error'],
                'error_description' => $request['error_description'],
                'state'             => $request['state'],
            ]));
        }

        if (!$user->authorise('mcp.use', 'com_mcp')) {
            $this->render('error', ['message' => Text::_('COM_MCP_OAUTH_NO_PERMISSION'), 'logout' => true], 403);
        }

        $this->render('consent', [
            'request' => $request,
            'params'  => $query,
            'user'    => $user,
        ]);
    }

    /**
     * POST from the consent page.
     */
    public function approve(): void
    {
        if (!Session::checkToken('post')) {
            $this->render('error', ['message' => Text::_('COM_MCP_OAUTH_SESSION_EXPIRED')], 400);
        }

        $query  = $this->params('post');
        $server = OAuthServer::create();

        try {
            $request = $server->validateAuthorization($query);
        } catch (OAuthException $e) {
            $this->render('error', ['message' => $e->getMessage()], 400);
        }

        $user = $this->app->getIdentity();

        if (!$user || $user->guest || !$user->authorise('mcp.use', 'com_mcp') || $request['error']) {
            $this->render('error', ['message' => Text::_('COM_MCP_OAUTH_NO_PERMISSION')], 403);
        }

        if ($this->input->post->get('decision', '', 'cmd') !== 'allow') {
            $this->app->redirect($server->redirectUrl($request['redirect_uri'], ['error' => 'access_denied', 'state' => $request['state']]));
        }

        // Read access is always granted; the others only when ticked
        $ticked = (array) $this->input->post->get('scopes', [], 'array');
        $scopes = array_values(array_unique(array_merge(['mcp:read'], array_intersect($ticked, $request['scopes']))));
        $code   = $server->issueCode($request, $user, $scopes);

        $this->audit($request, $scopes);

        $this->app->redirect($server->redirectUrl($request['redirect_uri'], ['code' => $code, 'state' => $request['state']]));
    }

    /**
     * The OAuth parameters of the request, unfiltered (state and redirect URIs must stay intact).
     */
    private function params(string $source): array
    {
        $input  = $source === 'post' ? $this->input->post : $this->input->get;
        $params = [];

        foreach (self::PARAMS as $name) {
            $value = $input->get($name, null, 'raw');

            if (\is_string($value)) {
                $params[$name] = $value;
            }
        }

        return $params;
    }

    /**
     * Record the grant in the MCP activity log.
     */
    private function audit(array $request, array $scopes): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $record = (object) [
                'created'     => Factory::getDate()->toSql(),
                'user_id'     => (int) $this->app->getIdentity()->id,
                'tool'        => 'oauth:authorize',
                'arguments'   => json_encode([
                    'client'       => $request['client']->client_name,
                    'client_id'    => $request['client']->client_id,
                    'redirect_uri' => $request['redirect_uri'],
                    'scopes'       => $scopes,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status'      => 'success',
                'message'     => '',
                'duration_ms' => 0,
                'ip'          => mb_substr((string) IpHelper::getIp(), 0, 64),
                'user_agent'  => mb_substr($this->input->server->getString('HTTP_USER_AGENT', ''), 0, 255),
            ];

            $db->insertObject('#__mcp_logs', $record);
        } catch (\Throwable) {
            // Never block sign-in because of logging
        }
    }

    /**
     * Output a standalone page (independent of the site template) and stop.
     */
    private function render(string $layout, array $data, int $status = 200): never
    {
        $data['siteName'] = $this->app->get('sitename');
        $data['logoutUrl'] = Route::_('index.php?option=com_users&task=user.logout&' . Session::getFormToken() . '=1&return=' . base64_encode(Uri::getInstance()->toString()), false);

        // The layout name is never attacker supplied today; keeping it to a list means it cannot
        // become a file inclusion if a caller is ever added
        if (!\in_array($layout, ['consent', 'error'], true)) {
            throw new \InvalidArgumentException('Unknown OAuth layout.');
        }

        extract($data, EXTR_SKIP);

        ob_start();
        include JPATH_SITE . '/components/com_mcp/tmpl/oauth/' . $layout . '.php';
        $html = ob_get_clean();

        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'");
        header('Referrer-Policy: no-referrer');

        echo $html;

        $this->app->close();
    }
}
