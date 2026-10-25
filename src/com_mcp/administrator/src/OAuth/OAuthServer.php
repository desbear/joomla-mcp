<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\OAuth;

use Gss\Component\Mcp\Administrator\Mcp\Logger;
use Gss\Component\Mcp\Administrator\Mcp\Tool;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * A minimal OAuth 2.1 authorization server for the MCP endpoint, following the MCP authorization
 * specification: authorization code + PKCE (S256), refresh token rotation, dynamic client
 * registration (RFC 7591), client ID metadata documents, resource indicators (RFC 8707),
 * authorization server metadata (RFC 8414) and protected resource metadata (RFC 9728).
 *
 * Only hashes of codes, tokens and client secrets are stored.
 *
 * @since  0.2.0
 */
final class OAuthServer
{
    /** OAuth scope => tool scope */
    public const SCOPES = [
        'mcp:read'    => Tool::SCOPE_READ,
        'mcp:write'   => Tool::SCOPE_WRITE,
        'mcp:publish' => Tool::SCOPE_PUBLISH,
        'mcp:delete'  => Tool::SCOPE_DELETE,
    ];

    public const DEFAULT_REDIRECT_HOSTS = "claude.ai\nclaude.com\nchatgpt.com\nvscode.dev\ninsiders.vscode.dev\ncursor://";

    private const CODE_TTL = 120;

    /** Largest client ID metadata document that will be parsed. */
    private const METADATA_MAX_BYTES = 65536;

    /** Client registrations allowed from one address per hour. */
    private const MAX_REGISTRATIONS_PER_IP = 5;

    /** Client registrations allowed site wide per day. */
    private const MAX_REGISTRATIONS_PER_DAY = 50;

    private const ACCESS_PREFIX = 'mcpat_';

    private const REFRESH_PREFIX = 'mcprt_';

    private readonly string $root;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly Registry $params
    ) {
        // Uri::root() follows the Host header when $live_site is empty, which would let a request
        // decide what this server calls itself; the configured site URL wins when there is one
        $live       = trim((string) Factory::getApplication()->get('live_site', ''));
        $this->root = rtrim($live !== '' ? $live : Uri::root(), '/');
    }

    public static function create(): self
    {
        return new self(Factory::getContainer()->get(DatabaseInterface::class), ComponentHelper::getParams('com_mcp'));
    }

    public function isEnabled(): bool
    {
        return (bool) $this->params->get('oauth_enabled', 1);
    }

    // ------------------------------------------------------------------ URLs and metadata

    /** The MCP endpoint, which is the OAuth protected resource. */
    public function resource(): string
    {
        return $this->root . '/api/index.php/v1/mcp';
    }

    public function issuer(): string
    {
        return $this->resource() . '/oauth';
    }

    public function authorizationEndpoint(): string
    {
        return $this->root . '/index.php?option=com_mcp&task=oauth.authorize';
    }

    public function resourceMetadataUrl(): string
    {
        return $this->resource() . '/oauth-protected-resource';
    }

    /**
     * RFC 8414 authorization server metadata (also served as openid-configuration for clients that look there).
     */
    public function serverMetadata(): array
    {
        return [
            'issuer'                                => $this->issuer(),
            'authorization_endpoint'                => $this->authorizationEndpoint(),
            'token_endpoint'                        => $this->issuer() . '/token',
            'registration_endpoint'                 => $this->issuer() . '/register',
            'revocation_endpoint'                   => $this->issuer() . '/revoke',
            'response_types_supported'              => ['code'],
            'response_modes_supported'              => ['query'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'      => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'scopes_supported'                      => array_keys(self::SCOPES),
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
            'subject_types_supported'               => ['public'],
        ];
    }

    /**
     * The same metadata for clients that read /.well-known/openid-configuration (on hosts without
     * URL rewriting that is the only discovery location below the API). OpenID Connect discovery
     * requires jwks_uri and ID token algorithms; this server never issues ID tokens, so the key set
     * is empty and the "openid" scope is not supported.
     */
    public function openidMetadata(): array
    {
        return $this->serverMetadata() + [
            'jwks_uri'                              => $this->issuer() . '/jwks',
            'id_token_signing_alg_values_supported' => ['RS256'],
        ];
    }

    /**
     * RFC 9728 protected resource metadata.
     */
    public function resourceMetadata(): array
    {
        return [
            'resource'                 => $this->resource(),
            'authorization_servers'    => [$this->issuer()],
            'scopes_supported'         => array_keys(self::SCOPES),
            'bearer_methods_supported' => ['header'],
            'resource_name'            => Factory::getApplication()->get('sitename'),
        ];
    }

    /**
     * WWW-Authenticate header value for a 401 response from the MCP endpoint.
     */
    public function challenge(): string
    {
        $value = 'Bearer realm="joomla-mcp"';

        if ($this->isEnabled()) {
            $value .= ', resource_metadata="' . $this->resourceMetadataUrl() . '", scope="' . implode(' ', $this->availableScopes()) . '"';
        }

        return $value;
    }

    /**
     * Scopes the configuration allows at all.
     *
     * @return string[]
     */
    public function availableScopes(): array
    {
        $scopes = ['mcp:read'];

        if (!$this->params->get('read_only', 1)) {
            $scopes[] = 'mcp:write';

            if ($this->params->get('allow_publish', 1)) {
                $scopes[] = 'mcp:publish';
            }

            if ($this->params->get('allow_delete', 0)) {
                $scopes[] = 'mcp:delete';
            }
        }

        return $scopes;
    }

    // ------------------------------------------------------------------ Clients

    /**
     * Dynamic client registration (RFC 7591).
     */
    public function register(array $meta): array
    {
        if (!$this->params->get('oauth_registration', 1)) {
            throw new OAuthException('access_denied', 'Dynamic client registration is disabled on this site.', 403);
        }

        // Registration is anonymous by necessity (RFC 7591), so it needs a limit of its own:
        // without one, an attacker can mint client IDs in bulk and fill the client table
        $ip = (string) \Joomla\CMS\Factory::getApplication()->getInput()->server->getString('REMOTE_ADDR', '');

        if (Logger::countRecent($this->db, 'oauth:register', 3600, null, $ip) >= self::MAX_REGISTRATIONS_PER_IP
            || Logger::countRecent($this->db, 'oauth:register', 86400) >= self::MAX_REGISTRATIONS_PER_DAY) {
            throw new OAuthException('access_denied', 'Too many client registrations. Try again later, or ask the site administrator to register the client.', 429);
        }

        $redirects = $meta['redirect_uris'] ?? null;

        if (!\is_array($redirects) || !$redirects) {
            throw new OAuthException('invalid_redirect_uri', 'redirect_uris is required.');
        }

        foreach ($redirects as $uri) {
            if (!\is_string($uri) || !$this->isAllowedRedirect($uri)) {
                throw new OAuthException('invalid_redirect_uri', \sprintf('Redirect URI "%s" is not allowed on this site. The administrator can allow it in Components > MCP Server > Options > OAuth.', \is_string($uri) ? $uri : ''));
            }
        }

        $grantTypes = array_values(array_intersect((array) ($meta['grant_types'] ?? ['authorization_code']), ['authorization_code', 'refresh_token']));

        if (!\in_array('authorization_code', $grantTypes, true)) {
            throw new OAuthException('invalid_client_metadata', 'grant_types must include authorization_code.');
        }

        $authMethod = (string) ($meta['token_endpoint_auth_method'] ?? 'none');

        if (!\in_array($authMethod, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            throw new OAuthException('invalid_client_metadata', 'Unsupported token_endpoint_auth_method.');
        }

        $clientId = 'mcp_' . bin2hex(random_bytes(16));
        $secret   = $authMethod === 'none' ? '' : bin2hex(random_bytes(32));
        $name     = mb_substr(trim((string) ($meta['client_name'] ?? '')), 0, 200) ?: 'MCP client';
        $now      = time();

        $stored = [
            'client_name'                => $name,
            'redirect_uris'              => array_values($redirects),
            'grant_types'                => $grantTypes,
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => $authMethod,
        ];

        foreach (['client_uri', 'logo_uri', 'software_id', 'software_version'] as $key) {
            if (isset($meta[$key]) && \is_string($meta[$key])) {
                $stored[$key] = mb_substr($meta[$key], 0, 500);
            }
        }

        $row = (object) [
            'client_id'            => $clientId,
            'client_secret_hash'   => $secret === '' ? '' : hash('sha256', $secret),
            'client_name'          => $name,
            'redirect_uris'        => json_encode(array_values($redirects)),
            'metadata'             => json_encode($stored),
            'is_metadata_document' => 0,
            'created'              => Factory::getDate($now)->toSql(),
        ];

        $this->db->insertObject('#__mcp_oauth_clients', $row);

        Logger::security($this->db, 'oauth:register', \sprintf('Registered client "%s" (%s) for %s', $name, $clientId, implode(', ', $redirects)), 0, '', 'success');

        $this->cleanup();

        return ['client_id' => $clientId, 'client_id_issued_at' => $now]
            + ($secret !== '' ? ['client_secret' => $secret, 'client_secret_expires_at' => 0] : [])
            + $stored;
    }

    /**
     * Load a client, fetching its metadata document when the client ID is an HTTPS URL.
     */
    public function getClient(string $clientId): ?object
    {
        if ($clientId === '') {
            return null;
        }

        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__mcp_oauth_clients'))
            ->where($this->db->quoteName('client_id') . ' = :id')
            ->bind(':id', $clientId);

        $client = $this->db->setQuery($query)->loadObject();

        $isDocument = str_starts_with($clientId, 'https://');
        $stale      = $client && $client->is_metadata_document && strtotime($client->created . ' UTC') < time() - 86400;

        if ($isDocument && (!$client || $stale)) {
            $client = $this->fetchMetadataDocument($clientId, $client);
        }

        if ($client) {
            $client->redirect_uris = json_decode($client->redirect_uris, true) ?: [];
        }

        return $client ?: null;
    }

    /**
     * Client ID metadata document: the client ID is a URL serving the client's metadata.
     */
    private function fetchMetadataDocument(string $url, ?object $existing): ?object
    {
        // A metadata document creates a client row, so the administrator's switch applies here too
        if (!$this->params->get('oauth_registration', 1)) {
            return $existing;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        // Only fetch from hosts the administrator trusts, and never from an address inside the
        // network this site is running in
        if (!parse_url($url, PHP_URL_PATH) || !$this->hostAllowed($host) || !self::isPublicHost($host)) {
            return null;
        }

        try {
            // Redirects are not followed: the target would not be checked against the list above,
            // which would turn an open redirect on an allowed host into a request to any address
            $http     = HttpFactory::getHttp(new Registry(['follow_location' => false]));
            $response = $http->get($url, ['Accept' => 'application/json'], 5);
            $length   = (int) ($response->getHeaderLine('Content-Length') ?: 0);
            $body     = $length > self::METADATA_MAX_BYTES ? '' : (string) $response->getBody();
            $meta     = $response->getStatusCode() === 200 && $body !== '' && \strlen($body) <= self::METADATA_MAX_BYTES
                ? json_decode($body, true)
                : null;
        } catch (\Throwable) {
            $meta = null;
        }

        if (!\is_array($meta) || ($meta['client_id'] ?? null) !== $url) {
            return $existing;
        }

        $redirects = array_values(array_filter((array) ($meta['redirect_uris'] ?? []), fn ($u) => \is_string($u) && $this->isAllowedRedirect($u)));

        if (!$redirects) {
            return null;
        }

        $row = (object) [
            'client_id'            => $url,
            'client_secret_hash'   => '',
            'client_name'          => mb_substr(trim((string) ($meta['client_name'] ?? $host)), 0, 200),
            'redirect_uris'        => json_encode($redirects),
            'metadata'             => json_encode($meta),
            'is_metadata_document' => 1,
            'created'              => Factory::getDate()->toSql(),
        ];

        if ($existing) {
            $row->id = $existing->id;
            $this->db->updateObject('#__mcp_oauth_clients', $row, 'id');
        } else {
            $this->db->insertObject('#__mcp_oauth_clients', $row, 'id');
        }

        return $row;
    }

    /**
     * Whether every address a host resolves to is a public one. A metadata document URL is
     * supplied by the client, so without this the fetch above could be aimed at an address inside
     * the hosting network (a metadata service, an admin panel, a database).
     */
    private static function isPublicHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        $literal = trim($host, '[]');
        $flags   = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return filter_var($literal, FILTER_VALIDATE_IP, $flags) !== false;
        }

        $addresses = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        if (!$addresses) {
            return false;
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, $flags) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a redirect URI may be used: loopback addresses (any port, for desktop and CLI
     * clients), and HTTPS or custom scheme URIs on the configured list.
     *
     * Browsers and parse_url() do not agree on what the host of a URL is. parse_url() follows
     * RFC 3986, where everything before an "@" is userinfo, while browsers follow the WHATWG URL
     * Standard, where a backslash ends the authority exactly like a slash. In
     * "https://evil.com\@claude.ai/cb" parse_url() reports claude.ai and a browser reports
     * evil.com, so a URI that passes the list here would send the authorization code somewhere
     * else entirely. Rather than try to match the browser, anything that the two parsers could
     * read differently is refused before parsing.
     */
    public function isAllowedRedirect(string $uri): bool
    {
        // Backslash and "#" change where the authority ends; control characters and spaces are
        // stripped by browsers while parse_url() keeps them
        if ($uri === '' || \strlen($uri) > 2000 || preg_match('/[\\\\#\x00-\x20\x7f]/', $uri)) {
            return false;
        }

        $parts = parse_url($uri);

        if (!$parts || empty($parts['scheme']) || isset($parts['fragment'])) {
            return false;
        }

        // Userinfo has no legitimate place in a redirect URI and is how the host gets disguised
        if (isset($parts['user']) || isset($parts['pass']) || str_contains($parts['host'] ?? '', '@')) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host   = strtolower($parts['host'] ?? '');

        // Anything that is not a plain host name or bracketed IPv6 literal is not a host we can
        // compare against the list with any confidence
        if ($host !== '' && !preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9]([a-z0-9.-]*[a-z0-9])?)$/i', $host)) {
            return false;
        }

        if ($scheme === 'http' || $scheme === 'https') {
            // Programs on the user's own computer (RFC 8252), unless the administrator allows none (0.11.0)
            if (\in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
                return (bool) $this->params->get('oauth_loopback', 1);
            }

            return $scheme === 'https' && $this->hostAllowed($host);
        }

        // Custom schemes such as cursor:// must be listed explicitly
        return \in_array($scheme . '://', $this->allowedEntries(), true);
    }

    private function hostAllowed(string $host): bool
    {
        if ($this->params->get('oauth_any_https', 0)) {
            return $host !== '';
        }

        $host = strtolower($host);

        foreach ($this->allowedEntries() as $entry) {
            if (str_contains($entry, '://')) {
                continue;
            }

            if ($host === $entry || (str_starts_with($entry, '*.') && str_ends_with($host, substr($entry, 1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function allowedEntries(): array
    {
        $list = (string) $this->params->get('oauth_redirect_hosts', self::DEFAULT_REDIRECT_HOSTS);

        return array_values(array_filter(array_map(fn ($l) => strtolower(trim($l)), preg_split('/[\s,]+/', $list))));
    }

    /**
     * RFC 8252: for loopback redirects the port may differ from the registered one.
     */
    private function redirectMatches(string $registered, string $requested): bool
    {
        if ($registered === $requested) {
            return true;
        }

        $a = parse_url($registered);
        $b = parse_url($requested);

        if (!$a || !$b || !\in_array(strtolower($a['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true)) {
            return false;
        }

        unset($a['port'], $b['port']);

        return $a == $b;
    }

    // ------------------------------------------------------------------ Authorization

    /**
     * Validate an authorization request.
     *
     * Errors that must not be redirected (unknown client, bad redirect URI) throw with status 400;
     * others are returned in 'error' so the caller can redirect them to the client.
     *
     * @return array{client: object, redirect_uri: string, state: ?string, scopes: string[], code_challenge: string, resource: string, error: ?string, error_description: ?string}
     */
    public function validateAuthorization(array $q): array
    {
        if (!$this->isEnabled()) {
            throw new OAuthException('access_denied', 'OAuth sign-in is disabled on this site.');
        }

        $client = $this->getClient((string) ($q['client_id'] ?? ''));

        if (!$client) {
            throw new OAuthException('invalid_client', 'Unknown client. Remove the connector in your AI assistant and add it again.');
        }

        $redirect = (string) ($q['redirect_uri'] ?? '');

        if ($redirect === '' && \count($client->redirect_uris) === 1) {
            $redirect = $client->redirect_uris[0];
        }

        $registered = array_filter($client->redirect_uris, fn ($r) => $this->redirectMatches($r, $redirect));

        if (!$registered || !$this->isAllowedRedirect($redirect)) {
            throw new OAuthException('invalid_request', 'The redirect URI does not match the registered client.');
        }

        $request = [
            'client'            => $client,
            'redirect_uri'      => $redirect,
            'state'             => isset($q['state']) ? (string) $q['state'] : null,
            'scopes'            => $this->requestedScopes((string) ($q['scope'] ?? '')),
            'code_challenge'    => (string) ($q['code_challenge'] ?? ''),
            'resource'          => (string) ($q['resource'] ?? ''),
            'error'             => null,
            'error_description' => null,
        ];

        if (($q['response_type'] ?? '') !== 'code') {
            $request['error']             = 'unsupported_response_type';
            $request['error_description'] = 'Only response_type=code is supported.';
        } elseif ($request['code_challenge'] === '' || ($q['code_challenge_method'] ?? '') !== 'S256') {
            $request['error']             = 'invalid_request';
            $request['error_description'] = 'PKCE with code_challenge_method=S256 is required.';
        } elseif ($request['resource'] !== '' && !$this->resourceMatches($request['resource'])) {
            $request['error']             = 'invalid_target';
            $request['error_description'] = 'This server only issues tokens for ' . $this->resource();
        }

        return $request;
    }

    /**
     * Requested scopes limited to what is available; everything available when none are requested.
     *
     * @return string[]
     */
    private function requestedScopes(string $scope): array
    {
        $available = $this->availableScopes();
        $requested = array_values(array_intersect(preg_split('/\s+/', trim($scope)) ?: [], $available));

        if ($requested) {
            return $requested;
        }

        // A client that asks for nothing in particular gets read access, not everything the site
        // happens to allow
        return \in_array('mcp:read', $available, true) ? ['mcp:read'] : $available;
    }

    private function resourceMatches(string $resource): bool
    {
        // The scheme is part of the audience: a token for http:// must not pass for https://
        $normalize = function (string $u): string {
            $parts = parse_url($u);

            if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
                return '';
            }

            return strtolower($parts['scheme'] . '://' . $parts['host'])
                . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
                . rtrim($parts['path'] ?? '', '/');
        };

        $normalized = $normalize($resource);

        return $normalized !== '' && $normalized === $normalize($this->resource());
    }

    /**
     * Issue an authorization code after the user approved the request.
     *
     * @param  string[]  $scopes  Granted scopes
     */
    public function issueCode(array $request, User $user, array $scopes): string
    {
        $code = bin2hex(random_bytes(32));
        $now  = time();

        $record = (object) [
            'code_hash'      => hash('sha256', $code),
            'client_id'      => $request['client']->client_id,
            'user_id'        => (int) $user->id,
            'redirect_uri'   => $request['redirect_uri'],
            'code_challenge' => $request['code_challenge'],
            'scopes'         => implode(' ', array_values(array_intersect($scopes, $this->availableScopes()))),
            'resource'       => $this->resource(),
            'created'        => Factory::getDate($now)->toSql(),
            'expires'        => Factory::getDate($now + self::CODE_TTL)->toSql(),
        ];

        $this->db->insertObject('#__mcp_oauth_codes', $record);

        return $code;
    }

    /**
     * The URL to send the browser back to, with the given parameters.
     */
    public function redirectUrl(string $redirectUri, array $params): string
    {
        $params['iss'] = $this->issuer();

        return $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query(array_filter($params, fn ($v) => $v !== null));
    }

    // ------------------------------------------------------------------ Token endpoint

    /**
     * @param  array        $post   Request body parameters
     * @param  string|null  $basic  "client_id:secret" from HTTP Basic authentication
     */
    public function token(array $post, ?string $basic = null): array
    {
        if (!$this->isEnabled()) {
            throw new OAuthException('access_denied', 'OAuth is disabled on this site.');
        }

        $client = $this->authenticateClient($post, $basic);

        return match ($post['grant_type'] ?? '') {
            'authorization_code' => $this->exchangeCode($client, $post),
            'refresh_token'      => $this->refresh($client, $post),
            default              => throw new OAuthException('unsupported_grant_type'),
        };
    }

    private function authenticateClient(array $post, ?string $basic): object
    {
        $clientId = (string) ($post['client_id'] ?? '');
        $secret   = isset($post['client_secret']) ? (string) $post['client_secret'] : null;

        if ($basic !== null && str_contains($basic, ':')) {
            [$basicId, $basicSecret] = array_map('urldecode', explode(':', $basic, 2));
            $clientId = $clientId ?: $basicId;
            $secret   = $basicSecret;
        }

        $client = $this->getClient($clientId);

        if (!$client) {
            throw new OAuthException('invalid_client', 'Unknown client.', 401);
        }

        if ($client->client_secret_hash !== '' && ($secret === null || !hash_equals($client->client_secret_hash, hash('sha256', $secret)))) {
            throw new OAuthException('invalid_client', 'Client authentication failed.', 401);
        }

        return $client;
    }

    private function exchangeCode(object $client, array $post): array
    {
        $hash  = hash('sha256', (string) ($post['code'] ?? ''));
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__mcp_oauth_codes'))
            ->where($this->db->quoteName('code_hash') . ' = :hash')
            ->bind(':hash', $hash);

        $code = $this->db->setQuery($query)->loadObject();

        if (!$code) {
            throw new OAuthException('invalid_grant', 'The authorization code is invalid or was already used.');
        }

        // Codes are single use, and the delete is what enforces it: two requests arriving together
        // both pass the select above, so whoever does not actually remove the row loses
        $delete = $this->db->getQuery(true)
            ->delete($this->db->quoteName('#__mcp_oauth_codes'))
            ->where($this->db->quoteName('id') . ' = ' . (int) $code->id);
        $this->db->setQuery($delete)->execute();

        if (!$this->db->getAffectedRows()) {
            throw new OAuthException('invalid_grant', 'The authorization code is invalid or was already used.');
        }

        if (strtotime($code->expires . ' UTC') < time()) {
            throw new OAuthException('invalid_grant', 'The authorization code has expired.');
        }

        if ($code->client_id !== $client->client_id) {
            throw new OAuthException('invalid_grant', 'The authorization code was issued to another client.');
        }

        // RFC 6749 section 4.1.3: the authorization request carried a redirect URI, so the token
        // request has to repeat it
        if ((string) ($post['redirect_uri'] ?? '') !== (string) $code->redirect_uri) {
            throw new OAuthException('invalid_grant', 'redirect_uri does not match the authorization request.');
        }

        $verifier = (string) ($post['code_verifier'] ?? '');

        // RFC 7636 section 4.1
        if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)) {
            throw new OAuthException('invalid_grant', 'code_verifier must be 43 to 128 unreserved characters.');
        }

        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        if (!hash_equals($code->code_challenge, $expected)) {
            throw new OAuthException('invalid_grant', 'PKCE verification failed.');
        }

        if (!empty($post['resource']) && !$this->resourceMatches((string) $post['resource'])) {
            throw new OAuthException('invalid_target');
        }

        $this->assertUserActive((int) $code->user_id);

        return $this->issueTokens($client->client_id, (int) $code->user_id, $code->scopes, bin2hex(random_bytes(16)));
    }

    private function refresh(object $client, array $post): array
    {
        $token = $this->findToken((string) ($post['refresh_token'] ?? ''), 'refresh');

        if (!$token || $token->client_id !== $client->client_id) {
            throw new OAuthException('invalid_grant', 'The refresh token is invalid.');
        }

        if ($token->revoked) {
            // A rotated refresh token was used again: it may be stolen, so end the whole grant
            $this->revokeFamily($token->family);

            Logger::security(
                $this->db,
                'oauth:token_replayed',
                \sprintf('A rotated refresh token of client %s was presented again; the whole grant was revoked.', $client->client_id),
                (int) $token->user_id
            );

            throw new OAuthException('invalid_grant', 'The refresh token was already used; please sign in again.');
        }

        if (strtotime($token->expires . ' UTC') < time()) {
            throw new OAuthException('invalid_grant', 'The refresh token has expired; please sign in again.');
        }

        $this->assertUserActive((int) $token->user_id);

        $scopes = $token->scopes;

        if (!empty($post['scope'])) {
            $narrowed = array_intersect(preg_split('/\s+/', trim((string) $post['scope'])) ?: [], explode(' ', $token->scopes));

            if (!$narrowed) {
                throw new OAuthException('invalid_scope', 'The requested scope is not part of this grant.');
            }

            $scopes = implode(' ', $narrowed);
        }

        // Rotation: the old refresh and access tokens stop working
        $this->revokeFamily($token->family);

        return $this->issueTokens($client->client_id, (int) $token->user_id, $scopes, $token->family);
    }

    private function assertUserActive(int $userId): void
    {
        $user = new User($userId);

        // The same account checks the API token path makes, so a blocked, unactivated or
        // must-change-password account cannot keep using an already issued token
        if (!$user->id
            || $user->block
            || !empty(trim((string) $user->activation))
            || $user->requireReset
            || !$user->authorise('mcp.use', 'com_mcp')) {
            throw new OAuthException('invalid_grant', 'This user can no longer use the MCP server.');
        }
    }

    private function issueTokens(string $clientId, int $userId, string $scopes, string $family): array
    {
        $now       = time();
        $accessTtl = max(5, (int) $this->params->get('oauth_access_minutes', 60)) * 60;
        $refreshTtl = max(1, (int) $this->params->get('oauth_refresh_days', 30)) * 86400;
        $access    = self::ACCESS_PREFIX . $this->randomToken();
        $refresh   = self::REFRESH_PREFIX . $this->randomToken();

        foreach ([['access', $access, $accessTtl], ['refresh', $refresh, $refreshTtl]] as [$type, $value, $ttl]) {
            $record = (object) [
                'token_hash' => hash('sha256', $value),
                'type'       => $type,
                'family'     => $family,
                'client_id'  => $clientId,
                'user_id'    => $userId,
                'scopes'     => $scopes,
                'resource'   => $this->resource(),
                'created'    => Factory::getDate($now)->toSql(),
                'expires'    => Factory::getDate($now + $ttl)->toSql(),
                'revoked'    => 0,
            ];

            $this->db->insertObject('#__mcp_oauth_tokens', $record);
        }

        $this->touchClient($clientId);

        return [
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => $accessTtl,
            'refresh_token' => $refresh,
            'scope'         => $scopes,
        ];
    }

    // ------------------------------------------------------------------ Resource server side

    /**
     * Validate an access token presented to the MCP endpoint.
     *
     * @return  array{user_id: int, scopes: string[], client_id: string}|null
     */
    public function validateAccessToken(string $value): ?array
    {
        if (!$this->isEnabled() || !str_starts_with($value, self::ACCESS_PREFIX)) {
            return null;
        }

        $token = $this->findToken($value, 'access');

        if (!$token || $token->revoked || strtotime($token->expires . ' UTC') < time() || !$this->resourceMatches($token->resource)) {
            return null;
        }

        // Record usage at most every five minutes
        if (!$token->last_used || strtotime($token->last_used . ' UTC') < time() - 300) {
            $now   = Factory::getDate()->toSql();
            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__mcp_oauth_tokens'))
                ->set($this->db->quoteName('last_used') . ' = :now')
                ->where($this->db->quoteName('family') . ' = :family')
                ->bind(':now', $now)
                ->bind(':family', $token->family);
            $this->db->setQuery($query)->execute();
        }

        return [
            'user_id'   => (int) $token->user_id,
            'scopes'    => array_values(array_filter(explode(' ', $token->scopes))),
            'client_id' => $token->client_id,
        ];
    }

    public static function looksLikeAccessToken(string $value): bool
    {
        return str_starts_with($value, self::ACCESS_PREFIX);
    }

    /**
     * Tool scopes for a list of OAuth scopes.
     *
     * @return string[]
     */
    public static function toolScopes(array $scopes): array
    {
        return array_values(array_map(fn ($s) => self::SCOPES[$s], array_filter($scopes, fn ($s) => isset(self::SCOPES[$s]))));
    }

    // ------------------------------------------------------------------ Revocation

    /**
     * RFC 7009 token revocation. Unknown tokens are ignored, as the RFC requires.
     */
    public function revoke(string $value, array $post, ?string $basic = null): void
    {
        $client = $this->authenticateClient($post, $basic);

        foreach (['access', 'refresh'] as $type) {
            $token = $this->findToken($value, $type);

            if ($token && $token->client_id === $client->client_id) {
                $this->revokeFamily($token->family);
            }
        }
    }

    public function revokeFamily(string $family): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__mcp_oauth_tokens'))
            ->set($this->db->quoteName('revoked') . ' = 1')
            ->where($this->db->quoteName('family') . ' = :family')
            ->bind(':family', $family);

        $this->db->setQuery($query)->execute();
    }

    public function revokeUser(int $userId): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__mcp_oauth_tokens'))
            ->set($this->db->quoteName('revoked') . ' = 1')
            ->where($this->db->quoteName('user_id') . ' = :user')
            ->bind(':user', $userId, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    // ------------------------------------------------------------------ Helpers

    private function findToken(string $value, string $type): ?object
    {
        if ($value === '') {
            return null;
        }

        $hash  = hash('sha256', $value);
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__mcp_oauth_tokens'))
            ->where($this->db->quoteName('token_hash') . ' = :hash')
            ->where($this->db->quoteName('type') . ' = :type')
            ->bind(':hash', $hash)
            ->bind(':type', $type);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function touchClient(string $clientId): void
    {
        $now   = Factory::getDate()->toSql();
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__mcp_oauth_clients'))
            ->set($this->db->quoteName('last_used') . ' = :now')
            ->where($this->db->quoteName('client_id') . ' = :id')
            ->bind(':now', $now)
            ->bind(':id', $clientId);

        $this->db->setQuery($query)->execute();
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Remove expired codes, revoked and expired tokens (a day on: until then a revoked refresh
     * token presented again still reveals a stolen family), clients registered and never used,
     * and clients unused for longer than a refresh token lasts that hold no live token (0.11.0).
     * Now and then on its own, every day from the scheduled task.
     *
     * @return  array{codes: int, tokens: int, clients: int}
     */
    public function cleanup(bool $now = false): array
    {
        $counts = ['codes' => 0, 'tokens' => 0, 'clients' => 0];

        if (!$now && random_int(1, 10) !== 1) {
            return $counts;
        }

        $db      = $this->db;
        $current = Factory::getDate()->toSql();
        $dayAgo  = Factory::getDate('-1 day')->toSql();
        $stale   = Factory::getDate('-' . ((int) $this->params->get('oauth_refresh_days', 30) + 1) . ' days')->toSql();

        $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__mcp_oauth_codes'))->where('expires < :now')->bind(':now', $current))->execute();
        $counts['codes'] = $db->getAffectedRows();

        $db->setQuery(
            $db->getQuery(true)->delete($db->quoteName('#__mcp_oauth_tokens'))
                ->where('(expires < :expired OR (revoked = 1 AND COALESCE(last_used, created) < :revoked))')
                ->bind(':expired', $dayAgo)->bind(':revoked', $dayAgo)
        )->execute();
        $counts['tokens'] = $db->getAffectedRows();

        $live = $db->getQuery(true)->select('t.client_id')->from($db->quoteName('#__mcp_oauth_tokens', 't'))
            ->where('t.revoked = 0')->where('t.expires > ' . $db->quote($current));

        $db->setQuery(
            $db->getQuery(true)->delete($db->quoteName('#__mcp_oauth_clients'))
                ->where('((last_used IS NULL AND created < :unused) OR (last_used IS NOT NULL AND last_used < :stale))')
                ->where('client_id NOT IN (' . $live . ')')
                ->bind(':unused', $dayAgo)->bind(':stale', $stale)
        )->execute();
        $counts['clients'] = $db->getAffectedRows();

        return $counts;
    }

    /**
     * Every registered client that holds no live token, deleted now (the administrator's button).
     */
    public function deleteUnusedClients(): int
    {
        $db   = $this->db;
        $live = $db->getQuery(true)->select('t.client_id')->from($db->quoteName('#__mcp_oauth_tokens', 't'))
            ->where('t.revoked = 0')->where('t.expires > ' . $db->quote(Factory::getDate()->toSql()));

        $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__mcp_oauth_clients'))->where('client_id NOT IN (' . $live . ')'))->execute();

        return $db->getAffectedRows();
    }
}
