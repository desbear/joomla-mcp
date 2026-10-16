<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Helper;

use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;
use Joomla\CMS\Factory;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

\defined('_JEXEC') or die;

/**
 * Turns the loose references a model uses (IDs, titles, paths, usernames) into database IDs.
 *
 * Shared by all tool plugins so they behave consistently.
 *
 * @since  0.1.0
 */
final class Resolver
{
    /**
     * Resolve a category by ID, path ("news/tech") or title.
     *
     * @return  object  {id, title, path, lft, rgt}
     */
    public static function category(ToolContext $ctx, mixed $ref, string $extension = 'com_content'): object
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title', 'path', 'lft', 'rgt', 'published']))
            ->from($db->quoteName('#__categories'))
            ->where($db->quoteName('extension') . ' = :extension')
            ->where($db->quoteName('published') . ' <> -2')
            ->bind(':extension', $extension);

        if (\is_int($ref) || (\is_string($ref) && ctype_digit($ref))) {
            $id = (int) $ref;
            $query->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
        } else {
            $ref   = trim((string) $ref, " /\t\n");
            $lower = mb_strtolower($ref);
            $query->where('(LOWER(' . $db->quoteName('path') . ') = :path OR LOWER(' . $db->quoteName('title') . ') = :title)')
                ->bind(':path', $lower)
                ->bind(':title', $lower);
        }

        $rows = $db->setQuery($query)->loadObjectList();

        if (!$rows) {
            throw new ToolException(\sprintf('Category "%s" not found. Use list_categories to see the available categories.', (string) $ref));
        }

        if (\count($rows) > 1) {
            $options = array_map(fn ($r) => \sprintf('%d (%s)', $r->id, $r->path), $rows);

            throw new ToolException(\sprintf('Category "%s" is ambiguous, use one of these IDs: %s.', (string) $ref, implode(', ', $options)));
        }

        $category = $rows[0];
        $category->id = (int) $category->id;

        self::assertCategoryAllowed($ctx, $category->id);

        return $category;
    }

    /**
     * IDs of a category and all its sub-categories.
     *
     * @return int[]
     */
    public static function categoryTree(ToolContext $ctx, int $id): array
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select('c.id')
            ->from($db->quoteName('#__categories', 'c'))
            ->join('INNER', $db->quoteName('#__categories', 'p'), 'c.lft >= p.lft AND c.rgt <= p.rgt AND c.extension = p.extension')
            ->where('p.id = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        return array_map('intval', $db->setQuery($query)->loadColumn());
    }

    /**
     * Categories the server may use, including sub-categories of the configured ones. Null means all.
     *
     * @return int[]|null
     */
    public static function allowedCategoryIds(ToolContext $ctx): ?array
    {
        static $cache = [];

        $roots = $ctx->allowedCategories();

        if (!$roots) {
            return null;
        }

        $key = implode(',', $roots);

        if (!isset($cache[$key])) {
            $ids = [];

            foreach ($roots as $root) {
                $ids = array_merge($ids, self::categoryTree($ctx, $root));
            }

            $cache[$key] = array_values(array_unique($ids));
        }

        return $cache[$key];
    }

    public static function assertCategoryAllowed(ToolContext $ctx, int $id): void
    {
        $allowed = self::allowedCategoryIds($ctx);

        if ($allowed !== null && !\in_array($id, $allowed, true)) {
            throw new ToolException(\sprintf('Category %d is outside the categories this MCP server is allowed to use.', $id));
        }
    }

    /**
     * Restrict a query on #__content AS a to the access levels of the user and the allowed categories.
     */
    public static function applyArticleVisibility(ToolContext $ctx, QueryInterface $query): void
    {
        if (!$ctx->user->authorise('core.admin')) {
            $query->whereIn('a.access', $ctx->user->getAuthorisedViewLevels());
        }

        $allowed = self::allowedCategoryIds($ctx);

        if ($allowed !== null) {
            $query->whereIn('a.catid', $allowed ?: [0]);
        }
    }

    /**
     * Resolve tags by ID, title or path.
     *
     * @param   array  $refs           Tag references
     * @param   bool   $createMissing  Unknown titles become "#new#Title", which Joomla creates on save
     *
     * @return  array  Tag IDs (int) and "#new#" strings
     */
    public static function tags(ToolContext $ctx, array $refs, bool $createMissing = false): array
    {
        $db     = $ctx->db;
        $result = [];

        foreach ($refs as $ref) {
            if (\is_int($ref) || (\is_string($ref) && ctype_digit($ref))) {
                $id    = (int) $ref;
                $query = $db->getQuery(true)
                    ->select('id')
                    ->from($db->quoteName('#__tags'))
                    ->where($db->quoteName('id') . ' = :id')
                    ->where($db->quoteName('id') . ' > 1')
                    ->bind(':id', $id, ParameterType::INTEGER);

                if (!$db->setQuery($query)->loadResult()) {
                    throw new ToolException(\sprintf('Tag ID %d not found. Use list_tags to see the available tags.', $id));
                }

                $result[] = $id;
                continue;
            }

            $name  = trim((string) $ref);
            $lower = mb_strtolower($name);

            if ($name === '') {
                continue;
            }

            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'path']))
                ->from($db->quoteName('#__tags'))
                ->where('(LOWER(' . $db->quoteName('title') . ') = :title OR LOWER(' . $db->quoteName('path') . ') = :path)')
                ->where($db->quoteName('published') . ' <> -2')
                ->where($db->quoteName('id') . ' > 1')
                ->bind(':title', $lower)
                ->bind(':path', $lower);

            $rows = $db->setQuery($query)->loadObjectList();

            if (\count($rows) === 1) {
                $result[] = (int) $rows[0]->id;
            } elseif (\count($rows) > 1) {
                $options = array_map(fn ($r) => \sprintf('%d (%s)', $r->id, $r->path), $rows);

                throw new ToolException(\sprintf('Tag "%s" is ambiguous, use one of these IDs: %s.', $name, implode(', ', $options)));
            } elseif ($createMissing) {
                $result[] = '#new#' . $name;
            } else {
                throw new ToolException(\sprintf('Tag "%s" not found. Use list_tags, create_tag, or pass create_missing_tags: true.', $name));
            }
        }

        return array_values(array_unique($result, SORT_REGULAR));
    }

    /**
     * Resolve a menu (menu type) by its unique name ("mainmenu") or title.
     *
     * @return  object  {id, menutype, title, client_id}
     */
    public static function menu(ToolContext $ctx, mixed $ref): object
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'menutype', 'title', 'client_id']))
            ->from($db->quoteName('#__menu_types'));

        if (\is_int($ref) || (\is_string($ref) && ctype_digit($ref))) {
            $id = (int) $ref;
            $query->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
        } else {
            $lower = mb_strtolower(trim((string) $ref));
            $query->where('(LOWER(' . $db->quoteName('menutype') . ') = :type OR LOWER(' . $db->quoteName('title') . ') = :title)')
                ->bind(':type', $lower)
                ->bind(':title', $lower);
        }

        $rows = $db->setQuery($query)->loadObjectList();

        if (\count($rows) !== 1) {
            throw new ToolException(\sprintf(
                'Menu "%s" %s. Use list_menus to see the menus.',
                (string) $ref,
                $rows ? 'is ambiguous (' . implode(', ', array_map(fn ($r) => $r->menutype, $rows)) . ')' : 'not found'
            ));
        }

        $rows[0]->id        = (int) $rows[0]->id;
        $rows[0]->client_id = (int) $rows[0]->client_id;

        return $rows[0];
    }

    /**
     * Resolve a menu item by ID, or by title / alias / path (optionally within one menu).
     *
     * @return  object  {id, title, alias, path, menutype, parent_id, level, client_id}
     */
    public static function menuItem(ToolContext $ctx, mixed $ref, ?string $menutype = null): object
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title', 'alias', 'path', 'menutype', 'parent_id', 'level', 'client_id']))
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('id') . ' > 1')
            ->where($db->quoteName('published') . ' <> -2');

        if (\is_int($ref) || (\is_string($ref) && ctype_digit($ref))) {
            $id = (int) $ref;
            $query->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
        } else {
            $lower = mb_strtolower(trim((string) $ref, " /\t\n"));
            $query->where('(LOWER(' . $db->quoteName('title') . ') = :title OR LOWER(' . $db->quoteName('path') . ') = :path)')
                ->bind(':title', $lower)
                ->bind(':path', $lower);

            if ($menutype !== null) {
                $query->where($db->quoteName('menutype') . ' = :menutype')->bind(':menutype', $menutype);
            }
        }

        $rows = $db->setQuery($query)->loadObjectList();

        if (!$rows) {
            throw new ToolException(\sprintf('Menu item "%s" not found. Use list_menu_items to see the menu items.', (string) $ref));
        }

        if (\count($rows) > 1) {
            $options = array_map(fn ($r) => \sprintf('%d (%s: %s)', $r->id, $r->menutype, $r->path), $rows);

            throw new ToolException(\sprintf('Menu item "%s" is ambiguous, use one of these IDs: %s.', (string) $ref, implode(', ', $options)));
        }

        foreach (['id', 'parent_id', 'level', 'client_id'] as $key) {
            $rows[0]->$key = (int) $rows[0]->$key;
        }

        return $rows[0];
    }

    /**
     * Resolve an article by ID or exact title.
     *
     * @return  object  {id, title, catid}
     */
    public static function article(ToolContext $ctx, mixed $ref): object
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['a.id', 'a.title', 'a.catid']))
            ->from($db->quoteName('#__content', 'a'))
            ->where($db->quoteName('a.state') . ' <> -2');

        if (\is_int($ref) || (\is_string($ref) && ctype_digit($ref))) {
            $id = (int) $ref;
            $query->where($db->quoteName('a.id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
        } else {
            $lower = mb_strtolower(trim((string) $ref));
            $query->where('LOWER(' . $db->quoteName('a.title') . ') = :title')->bind(':title', $lower);
        }

        self::applyArticleVisibility($ctx, $query);

        $rows = $db->setQuery($query)->loadObjectList();

        if (\count($rows) !== 1) {
            throw new ToolException(\sprintf(
                'Article "%s" %s. Use search_articles and pass the article ID.',
                (string) $ref,
                $rows ? 'is ambiguous (IDs ' . implode(', ', array_map(fn ($r) => $r->id, $rows)) . ')' : 'not found'
            ));
        }

        $rows[0]->id = (int) $rows[0]->id;

        return $rows[0];
    }

    /**
     * Resolve a user by ID, username, name or e-mail.
     */
    public static function userId(ToolContext $ctx, mixed $ref): int
    {
        $db = $ctx->db;

        if (\is_int($ref) || (\is_string($ref) && ctype_digit($ref))) {
            $id    = (int) $ref;
            $query = $db->getQuery(true)
                ->select('id')
                ->from($db->quoteName('#__users'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER);

            if (!$db->setQuery($query)->loadResult()) {
                throw new ToolException(\sprintf('There is no user with ID %d.', $id));
            }

            return $id;
        }

        $lower = mb_strtolower(trim((string) $ref));
        $query = $db->getQuery(true)
            ->select('id')
            ->from($db->quoteName('#__users'))
            ->where('(LOWER(' . $db->quoteName('username') . ') = :u OR LOWER(' . $db->quoteName('name') . ') = :n OR LOWER(' . $db->quoteName('email') . ') = :e)')
            ->bind(':u', $lower)
            ->bind(':n', $lower)
            ->bind(':e', $lower);

        $ids = $db->setQuery($query)->loadColumn();

        if (\count($ids) !== 1) {
            throw new ToolException(\sprintf('User "%s" %s. Use the numeric user ID instead.', (string) $ref, $ids ? 'is ambiguous' : 'not found'));
        }

        return (int) $ids[0];
    }

    /**
     * A date column shifted into the site's own time zone, for grouping.
     *
     * Joomla stores dates as UTC, so grouping on the raw column buckets by UTC day. An
     * administrator reading a daily digest means their own day, and an hour-of-day breakdown
     * grouped in UTC is simply wrong by the offset.
     *
     * The column name is written by the calling tool, never taken from a tool argument.
     */
    public static function inSiteTime(ToolContext $ctx, string $column): string
    {
        $zone   = new \DateTimeZone((string) $ctx->app->get('offset', 'UTC'));
        $offset = $zone->getOffset(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        return $offset === 0 ? $column : '(' . $column . ' + INTERVAL ' . (int) $offset . ' SECOND)';
    }

    /**
     * Add a case-insensitive LIKE condition over several columns, wildcards escaped.
     *
     * Every column gets its own placeholder on purpose. On Joomla 4 a named placeholder that
     * appears more than once in a statement but is bound once fails with "The number of variables
     * must match the number of parameters in the prepared statement"; Joomla 5 tolerates it. One
     * placeholder per column works on both.
     *
     * @param  string[]  $columns  Column expressions, e.g. ['a.title', 'a.alias']
     */
    public static function applyLike(QueryInterface $query, array $columns, string $value, string $prefix = 'like'): void
    {
        $columns = array_values($columns);

        if (!$columns) {
            return;
        }

        $pattern    = '%' . self::likeValue(mb_strtolower(trim($value))) . '%';
        $conditions = [];

        foreach ($columns as $i => $column) {
            $key          = ':' . $prefix . $i;
            $conditions[] = 'LOWER(' . $column . ') LIKE ' . $key;
            $query->bind($key, $pattern);
        }

        $query->where('(' . implode(' OR ', $conditions) . ')');
    }

    /**
     * Escape the wildcards in a value that goes into a LIKE pattern. Binding the value stops
     * injection but leaves "%" and "_" active, so a search for "%" would match every row and
     * "a%a%a%a%" would force an unindexable scan.
     */
    public static function likeValue(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * Convert a date given in the site time zone ("2026-01-31", "2026-01-31 14:00") to a UTC SQL date.
     */
    public static function sqlDate(ToolContext $ctx, string $value, bool $endOfDay = false): string
    {
        $value = trim($value);

        // Factory::getDate() also accepts relative expressions ("+5000 years", "next tuesday"),
        // which a model reaches for when it is guessing; an explicit date is always meant here
        if (!preg_match('/^\d{4}-\d{2}(-\d{2})?([ T]\d{2}:\d{2}(:\d{2})?)?$/', $value)) {
            throw new ToolException(\sprintf('Invalid date "%s". Use YYYY-MM-DD or YYYY-MM-DD HH:MM.', $value));
        }

        if (preg_match('/^\d{4}-\d{2}$/', $value)) {
            $value .= '-01';
        }

        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= ' 23:59:59';
        }

        try {
            return Factory::getDate($value, $ctx->app->get('offset', 'UTC'))->toSql();
        } catch (\Throwable) {
            throw new ToolException(\sprintf('Invalid date "%s". Use YYYY-MM-DD or YYYY-MM-DD HH:MM.', $value));
        }
    }

    /**
     * Format a UTC SQL date in the site time zone, or null for empty dates.
     */
    public static function localDate(ToolContext $ctx, ?string $value): ?string
    {
        if (!$value || str_starts_with($value, '0000')) {
            return null;
        }

        $date = Factory::getDate($value, 'UTC');
        $date->setTimezone(new \DateTimeZone($ctx->app->get('offset', 'UTC')));

        return $date->format('Y-m-d H:i', true);
    }
}
