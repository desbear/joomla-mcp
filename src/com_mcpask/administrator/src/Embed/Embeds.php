<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Embed;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Embed keys: Ask the Site put on other websites by one line of script. Each key says which
 * websites may show it (the browser refuses any other), how it looks, its language, how many
 * questions it may take a day, and whether visitors may sign in.
 *
 * The public key is in the other website's page for all to see; the secret is for the other
 * website's server only, to sign who its signed-in visitor is.
 *
 * @since  0.11.0
 */
final class Embeds
{
    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * @return  object[]  With params as Registry and domains as a list of origins
     */
    public function all(): array
    {
        return array_map([self::class, 'prepare'], $this->db->setQuery(
            $this->db->getQuery(true)->select('*')->from($this->db->quoteName('#__mcpask_embeds'))->order('title')
        )->loadObjectList());
    }

    public function get(int $id): ?object
    {
        $row = $this->db->setQuery(
            $this->db->getQuery(true)->select('*')->from($this->db->quoteName('#__mcpask_embeds'))->where('id = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadObject();

        return $row ? self::prepare($row) : null;
    }

    /**
     * A published key, by the public key the other website's page gives.
     */
    public function byKey(string $key): ?object
    {
        if (!preg_match('/^pk_[0-9a-f]{24}$/', $key)) {
            return null;
        }

        $row = $this->db->setQuery(
            $this->db->getQuery(true)->select('*')->from($this->db->quoteName('#__mcpask_embeds'))
                ->where('public_key = :key')->where('published = 1')->bind(':key', $key)
        )->loadObject();

        return $row ? self::prepare($row) : null;
    }

    /**
     * @param   array{id?: int, title: string, domains: string, published?: int, params?: array}  $data
     */
    public function save(array $data): int
    {
        $now = Factory::getDate()->toSql();
        $id  = (int) ($data['id'] ?? 0);
        $row = (object) [
            'title'     => mb_substr(trim((string) ($data['title'] ?? '')), 0, 255),
            'domains'   => implode("\n", self::origins((string) ($data['domains'] ?? ''))),
            'published' => (int) !empty($data['published']),
            'params'    => json_encode((array) ($data['params'] ?? []), JSON_UNESCAPED_UNICODE),
            'modified'  => $now,
        ];

        if ($id > 0 && $this->get($id)) {
            $row->id = $id;
            $this->db->updateObject('#__mcpask_embeds', $row, 'id');

            return $id;
        }

        $row->public_key = 'pk_' . bin2hex(random_bytes(12));
        $row->secret     = bin2hex(random_bytes(24));
        $row->created    = $now;
        $this->db->insertObject('#__mcpask_embeds', $row, 'id');

        return (int) $row->id;
    }

    /**
     * A new secret: tokens signed with the old one stop working at once.
     */
    public function newSecret(int $id): void
    {
        $row = (object) ['id' => $id, 'secret' => bin2hex(random_bytes(24)), 'modified' => Factory::getDate()->toSql()];
        $this->db->updateObject('#__mcpask_embeds', $row, 'id');
    }

    public function publish(int $id, bool $published): void
    {
        $row = (object) ['id' => $id, 'published' => (int) $published, 'modified' => Factory::getDate()->toSql()];
        $this->db->updateObject('#__mcpask_embeds', $row, 'id');
    }

    public function delete(int $id): void
    {
        $this->db->setQuery($this->db->getQuery(true)->delete($this->db->quoteName('#__mcpask_embeds'))->where('id = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();
    }

    /**
     * Questions asked through each key, today and in the last 30 days.
     *
     * @return  array<int, array{today: int, month: int}>
     */
    public function usage(): array
    {
        $db    = $this->db;
        $today = Factory::getDate('today', Factory::getApplication()->get('offset', 'UTC'))->toSql();
        $month = Factory::getDate('-30 days')->toSql();
        $usage = [];

        foreach ($db->setQuery(
            $db->getQuery(true)
                ->select('embed_id, SUM(CASE WHEN created >= :today THEN 1 ELSE 0 END) AS today, COUNT(*) AS month')
                ->from($db->quoteName('#__mcpask_log'))
                ->where('embed_id > 0')->where('created >= :month')
                ->bind(':today', $today)->bind(':month', $month)
                ->group('embed_id')
        )->loadObjectList() as $row) {
            $usage[(int) $row->embed_id] = ['today' => (int) $row->today, 'month' => (int) $row->month];
        }

        return $usage;
    }

    /**
     * Whether the key has taken all the questions it may today.
     */
    public function spent(object $embed): bool
    {
        $limit = (int) $embed->params->get('per_day', 0);

        if ($limit < 1) {
            return false;
        }

        $id    = (int) $embed->id;
        $today = Factory::getDate('today', Factory::getApplication()->get('offset', 'UTC'))->toSql();
        $count = (int) $this->db->setQuery(
            $this->db->getQuery(true)->select('COUNT(*)')->from($this->db->quoteName('#__mcpask_log'))
                ->where('embed_id = :id')->where('created >= :today')
                ->bind(':id', $id, ParameterType::INTEGER)->bind(':today', $today)
        )->loadResult();

        return $count >= $limit;
    }

    /**
     * Whether a page of this origin may show the key ("https://example.com", "*.example.com" too).
     */
    public static function allows(object $embed, string $origin): bool
    {
        $origin = strtolower(rtrim($origin, '/'));
        $parts  = parse_url($origin);

        if (!isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        foreach ($embed->origins as $allowed) {
            if ($allowed === $origin) {
                return true;
            }

            // https://*.example.com: any subdomain, same scheme and port
            if (preg_match('#^(https?)://\*\.([^/:]+)(:\d+)?$#', $allowed, $match)
                && $match[1] === $parts['scheme']
                && str_ends_with($parts['host'], '.' . $match[2])
                && ($match[3] ?? '') === (isset($parts['port']) ? ':' . $parts['port'] : '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The value of the frame-ancestors directive: the websites that may show the key.
     */
    public static function frameAncestors(object $embed): string
    {
        return $embed->origins ? implode(' ', $embed->origins) : "'none'";
    }

    /**
     * Origins from what was typed: one a line (or separated by spaces or commas), "https://" when
     * no scheme is given; paths are dropped. "http://" only for local addresses.
     *
     * @return  string[]
     */
    public static function origins(string $text): array
    {
        $origins = [];

        foreach (preg_split('/[\s,]+/', strtolower($text)) ?: [] as $item) {
            $item = trim($item);

            if ($item === '') {
                continue;
            }

            if (!str_contains($item, '://')) {
                $item = 'https://' . $item;
            }

            if (!preg_match('#^(https?)://((?:\*\.)?[a-z0-9.\-]+|\[[0-9a-f:]+\])(:\d{1,5})?(?:/.*)?$#', $item, $match)) {
                continue;
            }

            $local = preg_match('/^(localhost|127\.0\.0\.1|\[::1\])$|\.(test|localhost)$/', $match[2]);

            if ($match[1] === 'http' && !$local) {
                continue;
            }

            $origins[] = $match[1] . '://' . $match[2] . ($match[3] ?? '');
        }

        return array_values(array_unique($origins));
    }

    private static function prepare(object $row): object
    {
        $row->params  = new Registry($row->params ?: '{}');
        $row->origins = array_values(array_filter(preg_split('/\R/', (string) $row->domains) ?: []));

        return $row;
    }
}
