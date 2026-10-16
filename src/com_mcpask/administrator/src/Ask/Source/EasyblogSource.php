<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Ask\Source;

use Gss\Component\Mcpask\Administrator\Ask\Document;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;

\defined('_JEXEC') or die;

/**
 * EasyBlog posts, read alongside the site's articles.
 *
 * EasyBlog has its own rules for who may read a post instead of Joomla's view levels, so only
 * what is open to everyone by all of them is offered: published and not trashed or archived,
 * within its dates, public, without a password or "log in to read", in a published public
 * category, and when it belongs to a team blog, a team that everyone may read.
 *
 * @since  0.4.0
 */
final class EasyblogSource implements SourceInterface
{
    private const PUBLISHED = 1;

    private const NORMAL = 0;

    private const TEAM_EVERYONE = 3;

    public function __construct(private DatabaseInterface $db)
    {
    }

    public static function isAvailable(): bool
    {
        return ComponentHelper::isInstalled('com_easyblog') && ComponentHelper::isEnabled('com_easyblog');
    }

    public function name(): string
    {
        return 'easyblog';
    }

    public function ids(int $afterId = 0, int $limit = 0): array
    {
        $query = $this->eligible()->select('p.id')->where('p.id > ' . $afterId)->order('p.id');

        if ($limit > 0) {
            $query->setLimit($limit);
        }

        return array_map('intval', $this->db->setQuery($query)->loadColumn());
    }

    public function visible(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (!$ids) {
            return [];
        }

        $query = $this->eligible()->select('p.id')->whereIn('p.id', $ids);

        return array_map('intval', $this->db->setQuery($query)->loadColumn());
    }

    public function modified(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (!$ids) {
            return [];
        }

        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('id')
            // A date that was never set reads as empty or as the zero date
            ->select('CASE WHEN modified IS NULL OR modified < ' . $db->quote('1971-01-01') . ' THEN created ELSE modified END AS changed')
            ->from($db->quoteName('#__easyblog_post'))
            ->whereIn('id', $ids);

        return array_map('strval', $db->setQuery($query)->loadAssocList('id', 'changed'));
    }

    public function load(int $id): ?Document
    {
        $query = $this->eligible()
            ->select('p.id, p.title, p.permalink, p.language, p.intro, p.content')
            ->where('p.id = ' . $id);
        $row   = $this->db->setQuery($query)->loadObject();

        if (!$row) {
            return null;
        }

        // Blocks and legacy posts alike keep the shown text in intro and content
        $html = (string) $row->intro . "\n\n" . (string) $row->content;

        return new Document($this->name(), (int) $row->id, (string) $row->title, $this->link($row), (string) $row->language ?: '*', 1, $html);
    }

    public function url(string $link): string
    {
        // EasyBlog builds its own addresses (permalinks, its menu items, its SEF settings)
        $easyblog = JPATH_ADMINISTRATOR . '/components/com_easyblog/includes/easyblog.php';

        try {
            if (is_file($easyblog)) {
                require_once $easyblog;

                if (class_exists('EBR')) {
                    return (string) \EBR::getRoutedURL($link, false, true);
                }
            }
        } catch (\Throwable) {
            // Joomla's router below
        }

        try {
            return Route::link('site', $link, false, Route::TLS_IGNORE, true);
        } catch (\Throwable) {
            return rtrim(Uri::root(), '/') . '/' . $link;
        }
    }

    public function textFields(): array
    {
        return [];
    }

    /**
     * Posts everyone may read. Without a select list: the caller adds what it needs.
     */
    private function eligible(): QueryInterface
    {
        $db    = $this->db;
        $now   = $db->quote(Factory::getDate()->toSql());
        $never = $db->quote($db->getNullDate());

        return $db->getQuery(true)
            ->from($db->quoteName('#__easyblog_post', 'p'))
            ->join('INNER', $db->quoteName('#__easyblog_category', 'c'), 'c.id = p.category_id')
            ->join('LEFT', $db->quoteName('#__easyblog_team', 't'), 't.id = p.source_id AND p.source_type = ' . $db->quote('easyblog.team'))
            ->where('p.published = ' . self::PUBLISHED)
            ->where('p.state = ' . self::NORMAL)
            ->where('p.access = 0')
            ->where('p.login_to_read = 0')
            ->where('(p.blogpassword IS NULL OR p.blogpassword = ' . $db->quote('') . ')')
            ->where('(p.publish_up IS NULL OR p.publish_up = ' . $never . ' OR p.publish_up <= ' . $now . ')')
            ->where('(p.publish_down IS NULL OR p.publish_down = ' . $never . ' OR p.publish_down < ' . $db->quote('1971-01-01') . ' OR p.publish_down >= ' . $now . ')')
            ->where('c.published = 1')
            ->where('c.private = 0')
            // Nor in a category inside one that is unpublished or private
            ->where(
                'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__easyblog_category', 'up')
                . ' WHERE up.lft < c.lft AND up.rgt > c.rgt AND (up.published <> 1 OR up.private <> 0))'
            )
            // Sitewide posts, and posts of team blogs open to everyone; groups and events have their own members
            ->where('(p.source_type IN (' . $db->quote('') . ', ' . $db->quote('easyblog.sitewide') . ')'
                . ' OR (p.source_type = ' . $db->quote('easyblog.team') . ' AND t.published = 1 AND t.access = ' . self::TEAM_EVERYONE . '))');
    }

    private function link(object $row): string
    {
        $link = 'index.php?option=com_easyblog&view=entry&id=' . (int) $row->id;

        return $row->language && $row->language !== '*' ? $link . '&lang=' . $row->language : $link;
    }
}
