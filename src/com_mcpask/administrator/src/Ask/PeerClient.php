<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Ask;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Reads the index another site offers (see the site's FeedController there).
 *
 * @since  0.2.0
 */
final class PeerClient
{
    /** Largest answer accepted from another site. */
    private const MAX_BYTES = 16777216;

    /**
     * @param  array{id: string, name: string, url: string, key: string}  $peer
     */
    public function __construct(private array $peer)
    {
    }

    /**
     * What the other site has: its embedding settings and the hash of every page.
     *
     * @return  array{model: string, dimensions: int, pages: array<int, string>}
     */
    public function pages(): array
    {
        $data  = $this->call('feed.pages', []);
        $pages = [];

        foreach ((array) ($data['pages'] ?? []) as $id => $hash) {
            if ((int) $id > 0 && \is_string($hash) && $hash !== '') {
                $pages[(int) $id] = substr($hash, 0, 40);
            }
        }

        return ['model' => (string) ($data['model'] ?? ''), 'dimensions' => (int) ($data['dimensions'] ?? 0), 'pages' => $pages];
    }

    /**
     * Pages with their passages and embeddings.
     *
     * @param   int[]  $ids
     *
     * @return  array<int, array>
     */
    public function documents(array $ids): array
    {
        return array_values(array_filter((array) ($this->call('feed.documents', ['ids' => array_values($ids)])['documents'] ?? []), 'is_array'));
    }

    private function call(string $task, array $body): array
    {
        $url    = $this->peer['url'] . '/index.php?option=com_mcpask&format=raw&task=' . $task;
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host   = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        // The key travels in a header: never over an unencrypted connection, except to this machine itself
        if ($scheme !== 'https' && !($scheme === 'http' && \in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
            throw new ServiceException('The address of the other site must start with https://.');
        }

        // The address the name leads to now, checked and pinned, so it cannot be turned towards the
        // server's own network (another site on this network was listed on purpose, as an address)
        $pin = WebClient::target($url, \in_array($host, ['localhost', '127.0.0.1', '::1'], true) || (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)));

        if ($pin === null) {
            throw new ServiceException('The address of the other site cannot be reached, or leads into this server\'s own network.', true);
        }

        $received = '';
        $curl     = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RESOLVE        => [$pin],
            // A POST, because Joomla's language filter answers a GET of this address with a redirect
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode((object) $body),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'X-Mcpask-Key: ' . $this->peer['key']],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_WRITEFUNCTION  => static function ($curl, string $data) use (&$received): int {
                $received .= $data;

                return \strlen($received) > self::MAX_BYTES ? 0 : \strlen($data);
            },
        ]);

        curl_exec($curl);

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($curl);

        curl_close($curl);

        $data = json_decode($received, true);

        if ($status !== 200 || !\is_array($data)) {
            // The two answers an administrator can do something about say what to do, in their language
            if (\in_array($status, [403, 404], true)) {
                Factory::getApplication()->getLanguage()->load('com_mcpask', JPATH_ADMINISTRATOR);

                throw new ServiceException(Text::sprintf('COM_MCPASK_PEER_ERROR_' . $status, $this->peer['name'], Settings::MIN_EXPORT_KEY));
            }

            $reason = \is_array($data) && \is_string($data['error'] ?? null) ? $data['error'] : ($error ?: 'no usable answer');

            throw new ServiceException(\sprintf('%s answered with HTTP %d: %s', $this->peer['name'], $status, mb_substr($reason, 0, 150)));
        }

        return $data;
    }
}
