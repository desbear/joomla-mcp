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
use Gss\Component\Mcp\Administrator\Mcp\Tool;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/**
 * EasyBlog tools. EasyBlog is a blog beside Joomla's articles, so these tools are added next to
 * the article tools rather than replacing them.
 *
 * @since  0.5.0
 */
final class EasyblogTools
{
    /** Most posts one bulk call may touch: each one is a full EasyBlog save. */
    public const MAX_BULK = 100;

    public static function isAvailable(): bool
    {
        return EbEnvironment::isAvailable();
    }

    /**
     * @return Tool[]
     */
    public static function tools(): array
    {
        $ref     = ['type' => ['integer', 'string']];
        $refList = ['type' => 'array', 'items' => $ref];
        $can     = fn (string $action) => fn (ToolContext $c) => $c->user->authorise($action, 'com_easyblog');

        $writable = [
            'intro'        => ['type' => 'string', 'description' => 'Intro HTML, shown in listings and before the read more.'],
            'content'      => ['type' => 'string', 'description' => 'The rest of the post after the read more (HTML). Pass intro alone for a post without a read more.'],
            'categories'   => $refList + ['description' => 'Additional blog categories besides the main one.'],
            'tags'         => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tag titles; unknown ones are created (needs the EasyBlog "create tag" permission).'],
            'alias'        => ['type' => 'string', 'description' => 'URL segment; generated from the title when empty.'],
            'featured'     => ['type' => 'boolean'],
            'language'     => ['type' => 'string', 'description' => 'Language tag, or "*" for all (default).'],
            'metakey'      => ['type' => 'string', 'description' => 'SEO keywords.'],
            'metadesc'     => ['type' => 'string', 'description' => 'SEO description.'],
            'publish_up'   => ['type' => 'string', 'description' => 'Site time zone, YYYY-MM-DD HH:MM. A future date schedules the post.'],
            'publish_down' => ['type' => 'string', 'description' => 'Site time zone, YYYY-MM-DD HH:MM.'],
        ];

        return [
            new Tool(
                'list_blog_posts',
                'Search EasyBlog posts by text, blog category, blog tag, author, state, featured flag and dates, with sorting and paging. '
                . 'Returns summary rows with an excerpt; use get_blog_post for the full text.',
                [
                    'properties' => EbData::filterSchema() + [
                        'order_by'  => ['type' => 'string', 'enum' => ['created', 'modified', 'publish_up', 'hits', 'title', 'id']],
                        'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                        'limit'     => ['type' => 'integer', 'description' => 'Default 20.'],
                        'offset'    => ['type' => 'integer'],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'listPosts'],
                'List blog posts',
                Tool::SCOPE_READ,
                'easyblog',
                false,
                $can('core.manage')
            ),
            new Tool(
                'get_blog_post',
                'Get one EasyBlog post with its text, categories, tags, author, SEO data, hits and comment count.',
                ['properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
                [self::class, 'getPost'],
                'Get blog post',
                Tool::SCOPE_READ,
                'easyblog',
                false,
                $can('core.manage')
            ),
            new Tool(
                'list_blog_categories',
                'List the EasyBlog categories with the number of published posts in each.',
                ['properties' => [], 'additionalProperties' => false],
                [self::class, 'listCategories'],
                'List blog categories',
                Tool::SCOPE_READ,
                'easyblog',
                false,
                $can('core.manage')
            ),
            new Tool(
                'list_blog_tags',
                'List EasyBlog tags with the number of posts using each.',
                [
                    'properties'           => ['query' => ['type' => 'string'], 'limit' => ['type' => 'integer'], 'offset' => ['type' => 'integer']],
                    'additionalProperties' => false,
                ],
                [self::class, 'listTags'],
                'List blog tags',
                Tool::SCOPE_READ,
                'easyblog',
                false,
                $can('core.manage')
            ),
            new Tool(
                'blog_overview',
                'Overview of the blog: posts by state, by category, recent activity, most read posts and most commented posts.',
                ['properties' => [], 'additionalProperties' => false],
                [self::class, 'overview'],
                'Blog overview',
                Tool::SCOPE_READ,
                'easyblog',
                false,
                $can('core.manage')
            ),
            new Tool(
                'blog_stats',
                'Count EasyBlog posts (and sum hits) grouped by blog category, tag, author, state, language, featured flag or a period. '
                . 'Accepts the same filters as list_blog_posts.',
                [
                    'properties' => [
                        'group_by' => ['type' => 'string', 'enum' => ['category', 'tag', 'author', 'state', 'language', 'featured', 'year', 'quarter', 'month', 'week', 'day']],
                        'sort'     => ['type' => 'string', 'enum' => ['count', 'hits', 'key']],
                        'limit'    => ['type' => 'integer', 'description' => 'Maximum number of groups (default 100).'],
                    ] + EbData::filterSchema(),
                    'required'             => ['group_by'],
                    'additionalProperties' => false,
                ],
                [self::class, 'stats'],
                'Blog statistics',
                Tool::SCOPE_READ,
                'easyblog',
                false,
                $can('core.manage')
            ),
            new Tool(
                'create_blog_post',
                'Write a new EasyBlog post. Unless "state" is given the configured default for new content is used (normally unpublished). '
                . 'Posts are created in the classic HTML format.',
                [
                    'properties' => [
                        'title'    => ['type' => 'string'],
                        'category' => $ref + ['description' => 'Main blog category (ID, title or alias).'],
                        'state'    => ['type' => 'string', 'enum' => ['published', 'unpublished', 'draft', 'pending']],
                    ] + $writable,
                    'required'             => ['title', 'category'],
                    'additionalProperties' => false,
                ],
                [self::class, 'create'],
                'Create blog post',
                Tool::SCOPE_WRITE,
                'easyblog',
                false,
                $can('core.manage')
            ),
            new Tool(
                'update_blog_post',
                'Update an EasyBlog post. Only the given properties change; "categories" and "tags" replace the current ones when given. '
                . 'Posts written with the EasyBlog composer cannot have their text rewritten here.',
                [
                    'properties' => [
                        'id'       => ['type' => 'integer'],
                        'title'    => ['type' => 'string'],
                        'category' => $ref + ['description' => 'New main blog category.'],
                    ] + $writable,
                    'required'             => ['id'],
                    'additionalProperties' => false,
                ],
                [self::class, 'update'],
                'Update blog post',
                Tool::SCOPE_WRITE,
                'easyblog',
                true,
                $can('core.manage')
            ),
            new Tool(
                'set_blog_post_state',
                'Publish, unpublish, archive or draft EasyBlog posts, and/or change their featured flag.',
                [
                    'properties' => [
                        'ids'      => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => self::MAX_BULK],
                        'state'    => ['type' => 'string', 'enum' => ['published', 'unpublished', 'draft', 'pending', 'archived']],
                        'featured' => ['type' => 'boolean'],
                    ],
                    'required'             => ['ids'],
                    'additionalProperties' => false,
                ],
                [self::class, 'setState'],
                'Change blog post state',
                Tool::SCOPE_PUBLISH,
                'easyblog',
                true,
                $can('core.manage')
            ),
            new Tool(
                'trash_blog_posts',
                'Move EasyBlog posts to the trash (restorable in the back-end).',
                ['properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => self::MAX_BULK]], 'required' => ['ids'], 'additionalProperties' => false],
                [self::class, 'trash'],
                'Trash blog posts',
                Tool::SCOPE_DELETE,
                'easyblog',
                true,
                $can('core.manage')
            ),
        ];
    }

    // ------------------------------------------------------------------ Read

    public static function listPosts(array $args, ToolContext $ctx): array
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)->from($db->quoteName('#__easyblog_post', 'p'));

        $filters = EbData::applyFilters($ctx, $query, $args);
        $total   = (int) $db->setQuery((clone $query)->select('COUNT(*)'))->loadResult();
        $limit   = $ctx->limit($args['limit'] ?? null);
        $offset  = max(0, (int) ($args['offset'] ?? 0));
        $dir     = strtolower($args['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $query->select('p.id, p.title, p.permalink, p.published, p.state, p.created, p.modified, p.publish_up, p.hits, p.language, p.created_by, p.intro, p.content, p.doctype')
            ->select('u.name AS author_name')
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = p.created_by')
            ->order(self::orderColumn($args['order_by'] ?? 'created') . ' ' . $dir . ', p.id ' . $dir)
            ->setLimit($limit, $offset);

        $rows       = $db->setQuery($query)->loadObjectList();
        $ids        = array_map(fn ($r) => (int) $r->id, $rows);
        $categories = EbData::postCategories($ctx, $ids);
        $comments   = EbData::commentCounts($ctx, $ids);
        $featured   = EbData::featured($ctx, $ids);

        $items = array_map(fn ($r) => [
            'id'         => (int) $r->id,
            'title'      => $r->title,
            'state'      => EbData::stateName($r),
            'categories' => array_column($categories[(int) $r->id] ?? [], 'title'),
            'author'     => $r->author_name,
            'created'    => Resolver::localDate($ctx, $r->created),
            'hits'       => (int) $r->hits,
            'comments'   => (int) ($comments[(int) $r->id] ?? 0),
            'featured'   => \in_array((int) $r->id, $featured, true),
            'language'   => $r->language,
            'excerpt'    => EbData::excerpt($r),
        ], $rows);

        return ['total' => $total, 'offset' => $offset, 'has_more' => $offset + \count($items) < $total, 'filters' => $filters ?: null, 'posts' => $items];
    }

    public static function getPost(array $args, ToolContext $ctx): array
    {
        $id   = (int) $args['id'];
        $post = EbData::post($ctx, $id);

        // Drafts, scheduled, pending and archived posts are only for callers who could edit them
        // in the back end; the message stays the same as for a missing post
        if (EbData::stateName($post) !== 'published' && !EbData::canSeeUnpublished($ctx)) {
            throw new ToolException(\sprintf('Blog post %d not found.', $id));
        }

        $meta = EbData::meta($ctx, $id);

        return [
            'id'           => $id,
            'title'        => $post->title,
            'alias'        => $post->permalink,
            'state'        => EbData::stateName($post),
            'featured'     => (bool) EbData::featured($ctx, [$id]),
            'categories'   => EbData::postCategories($ctx, [$id])[$id] ?? [],
            'tags'         => EbData::postTags($ctx, [$id])[$id] ?? [],
            'author'       => ['id' => (int) $post->created_by, 'name' => $post->author_name, 'username' => $post->author_username],
            'created'      => Resolver::localDate($ctx, $post->created),
            'modified'     => Resolver::localDate($ctx, $post->modified),
            'publish_up'   => Resolver::localDate($ctx, $post->publish_up),
            'publish_down' => Resolver::localDate($ctx, $post->publish_down),
            'language'     => $post->language,
            'hits'         => (int) $post->hits,
            'comments'     => (int) (EbData::commentCounts($ctx, [$id])[$id] ?? 0),
            'editor'       => $post->doctype === 'ebd' ? 'composer (text not writable through MCP)' : 'classic HTML',
            'seo'          => array_filter([
                'title'       => $meta['title'] ?? null,
                'keywords'    => $meta['keywords'] ?? null,
                'description' => $meta['description'] ?? null,
                'canonical'   => $meta['canonical'] ?? null,
            ]),
            'intro'        => $post->intro,
            'content'      => $post->content,
            'url'          => rtrim(Uri::root(), '/') . '/index.php?option=com_easyblog&view=entry&id=' . $id,
            'edit_url'     => rtrim(Uri::root(), '/') . '/administrator/index.php?option=com_easyblog&view=composer&uid=' . $id,
        ];
    }

    public static function listCategories(array $args, ToolContext $ctx): array
    {
        return ['categories' => array_values(array_map(fn ($c) => [
            'id'         => $c->id,
            'title'      => $c->title,
            'alias'      => $c->alias,
            'parent_id'  => (int) $c->parent_id,
            'published'  => (int) $c->published === 1,
            'private'    => (bool) $c->private,
            'post_count' => (int) $c->post_count,
        ], EbData::categories($ctx)))];
    }

    public static function listTags(array $args, ToolContext $ctx): array
    {
        $db    = $ctx->db;
        $count = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__easyblog_post_tag', 'pt'))
            ->where('pt.tag_id = t.id')
            ->where('pt.isrevision IS NULL');

        $query = $db->getQuery(true)->from($db->quoteName('#__easyblog_tag', 't'));

        if (($args['query'] ?? '') !== '') {
            $search = '%' . Resolver::likeValue(mb_strtolower($args['query'])) . '%';
            $query->where('(LOWER(t.title) LIKE :s1 OR LOWER(t.alias) LIKE :s2)')->bind(':s1', $search)->bind(':s2', $search);
        }

        $total  = (int) $db->setQuery((clone $query)->select('COUNT(*)'))->loadResult();
        $limit  = $ctx->limit($args['limit'] ?? null, 100);
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $query->select('t.id, t.title, t.alias, t.published')->select('(' . $count . ') AS post_count')->order('t.title')->setLimit($limit, $offset);

        $tags = array_map(fn ($t) => [
            'id'         => (int) $t->id,
            'title'      => $t->title,
            'published'  => (int) $t->published === 1,
            'post_count' => (int) $t->post_count,
        ], $db->setQuery($query)->loadObjectList());

        return ['total' => $total, 'offset' => $offset, 'has_more' => $offset + \count($tags) < $total, 'tags' => $tags];
    }

    public static function overview(array $args, ToolContext $ctx): array
    {
        $db   = $ctx->db;
        $base = fn () => $db->getQuery(true)->from($db->quoteName('#__easyblog_post', 'p'))->where('p.published <> ' . EbData::BLANK);

        $byState = [];

        foreach ($db->setQuery($base()->select('p.published, p.state, COUNT(*) AS n, SUM(p.hits) AS hits')->group('p.published, p.state'))->loadObjectList() as $row) {
            $name = EbData::stateName($row);
            $byState[$name]['count'] = ($byState[$name]['count'] ?? 0) + (int) $row->n;
            $byState[$name]['hits']  = ($byState[$name]['hits'] ?? 0) + (int) $row->hits;
        }

        $recent = [];

        foreach (['last_7_days' => 7, 'last_30_days' => 30, 'last_365_days' => 365] as $key => $days) {
            $since        = Factory::getDate('-' . $days . ' days')->toSql();
            $recent[$key] = (int) $db->setQuery($base()->select('COUNT(*)')->where('p.state = 0')->where('p.created >= :since')->bind(':since', $since))->loadResult();
        }

        $list = function (string $select, string $order) use ($ctx, $db, $base) {
            $query = $base()->select('p.id, p.title, p.hits, p.created')->select($select)->where('p.state = 0')->where('p.published = 1')->order($order)->setLimit(10);

            return array_map(fn ($r) => array_filter([
                'id'       => (int) $r->id,
                'title'    => $r->title,
                'hits'     => (int) $r->hits,
                'comments' => isset($r->comments) ? (int) $r->comments : null,
                'created'  => Resolver::localDate($ctx, $r->created),
            ], fn ($v) => $v !== null), $db->setQuery($query)->loadObjectList());
        };

        $commentCount = '(SELECT COUNT(*) FROM ' . $db->quoteName('#__easyblog_comment') . ' cc WHERE cc.post_id = p.id AND cc.published = 1) AS comments';

        $categories = array_values(array_filter(array_map(fn ($c) => (int) $c->post_count ? ['title' => $c->title, 'posts' => (int) $c->post_count] : null, EbData::categories($ctx))));
        usort($categories, fn ($a, $b) => $b['posts'] <=> $a['posts']);

        return [
            'posts'          => ['by_state' => $byState],
            'created'        => $recent,
            'categories'     => \array_slice($categories, 0, 15),
            'tags'           => (int) $db->setQuery($db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__easyblog_tag')))->loadResult(),
            'most_read'      => $list('p.hits', 'p.hits DESC'),
            'most_commented' => $list($commentCount, 'comments DESC'),
            'most_recent'    => $list('p.hits', 'p.created DESC'),
            'time_zone'      => $ctx->app->get('offset', 'UTC'),
        ];
    }

    public static function stats(array $args, ToolContext $ctx): array
    {
        $db      = $ctx->db;
        $groupBy = $args['group_by'];
        $query   = $db->getQuery(true)->from($db->quoteName('#__easyblog_post', 'p'));
        $filters = EbData::applyFilters($ctx, $query, $args);
        $totals  = $db->setQuery((clone $query)->select('COUNT(*) AS n, SUM(p.hits) AS hits'))->loadObject();
        $periods = ['year' => 'Y', 'quarter' => 'Q', 'month' => 'Y-m', 'week' => 'o-\WW', 'day' => 'Y-m-d'];

        if (isset($periods[$groupBy])) {
            $groups = self::groupByPeriod($ctx, $query, EbData::dateColumn($args['date_field'] ?? 'created'), $periods[$groupBy]);
        } else {
            switch ($groupBy) {
                case 'category':
                    $query->join('INNER', $db->quoteName('#__easyblog_post_category', 'gpc'), 'gpc.post_id = p.id')
                        ->join('INNER', $db->quoteName('#__easyblog_category', 'gc'), 'gc.id = gpc.category_id')
                        ->select('gc.id AS k, gc.title AS label')->group('gc.id, gc.title');
                    break;

                case 'tag':
                    $query->join('INNER', $db->quoteName('#__easyblog_post_tag', 'gpt'), 'gpt.post_id = p.id AND gpt.isrevision IS NULL')
                        ->join('INNER', $db->quoteName('#__easyblog_tag', 'gt'), 'gt.id = gpt.tag_id')
                        ->select('gt.id AS k, gt.title AS label')->group('gt.id, gt.title');
                    break;

                case 'author':
                    $query->join('LEFT', $db->quoteName('#__users', 'gu'), 'gu.id = p.created_by')
                        ->select('p.created_by AS k, gu.name AS label')->group('p.created_by, gu.name');
                    break;

                case 'state':
                    $query->select('CONCAT(p.published, ":", p.state) AS k')->group('p.published, p.state');
                    break;

                case 'featured':
                    $query->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__easyblog_featured') . ' gf WHERE gf.type = ' . $db->quote('post')
                        . ' AND gf.content_id = p.id) AS k')->group('k');
                    break;

                default:
                    $query->select('p.language AS k')->group('p.language');
            }

            $query->select('COUNT(DISTINCT p.id) AS n, SUM(p.hits) AS hits');

            $groups = array_map(function ($row) use ($groupBy) {
                $label = $row->label ?? null;
                $key   = $row->k;

                if ($groupBy === 'state') {
                    [$published, $state] = array_map('intval', explode(':', (string) $row->k));
                    $key   = EbData::stateName((object) ['published' => $published, 'state' => $state]);
                    $label = null;
                } elseif ($groupBy === 'featured') {
                    $key   = (int) $row->k ? 'featured' : 'not featured';
                    $label = null;
                }

                return ['key' => is_numeric($key) ? $key + 0 : $key, 'label' => $label, 'count' => (int) $row->n, 'hits' => (int) $row->hits];
            }, $db->setQuery($query)->loadObjectList());
        }

        $sort = $args['sort'] ?? (isset($periods[$groupBy]) ? 'key' : 'count');

        usort($groups, fn ($a, $b) => match ($sort) {
            'key'   => strcmp((string) $a['key'], (string) $b['key']),
            'hits'  => $b['hits'] <=> $a['hits'],
            default => $b['count'] <=> $a['count'],
        });

        return [
            'group_by'    => $groupBy,
            'filters'     => $filters ?: null,
            'total_posts' => (int) $totals->n,
            'total_hits'  => (int) $totals->hits,
            'group_count' => \count($groups),
            'groups'      => \array_slice($groups, 0, max(1, min((int) ($args['limit'] ?? 100), 1000))),
            'note'        => \in_array($groupBy, ['category', 'tag'], true) ? 'Posts in several categories or with several tags are counted once per group.' : null,
        ];
    }

    // ------------------------------------------------------------------ Write

    public static function create(array $args, ToolContext $ctx): array
    {
        $id = EbWriter::create($ctx, $args);

        return ['result' => 'created'] + self::getPost(['id' => $id], $ctx);
    }

    public static function update(array $args, ToolContext $ctx): array
    {
        $id = (int) $args['id'];

        EbWriter::update($ctx, $id, $args);

        return ['result' => 'updated'] + self::getPost(['id' => $id], $ctx);
    }

    public static function setState(array $args, ToolContext $ctx): array
    {
        if (!isset($args['state']) && !isset($args['featured'])) {
            throw new ToolException('Pass "state" and/or "featured".');
        }

        if (($args['state'] ?? null) === 'published' && !$ctx->params->get('allow_publish', 1)) {
            throw new ToolException('Publishing is disabled on this MCP server.');
        }

        return ['results' => EbWriter::setState($ctx, $args['ids'], $args['state'] ?? null, $args['featured'] ?? null)];
    }

    public static function trash(array $args, ToolContext $ctx): array
    {
        return ['results' => EbWriter::setState($ctx, $args['ids'], 'trashed', null)];
    }

    /**
     * Sort column for list_blog_posts. A map, so the argument never reaches the SQL as it was given.
     */
    private static function orderColumn(string $orderBy): string
    {
        return match ($orderBy) {
            'modified'   => 'p.modified',
            'publish_up' => 'p.publish_up',
            'hits'       => 'p.hits',
            'title'      => 'p.title',
            'id'         => 'p.id',
            default      => 'p.created',
        };
    }

    private static function groupByPeriod(ToolContext $ctx, \Joomla\Database\QueryInterface $query, string $dateField, string $format): array
    {
        $tz     = new \DateTimeZone($ctx->app->get('offset', 'UTC'));
        $groups = [];

        $ctx->db->setQuery($query->select($dateField . ' AS d, p.hits')->where($dateField . ' IS NOT NULL'));

        foreach ($ctx->db->getIterator() as $row) {
            if (!$row->d || str_starts_with($row->d, '0000')) {
                continue;
            }

            $date = (new \DateTimeImmutable($row->d, new \DateTimeZone('UTC')))->setTimezone($tz);
            $key  = $format === 'Q' ? $date->format('Y') . '-Q' . (int) ceil($date->format('n') / 3) : $date->format($format);

            $groups[$key] ??= ['key' => $key, 'label' => null, 'count' => 0, 'hits' => 0];
            $groups[$key]['count']++;
            $groups[$key]['hits'] += (int) $row->hits;
        }

        return array_values($groups);
    }
}
