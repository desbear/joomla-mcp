<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Ask;

use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/**
 * Reads pages of a website the administrator has listed, as a polite and careful visitor: it
 * says who it is, keeps to robots.txt, waits between pages, stays on the site's own host, and
 * takes only so much.
 *
 * @since  0.3.0
 */
final class WebClient
{
    /** Largest document accepted: a page, a feed or a sitemap. */
    private const MAX_BYTES = 5242880;

    /** Microseconds between two requests to the same site. */
    private const PAUSE = 600000;

    private const REDIRECTS = 3;

    /** @var array<int, array{0: bool, 1: string}>  Disallow (false) and Allow (true) rules from robots.txt */
    private ?array $rules = null;

    /** @var string[]  Sitemaps that robots.txt names */
    private array $sitemaps = [];

    private float $last = 0.0;

    /**
     * @param  string  $base  Address of the source; every address read must be on its host
     */
    /** Whether the administrator listed a site on the server's own network on purpose. */
    private bool $internal;

    public function __construct(private string $base)
    {
        $this->internal = self::isInternalHost((string) parse_url($base, PHP_URL_HOST));
    }

    /**
     * Where a request may go, checked before every request: the addresses the name resolves to
     * now, all of them public (unless the administrator listed an internal site), on the usual
     * port. The answer is pinned for curl, so the name cannot resolve differently a moment later
     * (DNS rebinding).
     *
     * @return  string|null  A CURLOPT_RESOLVE entry ("host:port:address"), or null when the request may not be made
     */
    public static function target(string $url, bool $internal = false): ?string
    {
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (!\in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        // Only the ports of the web, unless an internal site was listed with its own
        if (!$internal && !\in_array($port, [80, 443], true)) {
            return null;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);

        if (!$ips) {
            return null;
        }

        foreach ($ips as $ip) {
            if (!$internal && !self::isPublic($ip)) {
                return null;
            }
        }

        $ip = $ips[0];

        return $host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
    }

    /**
     * @return  string[]  IPv4 and IPv6 addresses
     */
    private static function resolve(string $host): array
    {
        $ips     = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        foreach ($records as $record) {
            $ips[] = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
        }

        $ips = array_values(array_filter($ips));

        return $ips ?: (gethostbynamel($host) ?: []);
    }

    /**
     * Whether an address is on the public internet: not private, loopback, link-local, reserved,
     * shared (carrier-grade NAT), unique-local, or IPv4 dressed as IPv6.
     */
    private static function isPublic(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (\strlen($packed) === 4) {
            $first = \ord($packed[0]);
            $second = \ord($packed[1]);

            // 0.0.0.0/8, 100.64.0.0/10, 198.18.0.0/15
            return $first !== 0 && !($first === 100 && $second >= 64 && $second <= 127) && !($first === 198 && ($second & 0xFE) === 18);
        }

        $hex = bin2hex($packed);

        return !str_starts_with($hex, '00000000000000000000ffff')  // ::ffff:a.b.c.d
            && !str_starts_with($hex, '0000000000000000')            // ::, ::1 and IPv4-compatible
            && !preg_match('/^f[cd]/', $hex)                         // fc00::/7
            && !preg_match('/^fe[89ab]/', $hex)                      // fe80::/10
            && !str_starts_with($hex, '0064ff9b');                   // 64:ff9b::/96 (NAT64 onto IPv4)
    }

    /**
     * Whether a host the administrator gave is on the server's own network: then they meant it.
     */
    private static function isInternalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false && !self::isPublic($host);
    }

    /**
     * Where an address the administrator gave really leads: its redirects are followed (at most
     * three) even to another host, but never to the server's own network.
     */
    public static function landing(string $url): string
    {
        $current  = $url;
        $internal = self::isInternalHost((string) parse_url($url, PHP_URL_HOST));

        for ($hop = 0; $hop <= self::REDIRECTS; $hop++) {
            $pin = self::target($current, $internal);

            if ($pin === null) {
                // Not reachable, or somewhere it may not lead: the address as given stays, and is refused there
                return $url;
            }

            // The last hop is only checked, not followed further
            if ($hop === self::REDIRECTS) {
                return $current;
            }

            $curl = curl_init($current);

            curl_setopt_array($curl, [
                CURLOPT_RESOLVE        => [$pin],
                CURLOPT_NOBODY         => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; AskTheSite/0.4; +' . rtrim(Uri::root(), '/') . ')',
            ]);
            curl_exec($curl);

            $status   = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $location = (string) curl_getinfo($curl, CURLINFO_REDIRECT_URL);

            curl_close($curl);

            if (!\in_array($status, [301, 302, 303, 307, 308], true) || !preg_match('#^https?://#i', $location)) {
                return $current;
            }

            $current = $location;
        }

        return $current;
    }

    /**
     * Whether an address belongs to the site: the same host (with or without "www."), over HTTPS
     * unless the source itself was given as HTTP.
     */
    public function owns(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $mine   = strtolower((string) parse_url($this->base, PHP_URL_SCHEME));
        $bare   = static fn ($host) => preg_replace('/^www\./', '', strtolower((string) $host));

        return \in_array($scheme, $mine === 'http' ? ['http', 'https'] : ['https'], true)
            && $bare(parse_url($url, PHP_URL_HOST)) === $bare(parse_url($this->base, PHP_URL_HOST))
            && $bare(parse_url($url, PHP_URL_HOST)) !== '';
    }

    /**
     * Read a document of the site.
     *
     * @return  array{status: int, type: string, charset: string, body: string, url: string}  The status 0 when it could not be read at all
     */
    public function get(string $url): array
    {
        for ($hop = 0; $hop <= self::REDIRECTS; $hop++) {
            if (!$this->owns($url) || !$this->allowed($url)) {
                return ['status' => 0, 'type' => '', 'charset' => '', 'body' => '', 'url' => $url];
            }

            $result = $this->request($url);

            if (!\in_array($result['status'], [301, 302, 303, 307, 308], true) || $result['location'] === '') {
                return $result + ['url' => $url];
            }

            // A redirect is followed only while it stays on the site (checked at the top of the loop)
            $url = $this->absolute($result['location'], $url);
        }

        return ['status' => 0, 'type' => '', 'charset' => '', 'body' => '', 'url' => $url];
    }

    /**
     * An address as it stands in a page, made absolute against the page's address.
     */
    public function absolute(string $href, string $page): string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            return $href;
        }

        $parts = parse_url($page);
        $root  = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($href, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $href;
        }

        if (str_starts_with($href, '/')) {
            return $root . $href;
        }

        $directory = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');

        return $root . $directory . $href;
    }

    /**
     * Whether robots.txt lets this visitor read an address. The rules for every visitor ("*") and
     * for this one apply; the longest matching rule decides, as search engines do.
     */
    public function allowed(string $url): bool
    {
        if ($this->rules === null) {
            $this->rules = [];
            $robots      = $this->request($this->absolute('/robots.txt', $this->base));

            // As search engines do, only the first 500 KB of robots.txt counts
            if ($robots['status'] === 200) {
                $robots['body'] = substr($robots['body'], 0, 512000);
                $this->rules    = self::robots($robots['body']);

                preg_match_all('/^\s*sitemap\s*:\s*(\S+)/im', $robots['body'], $found);
                $this->sitemaps = array_values(array_unique(array_filter($found[1], fn ($url) => $this->owns($url))));
            }
        }

        $path  = (string) parse_url($url, PHP_URL_PATH) ?: '/';
        $path .= ($query = parse_url($url, PHP_URL_QUERY)) ? '?' . $query : '';
        $best  = [-1, true];

        foreach ($this->rules as [$allow, $rule]) {
            $pattern = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($rule, '#')) . '#';

            if (preg_match($pattern, $path) && \strlen($rule) > $best[0]) {
                $best = [\strlen($rule), $allow];
            }
        }

        return $best[1];
    }

    /**
     * The sitemaps robots.txt names that are on the site itself.
     *
     * @return  string[]
     */
    public function sitemaps(): array
    {
        $this->allowed($this->base);

        return $this->sitemaps;
    }

    /**
     * @return  array<int, array{0: bool, 1: string}>
     */
    private static function robots(string $text): array
    {
        $rules  = [];
        $groups = [];
        $agents = [];
        $inRules = false;

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*/', '', $line));

            if (!preg_match('/^([a-z-]+)\s*:\s*(.*)$/i', $line, $match)) {
                continue;
            }

            $field = strtolower($match[1]);
            $value = trim($match[2]);

            if ($field === 'user-agent') {
                // A user-agent line after rules starts a new group
                if ($inRules) {
                    $agents  = [];
                    $inRules = false;
                }

                $agents[] = strtolower($value);
            } elseif (\in_array($field, ['allow', 'disallow'], true)) {
                $inRules = true;

                foreach ($agents as $agent) {
                    $groups[$agent][] = [$field === 'allow', $value];
                }
            }
        }

        // This visitor's own group replaces the general one
        foreach (\array_slice($groups['askthesite'] ?? $groups['*'] ?? [], 0, 1000) as [$allow, $value]) {
            if ($value !== '') {
                // Runs of wildcards match what one does, and cost the matcher dearly
                $rules[] = [$allow, (string) preg_replace('/\*+/', '*', mb_substr($value, 0, 500))];
            }
        }

        return $rules;
    }

    /**
     * @return  array{status: int, type: string, charset: string, body: string, location: string}
     */
    private function request(string $url): array
    {
        // Never two requests to the site in quick succession
        $wait = (int) (self::PAUSE - (microtime(true) - $this->last) * 1000000);

        if ($wait > 0) {
            usleep($wait);
        }

        $body    = '';
        $headers = [];
        $pin     = self::target($url, $this->internal);

        // An address that leads into the server's own network (or nowhere) is not requested at all
        if ($pin === null) {
            return ['status' => 0, 'type' => '', 'charset' => '', 'body' => '', 'location' => ''];
        }

        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RESOLVE        => [$pin],
            CURLOPT_HTTPGET        => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            // The usual form for crawlers: says who reads, and where to find out more
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; AskTheSite/0.4; +' . rtrim(Uri::root(), '/') . ')',
            CURLOPT_HTTPHEADER     => ['Accept: text/html, application/xhtml+xml, application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.5'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                if (preg_match('/^([a-z-]+):\s*(.*)$/i', trim($line), $match)) {
                    $headers[strtolower($match[1])] = $match[2];
                }

                return \strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => static function ($curl, string $data) use (&$body): int {
                $body .= $data;

                return \strlen($body) > self::MAX_BYTES ? 0 : \strlen($data);
            },
        ]);

        curl_exec($curl);

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        curl_close($curl);
        $this->last = microtime(true);

        return [
            'status'   => \strlen($body) > self::MAX_BYTES ? 0 : $status,
            'type'     => strtolower(trim(explode(';', $headers['content-type'] ?? '')[0])),
            'charset'  => preg_match('/charset\s*=\s*["\']?([a-z0-9_-]+)/i', $headers['content-type'] ?? '', $match) ? $match[1] : '',
            'body'     => $body,
            'location' => $headers['location'] ?? '',
        ];
    }
}
