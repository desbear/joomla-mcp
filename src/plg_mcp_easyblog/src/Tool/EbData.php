<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_easyblog
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Easyblog\Tool;

use Gss\Component\Mcp\Administrator\Helper\Resolver;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

\defined('_JEXEC') or die;

/**
 * Reads EasyBlog data from its tables.
 *
 * EasyBlog keeps two independent state columns: "published" (unpublished, published, scheduled,
 * draft, pending) and "state" (normal, trashed, archived). A trashed post can still have
 * published = 1, so every query has to filter both.
 *
 * @since  0.5.0
 */
final class EbData
{
    /** Values of the "published" column. */
    public const PUBLISHED = ['unpublished' => 0, 'published' => 1, 'scheduled' => 2, 'draft' => 3, 'pending' => 4];

    /** Values of the "state" column. */
    public const STATE = ['normal' => 0, 'trashed' => 1, 'archived' => 2];

    /** Freshly created shells that were never filled in. */
    public const BLANK = 9;

    /**
     * Posts that are not published are only visible to someone who could edit them in the back
     * end. The read tools ask for core.manage today, but the rule belongs with the query so a
     * wider availability callback cannot open the drafts up by accident.
     */
    public static function canSeeUnpublished(ToolContext $ctx): bool
    {
        return $ctx->user->authorise('core.manage', 'com_easyblog')
            || $ctx->user->authorise('core.edit', 'com_easyblog');
    }

    /**
     * Date column for date_from/date_to and the period grouping. A map, so the argument never
     * reaches the SQL as it was given.
     */
    public static function dateColumn(string $dateField): string
    {
        return match ($dateField) {
            'modified'   => 'p.modified',
            'publish_up' => 'p.publish_up',
            default      => 'p.created',
        };
    }

    /**
     * States a tool argument can ask for.
     */
    public static function stateEnum(): array
    {
        return array_merge(array_keys(self::PUBLISHED), ['trashed', 'archived', 'any']);
    }

    /**
     * Apply a state filter to a query on #__easyblog_post AS p.
     */
    public static function applyState(QueryInterface $query, ?string $state): void
    {
        $query->where('p.published <> ' . self::BLANK);

        if ($state === 'any') {
            return;
        }

        if ($state === null) {
            // Everything that still exists: not trashed, not archived
            $query->where('p.state = ' . self::STATE['normal']);

            return;
        }

        if (isset(self::STATE[$state]) && $state !== 'normal') {
            $query->where('p.state = ' . self::STATE[$state]);

            return;
        }

        $query->where('p.state = ' . self::STATE['normal'])->where('p.published = ' . self::PUBLISHED[$state]);
    }

    public static function stateName(object $post): string
    {
        if ((int) $post->state === self::STATE['trashed']) {
            return 'trashed';
        }

        if ((int) $post->state === self::STATE['archived']) {
            return 'archived';
        }

        return array_search((int) $post->published, self::PUBLISHED, true) ?: (string) $post->published;
    }

    // ------------------------------------------------------------------ Categories and tags

    /**
     * @return array<int, object>  id, title, alias, parent_id, published, private, post_count
     */
    public static function categories(ToolContext $ctx): array
    {
        $db    = $ctx->db;
        $count = $db->getQuery(true)
            ->select('COUNT(DISTINCT pc.post_id)')
            ->from($db->quoteName('#__easyblog_post_category', 'pc'))
            ->join('INNER', $db->quoteName('#__easyblog_post', 'p2'), 'p2.id = pc.post_id AND p2.state = 0 AND p2.published = 1')
            ->where('pc.category_id = c.id');

        $query = $db->getQuery(true)
            ->select('c.id, c.title, c.alias, c.parent_id, c.published, c.private, c.lft, c.language')
            ->select('(' . $count . ') AS post_count')
            ->from($db->quoteName('#__easyblog_category', 'c'))
            ->order('c.lft');

        $categories = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $row->id             = (int) $row->id;
            $categories[$row->id] = $row;
        }

        return $categories;
    }

    public static function category(ToolContext $ctx, mixed $ref): object
    {
        $categories = self::categories($ctx);

        if (\is_int($ref) || ctype_digit((string) $ref)) {
            if (isset($categories[(int) $ref])) {
                return $categories[(int) $ref];
            }
        } else {
            $lower   = mb_strtolower(trim((string) $ref));
            $matches = array_filter($categories, fn ($c) => mb_strtolower($c->title) === $lower || mb_strtolower($c->alias) === $lower);

            if (\count($matches) === 1) {
                return reset($matches);
            }

            if (\count($matches) > 1) {
                throw new ToolException(\sprintf('Blog category "%s" is ambiguous (IDs %s).', (string) $ref, implode(', ', array_keys($matches))));
            }
        }

        throw new ToolException(\sprintf('Blog category "%s" not found. Use list_blog_categories.', (string) $ref));
    }

    /**
     * Resolve tags by ID or title.
     *
     * @return array{0: int[], 1: string[]}  Found IDs, unknown titles
     */
    public static function tags(ToolContext $ctx, array $refs): array
    {
        $db      = $ctx->db;
        $ids     = [];
        $missing = [];

        foreach ($refs as $ref) {
            $query = $db->getQuery(true)->select('id')->from($db->quoteName('#__easyblog_tag'));

            if (\is_int($ref) || ctype_digit((string) $ref)) {
                $id = (int) $ref;
                $query->where('id = :id')->bind(':id', $id, ParameterType::INTEGER);
            } else {
                $lower = mb_strtolower(trim((string) $ref));
                $query->where('(LOWER(title) = :title OR LOWER(alias) = :alias)')->bind(':title', $lower)->bind(':alias', $lower);
            }

            $found = $db->setQuery($query)->loadColumn();

            if ($found) {
                $ids[] = (int) $found[0];
            } elseif (\is_int($ref) || ctype_digit((string) $ref)) {
                throw new ToolException(\sprintf('Blog tag ID %s not found.', (string) $ref));
            } else {
                $missing[] = trim((string) $ref);
            }
        }

        return [array_values(array_unique($ids)), array_values(array_unique($missing))];
    }

    // ------------------------------------------------------------------ Posts

    public static function post(ToolContext $ctx, int $id): object
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select('p.*, u.name AS author_name, u.username AS author_username')
            ->from($db->quoteName('#__easyblog_post', 'p'))
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = p.created_by')
            ->where('p.id = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $post = $db->setQuery($query)->loadObject();

        if (!$post || (int) $post->published === self::BLANK) {
            throw new ToolException(\sprintf('Blog post %d not found.', $id));
        }

        return $post;
    }

    /**
     * Categories per post: [post ID] => [[id, title, primary]].
     *
     * @param  int[]  $ids
     */
    public static function postCategories(ToolContext $ctx, array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select('pc.post_id, c.id, c.title, pc.primary AS is_primary')
            ->from($db->quoteName('#__easyblog_post_category', 'pc'))
            ->join('INNER', $db->quoteName('#__easyblog_category', 'c'), 'c.id = pc.category_id')
            ->whereIn('pc.post_id', array_map('intval', $ids))
            ->order('pc.primary DESC, c.lft');

        $result = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $result[(int) $row->post_id][] = ['id' => (int) $row->id, 'title' => $row->title, 'primary' => (bool) $row->is_primary];
        }

        return $result;
    }

    /**
     * Tags per post: [post ID] => [[id, title]]. Rows belonging to a revision are ignored.
     *
     * @param  int[]  $ids
     */
    public static function postTags(ToolContext $ctx, array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select('pt.post_id, t.id, t.title')
            ->from($db->quoteName('#__easyblog_post_tag', 'pt'))
            ->join('INNER', $db->quoteName('#__easyblog_tag', 't'), 't.id = pt.tag_id')
            ->whereIn('pt.post_id', array_map('intval', $ids))
            ->where('pt.isrevision IS NULL')
            ->order('t.title');

        $result = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $result[(int) $row->post_id][] = ['id' => (int) $row->id, 'title' => $row->title];
        }

        return $result;
    }

    /**
     * Number of published comments per post.
     *
     * @param  int[]  $ids
     */
    public static function commentCounts(ToolContext $ctx, array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $db = $ctx->db;

        try {
            $query = $db->getQuery(true)
                ->select('post_id, COUNT(*) AS n')
                ->from($db->quoteName('#__easyblog_comment'))
                ->whereIn('post_id', array_map('intval', $ids))
                ->where('published = 1')
                ->group('post_id');

            return array_map('intval', $db->setQuery($query)->loadAssocList('post_id', 'n'));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * IDs of featured posts among the given ones.
     *
     * @param  int[]  $ids
     *
     * @return int[]
     */
    public static function featured(ToolContext $ctx, array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $db = $ctx->db;

        try {
            $query = $db->getQuery(true)
                ->select('content_id')
                ->from($db->quoteName('#__easyblog_featured'))
                ->where('type = ' . $db->quote('post'))
                ->whereIn('content_id', array_map('intval', $ids));

            return array_map('intval', $db->setQuery($query)->loadColumn());
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * SEO meta of a post: title, keywords, description, canonical.
     */
    public static function meta(ToolContext $ctx, int $id): array
    {
        $db = $ctx->db;

        try {
            $query = $db->getQuery(true)
                ->select('title, keywords, description, canonical')
                ->from($db->quoteName('#__easyblog_meta'))
                ->where('type = ' . $db->quote('post'))
                ->where('content_id = :id')
                ->bind(':id', $id, ParameterType::INTEGER);

            return (array) ($db->setQuery($query)->loadAssoc() ?: []);
        } catch (\Throwable) {
            return [];
        }
    }

    // ------------------------------------------------------------------ Filters

    /**
     * JSON schema of the post filters, shared by search and statistics.
     */
    public static function filterSchema(): array
    {
        $ref = ['type' => ['integer', 'string']];

        return [
            'query'      => ['type' => 'string', 'description' => 'Text in the title (or the content with search_in).'],
            'search_in'  => ['type' => 'string', 'enum' => ['title', 'content', 'all']],
            'category'   => $ref + ['description' => 'Blog category ID, title or alias.'],
            'tag'        => $ref + ['description' => 'Blog tag ID or title.'],
            'author'     => $ref + ['description' => 'User ID, username or name.'],
            'state'      => ['type' => 'string', 'enum' => self::stateEnum(), 'description' => 'Default: everything except trashed and archived.'],
            'featured'   => ['type' => 'boolean'],
            'language'   => ['type' => 'string'],
            'date_field' => ['type' => 'string', 'enum' => ['created', 'modified', 'publish_up'], 'description' => 'Default created.'],
            'date_from'  => ['type' => 'string', 'description' => 'YYYY-MM-DD, site time zone.'],
            'date_to'    => ['type' => 'string', 'description' => 'YYYY-MM-DD, inclusive.'],
        ];
    }

    /**
     * Apply the filters to a query on #__easyblog_post AS p.
     */
    public static function applyFilters(ToolContext $ctx, QueryInterface $query, array $args): array
    {
        $db      = $ctx->db;
        $applied = [];

        $state = $args['state'] ?? null;

        if (!self::canSeeUnpublished($ctx)) {
            if ($state !== null && $state !== 'published') {
                throw new ToolException('You are not allowed to list anything other than published posts.');
            }

            $state = 'published';
        }

        self::applyState($query, $state);

        if (($args['query'] ?? '') !== '') {
            $cols   = match ($args['search_in'] ?? 'title') {
                'content' => ['p.intro', 'p.content'],
                'all'     => ['p.title', 'p.intro', 'p.content'],
                default   => ['p.title', 'p.permalink'],
            };

            Resolver::applyLike($query, $cols, $args['query'], 'ebsearch');
            $applied['query'] = $args['query'];
        }

        if (isset($args['category'])) {
            $category = self::category($ctx, $args['category']);
            $query->where('EXISTS (SELECT 1 FROM ' . $db->quoteName('#__easyblog_post_category') . ' fpc WHERE fpc.post_id = p.id AND fpc.category_id = ' . $category->id . ')');
            $applied['category'] = $category->title;
        }

        if (isset($args['tag'])) {
            [$tagIds] = self::tags($ctx, [$args['tag']]);

            if (!$tagIds) {
                throw new ToolException(\sprintf('Blog tag "%s" not found. Use list_blog_tags.', (string) $args['tag']));
            }

            $query->where('EXISTS (SELECT 1 FROM ' . $db->quoteName('#__easyblog_post_tag') . ' fpt WHERE fpt.post_id = p.id AND fpt.isrevision IS NULL AND fpt.tag_id IN ('
                . implode(',', $tagIds) . '))');
            $applied['tag'] = $args['tag'];
        }

        if (isset($args['author'])) {
            $author = Resolver::userId($ctx, $args['author']);
            $query->where('p.created_by = ' . $author);
            $applied['author'] = $author;
        }

        if (isset($args['featured'])) {
            $exists = 'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__easyblog_featured') . ' ff WHERE ff.type = ' . $db->quote('post') . ' AND ff.content_id = p.id)';
            $query->where($args['featured'] ? $exists : 'NOT ' . $exists);
            $applied['featured'] = $args['featured'];
        }

        if (!empty($args['language'])) {
            $query->where('p.language = :eblang')->bind(':eblang', $args['language']);
            $applied['language'] = $args['language'];
        }

        $dateField = self::dateColumn($args['date_field'] ?? 'created');

        if (!empty($args['date_from'])) {
            $from = Resolver::sqlDate($ctx, $args['date_from']);
            $query->where($dateField . ' >= :ebfrom')->bind(':ebfrom', $from);
            $applied['date_from'] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $to = Resolver::sqlDate($ctx, $args['date_to'], true);
            $query->where($dateField . ' <= :ebto')->bind(':ebto', $to);
            $applied['date_to'] = $args['date_to'];
        }

        if (!empty($args['state'])) {
            $applied['state'] = $args['state'];
        }

        return $applied;
    }

    /**
     * Plain text preview of a post's intro.
     */
    public static function excerpt(object $post, int $length = 200): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($post->intro ?: $post->content))));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
    }
}
