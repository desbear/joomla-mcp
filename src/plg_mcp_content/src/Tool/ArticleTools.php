<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_content
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Content\Tool;

use Gss\Component\Mcp\Administrator\Helper\ModelHelper;
use Gss\Component\Mcp\Administrator\Helper\Resolver;
use Gss\Component\Mcp\Administrator\Mcp\Tool;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

\defined('_JEXEC') or die;

/**
 * Article tools: search, read, create, update, publish, tag, custom fields.
 *
 * All writes go through the core com_content ArticleModel, so ACL, versions, workflows,
 * content plugins, Smart Search and the User Actions Log behave as in the back-end.
 *
 * @since  0.1.0
 */
final class ArticleTools
{
    public const STATES = ['published' => 1, 'unpublished' => 0, 'archived' => 2, 'trashed' => -2];

    private const READMORE = '<hr id="system-readmore">';

    /** Most articles one bulk call may touch: each one is a full model save. */
    public const MAX_BULK = 100;

    /**
     * @return Tool[]
     */
    public static function tools(): array
    {
        $ref     = ['type' => ['integer', 'string']];
        $refList = ['type' => 'array', 'items' => $ref];

        $writable = [
            'text'                => ['type' => 'string', 'description' => 'Full article HTML. Use <hr id="system-readmore"> to split intro text and full text.'],
            'introtext'           => ['type' => 'string', 'description' => 'Intro text HTML (alternative to "text").'],
            'fulltext'            => ['type' => 'string', 'description' => 'Full text HTML shown after "read more" (alternative to "text").'],
            'alias'               => ['type' => 'string', 'description' => 'URL alias; generated from the title when empty.'],
            'featured'            => ['type' => 'boolean'],
            'tags'                => $refList + ['description' => 'Tag IDs, titles or paths.'],
            'create_missing_tags' => ['type' => 'boolean', 'description' => 'Create tags that do not exist yet (default false).'],
            'fields'              => ['type' => 'object', 'description' => 'Custom field values keyed by field name (see list_fields). Multi-value fields take an array.'],
            'language'            => ['type' => 'string', 'description' => 'Language tag such as "zh-TW", or "*" for all languages.'],
            'access'              => ['type' => 'integer', 'description' => 'Access level ID (1 = Public).'],
            'metadesc'            => ['type' => 'string', 'description' => 'Meta description.'],
            'metakey'             => ['type' => 'string', 'description' => 'Meta keywords.'],
            'publish_up'          => ['type' => 'string', 'description' => 'Start publishing, site time zone, YYYY-MM-DD HH:MM.'],
            'publish_down'        => ['type' => 'string', 'description' => 'Finish publishing, site time zone, YYYY-MM-DD HH:MM.'],
            'note'                => ['type' => 'string', 'description' => 'Internal note shown in the back-end list.'],
        ];

        $canCreate = fn (ToolContext $c) => $c->user->authorise('core.create', 'com_content');
        $canEdit   = fn (ToolContext $c) => $c->user->authorise('core.edit', 'com_content') || $c->user->authorise('core.edit.own', 'com_content');
        $canState  = fn (ToolContext $c) => $c->user->authorise('core.edit.state', 'com_content');
        $canRead   = fn (ToolContext $c) => $c->user->authorise('core.manage', 'com_content')
            || $c->user->authorise('core.edit', 'com_content')
            || $c->user->authorise('core.edit.own', 'com_content')
            || $c->user->authorise('core.create', 'com_content');

        return [
            new Tool(
                'search_articles',
                'Search and list articles with filters, sorting and paging. Returns summary rows (no article text); use get_article for the full content. '
                . 'Sort by hits to find the most read articles.',
                [
                    'properties' => [
                        'query'                  => ['type' => 'string', 'description' => 'Text to search for.'],
                        'search_in'              => ['type' => 'string', 'enum' => ['title', 'content', 'all'], 'description' => 'Where to search (default title, which also matches alias and note).'],
                        'category'               => $ref + ['description' => 'Category ID, title or path.'],
                        'include_subcategories'  => ['type' => 'boolean', 'description' => 'Default true.'],
                        'state'                  => ['type' => 'string', 'enum' => ['published', 'unpublished', 'archived', 'trashed', 'any'], 'description' => 'Default: everything except trashed.'],
                        'featured'               => ['type' => 'boolean'],
                        'tag'                    => $ref + ['description' => 'Tag ID, title or path.'],
                        'author'                 => $ref + ['description' => 'User ID, username or name.'],
                        'language'               => ['type' => 'string'],
                        'date_field'             => ['type' => 'string', 'enum' => ['created', 'modified', 'publish_up'], 'description' => 'Date used by date_from/date_to (default created).'],
                        'date_from'              => ['type' => 'string', 'description' => 'YYYY-MM-DD, site time zone.'],
                        'date_to'                => ['type' => 'string', 'description' => 'YYYY-MM-DD, inclusive.'],
                        'order_by'               => ['type' => 'string', 'enum' => ['created', 'modified', 'publish_up', 'hits', 'title', 'id'], 'description' => 'Default created.'],
                        'direction'              => ['type' => 'string', 'enum' => ['asc', 'desc'], 'description' => 'Default desc.'],
                        'limit'                  => ['type' => 'integer', 'description' => 'Default 20.', 'minimum' => 1],
                        'offset'                 => ['type' => 'integer', 'minimum' => 0],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'search'],
                'Search articles',
                Tool::SCOPE_READ,
                'content',
                false,
                $canRead
            ),
            new Tool(
                'get_article',
                'Get one article with its full text, tags, custom field values, metadata and URLs.',
                [
                    'properties'           => ['id' => ['type' => 'integer']],
                    'required'             => ['id'],
                    'additionalProperties' => false,
                ],
                [self::class, 'get'],
                'Get article',
                Tool::SCOPE_READ,
                'content',
                false,
                $canRead
            ),
            new Tool(
                'create_article',
                'Create an article. Unless "state" is given, the configured default state is used (normally unpublished) so the user can review it.',
                [
                    'properties' => [
                        'title'    => ['type' => 'string'],
                        'category' => $ref + ['description' => 'Category ID, title or path.'],
                        'state'    => ['type' => 'string', 'enum' => ['published', 'unpublished']],
                    ] + $writable,
                    'required'             => ['title', 'category'],
                    'additionalProperties' => false,
                ],
                [self::class, 'create'],
                'Create article',
                Tool::SCOPE_WRITE,
                'content',
                false,
                $canCreate
            ),
            new Tool(
                'update_article',
                'Update an existing article. Only the given properties change. "tags" replaces all tags (use set_article_tags to add or remove); '
                . '"fields" only changes the listed custom fields. Use set_article_state to publish or unpublish.',
                [
                    'properties' => [
                        'id'       => ['type' => 'integer'],
                        'title'    => ['type' => 'string'],
                        'category' => $ref + ['description' => 'Move to this category (ID, title or path).'],
                    ] + $writable,
                    'required'             => ['id'],
                    'additionalProperties' => false,
                ],
                [self::class, 'update'],
                'Update article',
                Tool::SCOPE_WRITE,
                'content',
                true,
                $canEdit
            ),
            new Tool(
                'set_article_tags',
                'Add, remove or replace tags on one or more articles.',
                [
                    'properties' => [
                        'ids'                 => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => self::MAX_BULK, 'description' => 'Article IDs.'],
                        'tags'                => $refList + ['description' => 'Tag IDs, titles or paths.'],
                        'mode'                => ['type' => 'string', 'enum' => ['add', 'remove', 'replace'], 'description' => 'Default add.'],
                        'create_missing_tags' => ['type' => 'boolean'],
                    ],
                    'required'             => ['ids', 'tags'],
                    'additionalProperties' => false,
                ],
                [self::class, 'setTags'],
                'Set article tags',
                Tool::SCOPE_WRITE,
                'content',
                true,
                $canEdit
            ),
            new Tool(
                'set_article_fields',
                'Set custom field values of an article. Call list_fields first to learn the field names, types and allowed options. '
                . 'Pass null or an empty string to clear a value.',
                [
                    'properties' => [
                        'id'     => ['type' => 'integer'],
                        'fields' => ['type' => 'object', 'description' => 'Values keyed by field name.'],
                    ],
                    'required'             => ['id', 'fields'],
                    'additionalProperties' => false,
                ],
                [self::class, 'setFields'],
                'Set article custom fields',
                Tool::SCOPE_WRITE,
                'content',
                true,
                $canEdit
            ),
            new Tool(
                'set_article_state',
                'Publish, unpublish or archive articles, and/or change their featured flag.',
                [
                    'properties' => [
                        'ids'      => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => self::MAX_BULK, 'description' => 'Article IDs.'],
                        'state'    => ['type' => 'string', 'enum' => ['published', 'unpublished', 'archived']],
                        'featured' => ['type' => 'boolean'],
                    ],
                    'required'             => ['ids'],
                    'additionalProperties' => false,
                ],
                [self::class, 'setState'],
                'Change article state',
                Tool::SCOPE_PUBLISH,
                'content',
                true,
                $canState
            ),
            new Tool(
                'trash_articles',
                'Move articles to the trash. They can be restored from the back-end; nothing is permanently deleted.',
                [
                    'properties' => [
                        'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => self::MAX_BULK, 'description' => 'Article IDs.'],
                    ],
                    'required'             => ['ids'],
                    'additionalProperties' => false,
                ],
                [self::class, 'trash'],
                'Trash articles',
                Tool::SCOPE_DELETE,
                'content',
                true,
                $canState
            ),
        ];
    }

    public static function search(array $args, ToolContext $ctx): array
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->from($db->quoteName('#__content', 'a'))
            ->join('LEFT', $db->quoteName('#__categories', 'c'), 'c.id = a.catid')
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = a.created_by');

        self::applyVisibility($ctx, $query);

        if (($args['query'] ?? '') !== '') {
            $in     = $args['search_in'] ?? 'title';
            $cols   = ['a.title', 'a.alias', 'a.note'];

            if ($in === 'content') {
                $cols = ['a.introtext', 'a.fulltext'];
            } elseif ($in === 'all') {
                $cols = array_merge($cols, ['a.introtext', 'a.fulltext']);
            }

            Resolver::applyLike($query, $cols, $args['query'], 'search');
        }

        if (isset($args['category'])) {
            $category = Resolver::category($ctx, $args['category']);
            $ids      = ($args['include_subcategories'] ?? true) ? Resolver::categoryTree($ctx, $category->id) : [$category->id];
            $query->whereIn('a.catid', $ids);
        }

        $state = $args['state'] ?? null;

        // Unpublished, archived and trashed articles are only visible to someone who could edit
        // them in the back end; without this any account granted "Use MCP Server" could read every
        // draft on the site
        if (!self::canSeeUnpublished($ctx)) {
            if ($state !== null && $state !== 'published') {
                throw new ToolException('You are not allowed to list anything other than published articles.');
            }

            $query->where('a.state = 1');
        } elseif ($state === null) {
            $query->where('a.state <> -2');
        } elseif ($state !== 'any') {
            $query->where('a.state = ' . (int) self::STATES[$state]);
        }

        if (isset($args['featured'])) {
            $query->where('a.featured = ' . ($args['featured'] ? 1 : 0));
        }

        if (isset($args['tag'])) {
            $tagIds = Resolver::tags($ctx, [$args['tag']]);
            $query->join('INNER', $db->quoteName('#__contentitem_tag_map', 'tm'), 'tm.content_item_id = a.id AND tm.type_alias = ' . $db->quote('com_content.article'))
                ->whereIn('tm.tag_id', $tagIds);
        }

        if (isset($args['author'])) {
            $author = Resolver::userId($ctx, $args['author']);
            $query->where('a.created_by = :author')->bind(':author', $author, ParameterType::INTEGER);
        }

        if (!empty($args['language'])) {
            $query->where('a.language = :language')->bind(':language', $args['language']);
        }

        $dateField = match ($args['date_field'] ?? 'created') {
            'modified'   => 'a.modified',
            'publish_up' => 'a.publish_up',
            default      => 'a.created',
        };

        if (!empty($args['date_from'])) {
            $from = Resolver::sqlDate($ctx, $args['date_from']);
            $query->where($dateField . ' >= :from')->bind(':from', $from);
        }

        if (!empty($args['date_to'])) {
            $to = Resolver::sqlDate($ctx, $args['date_to'], true);
            $query->where($dateField . ' <= :to')->bind(':to', $to);
        }

        $total = (int) $db->setQuery((clone $query)->select('COUNT(DISTINCT a.id)'))->loadResult();

        $limit  = $ctx->limit($args['limit'] ?? null);
        $offset = max(0, (int) ($args['offset'] ?? 0));
        // Mapped rather than interpolated, so this is not an injection even if the schema's enum
        // is ever dropped
        $order = match ($args['order_by'] ?? 'created') {
            'modified'   => 'a.modified',
            'publish_up' => 'a.publish_up',
            'hits'       => 'a.hits',
            'title'      => 'a.title',
            'id'         => 'a.id',
            default      => 'a.created',
        };
        $dir = strtolower($args['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $query->select('DISTINCT a.id, a.title, a.alias, a.state, a.catid, c.title AS category_title, a.created_by, u.name AS author_name, '
            . 'a.created, a.modified, a.publish_up, a.hits, a.featured, a.language')
            ->order($order . ' ' . $dir . ', a.id ' . $dir)
            ->setLimit($limit, $offset);

        $items = array_map(fn ($row) => [
            'id'         => (int) $row->id,
            'title'      => $row->title,
            'alias'      => $row->alias,
            'state'      => self::stateName((int) $row->state),
            'category'   => ['id' => (int) $row->catid, 'title' => $row->category_title],
            'author'     => ['id' => (int) $row->created_by, 'name' => $row->author_name],
            'created'    => Resolver::localDate($ctx, $row->created),
            'modified'   => Resolver::localDate($ctx, $row->modified),
            'publish_up' => Resolver::localDate($ctx, $row->publish_up),
            'hits'       => (int) $row->hits,
            'featured'   => (bool) $row->featured,
            'language'   => $row->language,
        ], $db->setQuery($query)->loadObjectList());

        return [
            'total'    => $total,
            'offset'   => $offset,
            'limit'    => $limit,
            'has_more' => $offset + \count($items) < $total,
            'items'    => $items,
        ];
    }

    public static function get(array $args, ToolContext $ctx): array
    {
        $db    = $ctx->db;
        $id    = (int) $args['id'];
        $query = $db->getQuery(true)
            ->select('a.*, c.title AS category_title, c.path AS category_path, u.name AS author_name, m.name AS modified_by_name, v.title AS access_title')
            ->from($db->quoteName('#__content', 'a'))
            ->join('LEFT', $db->quoteName('#__categories', 'c'), 'c.id = a.catid')
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = a.created_by')
            ->join('LEFT', $db->quoteName('#__users', 'm'), 'm.id = a.modified_by')
            ->join('LEFT', $db->quoteName('#__viewlevels', 'v'), 'v.id = a.access')
            ->where('a.id = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        self::applyVisibility($ctx, $query);

        $row = $db->setQuery($query)->loadObject();

        if (!$row) {
            throw new ToolException(\sprintf('Article %d not found (or not accessible).', $id));
        }

        $metadata = json_decode((string) $row->metadata, true) ?: [];

        return [
            'id'          => (int) $row->id,
            'title'       => $row->title,
            'alias'       => $row->alias,
            'state'       => self::stateName((int) $row->state),
            'featured'    => (bool) $row->featured,
            'category'    => ['id' => (int) $row->catid, 'title' => $row->category_title, 'path' => $row->category_path],
            'author'      => ['id' => (int) $row->created_by, 'name' => $row->author_name, 'alias' => $row->created_by_alias],
            'modified_by' => ['id' => (int) $row->modified_by, 'name' => $row->modified_by_name],
            'created'     => Resolver::localDate($ctx, $row->created),
            'modified'    => Resolver::localDate($ctx, $row->modified),
            'publish_up'  => Resolver::localDate($ctx, $row->publish_up),
            'publish_down' => Resolver::localDate($ctx, $row->publish_down),
            'language'    => $row->language,
            'access'      => ['id' => (int) $row->access, 'title' => $row->access_title],
            'hits'        => (int) $row->hits,
            'version'     => (int) $row->version,
            'note'        => $row->note,
            'metadesc'    => $row->metadesc,
            'metakey'     => $row->metakey,
            'robots'      => $metadata['robots'] ?? '',
            'images'      => array_filter(json_decode((string) $row->images, true) ?: []),
            'tags'        => self::articleTags($ctx, $id),
            'fields'      => self::articleFields($row),
            'introtext'   => $row->introtext,
            'fulltext'    => $row->fulltext,
            'url'         => self::siteUrl($ctx, $row),
            'edit_url'    => rtrim(Uri::root(), '/') . '/administrator/index.php?option=com_content&task=article.edit&id=' . $id,
        ];
    }

    public static function create(array $args, ToolContext $ctx): array
    {
        $category = Resolver::category($ctx, $args['category']);

        $data = [
            'id'       => 0,
            'title'    => $args['title'],
            'catid'    => $category->id,
            'language' => $args['language'] ?? '*',
            'state'    => isset($args['state']) ? self::STATES[$args['state']] : (int) $ctx->params->get('new_article_state', 0),
        ];

        if ($data['state'] === 1 && !$ctx->params->get('allow_publish', 1)) {
            throw new ToolException('Publishing is disabled on this MCP server; create the article unpublished.');
        }

        $data = self::applyChanges($ctx, $data, $args, ['introtext' => '', 'fulltext' => '']);

        [$id, $warning] = self::save($ctx, $data);

        return ['result' => 'created'] + ($warning ? ['warning' => $warning] : []) + self::get(['id' => $id], $ctx);
    }

    public static function update(array $args, ToolContext $ctx): array
    {
        $id   = (int) $args['id'];
        $data = self::load($ctx, $id);

        if (isset($args['title'])) {
            $data['title'] = $args['title'];
        }

        if (isset($args['category'])) {
            $data['catid'] = Resolver::category($ctx, $args['category'])->id;
        }

        $data = self::applyChanges($ctx, $data, $args, $data);

        [, $warning] = self::save($ctx, $data);

        return ['result' => 'updated'] + ($warning ? ['warning' => $warning] : []) + self::get(['id' => $id], $ctx);
    }

    public static function setTags(array $args, ToolContext $ctx): array
    {
        $mode    = $args['mode'] ?? 'add';
        $tags    = Resolver::tags($ctx, $args['tags'], (bool) ($args['create_missing_tags'] ?? false) && $mode !== 'remove');
        $results = [];

        foreach (array_unique(array_map('intval', $args['ids'])) as $id) {
            try {
                $data    = self::load($ctx, $id);
                $current = array_map('intval', $data['tags']);

                $data['tags'] = match ($mode) {
                    'replace' => $tags,
                    'remove'  => array_values(array_diff($current, $tags)),
                    default   => array_values(array_unique(array_merge($current, $tags), SORT_REGULAR)),
                };

                [, $warning] = self::save($ctx, $data);

                // "#new#" tags are created by the first save; reuse them for the next articles
                $tags = array_map(
                    fn ($t) => \is_string($t) && str_starts_with($t, '#new#') ? (Resolver::tags($ctx, [substr($t, 5)])[0] ?? $t) : $t,
                    $tags
                );

                $results[] = ['id' => $id, 'ok' => true, 'tags' => self::articleTags($ctx, $id)] + ($warning ? ['warning' => $warning] : []);
            } catch (ToolException $e) {
                $results[] = ['id' => $id, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return ['results' => $results];
    }

    public static function setFields(array $args, ToolContext $ctx): array
    {
        $id   = (int) $args['id'];
        $data = self::load($ctx, $id);
        $data = self::applyChanges($ctx, $data, ['fields' => $args['fields']], $data);

        [, $warning] = self::save($ctx, $data);

        return ['result' => 'updated', 'id' => $id] + ($warning ? ['warning' => $warning] : []) + ['fields' => self::get(['id' => $id], $ctx)['fields']];
    }

    public static function setState(array $args, ToolContext $ctx): array
    {
        if (!isset($args['state']) && !isset($args['featured'])) {
            throw new ToolException('Pass "state" and/or "featured".');
        }

        $ids = self::checkIds($ctx, $args['ids']);

        if (isset($args['state'])) {
            if ($args['state'] === 'published' && !$ctx->params->get('allow_publish', 1)) {
                throw new ToolException('Publishing is disabled on this MCP server.');
            }

            self::publish($ctx, $ids, self::STATES[$args['state']]);
        }

        if (isset($args['featured'])) {
            // ArticleModel::featured() runs a plain UPDATE and checks nothing: core relies on the
            // controller to authorise first, and that controller is what this tool replaces
            foreach ($ids as $id) {
                if (!$ctx->user->authorise('core.edit.state', 'com_content.article.' . $id)) {
                    throw new ToolException(\sprintf('You are not allowed to change the featured flag of article %d.', $id));
                }
            }

            $model = self::model($ctx);

            if (!$model->featured($ids, $args['featured'] ? 1 : 0)) {
                throw new ToolException('Could not change the featured flag: ' . $model->getError());
            }
        }

        return ['results' => self::summaries($ctx, $ids)];
    }

    public static function trash(array $args, ToolContext $ctx): array
    {
        $ids = self::checkIds($ctx, $args['ids']);

        self::publish($ctx, $ids, -2);

        return ['results' => self::summaries($ctx, $ids)];
    }

    /**
     * Restrict a query on #__content AS a to what this user and server may see.
     */
    /**
     * Whether the caller may see content that is not published. Joomla ties this to editing
     * permissions rather than to view levels.
     */
    public static function canSeeUnpublished(ToolContext $ctx): bool
    {
        $user = $ctx->user;

        return $user->authorise('core.edit.state', 'com_content')
            || $user->authorise('core.edit', 'com_content')
            || $user->authorise('core.manage', 'com_content');
    }

    public static function applyVisibility(ToolContext $ctx, QueryInterface $query): void
    {
        Resolver::applyArticleVisibility($ctx, $query);
    }

    public static function stateName(int $state): string
    {
        return array_search($state, self::STATES, true) ?: (string) $state;
    }

    /**
     * Merge tool arguments into model data.
     *
     * @param   array  $existing  Current introtext/fulltext, used when only one part changes
     */
    private static function applyChanges(ToolContext $ctx, array $data, array $args, array $existing): array
    {
        foreach (['alias', 'language', 'metadesc', 'metakey', 'note'] as $key) {
            if (isset($args[$key])) {
                $data[$key] = $args[$key];
            }
        }

        if (isset($args['access'])) {
            $data['access'] = (int) $args['access'];
        }

        if (isset($args['featured'])) {
            $data['featured'] = $args['featured'] ? 1 : 0;
        }

        foreach (['publish_up', 'publish_down'] as $key) {
            if (\array_key_exists($key, $args)) {
                $data[$key] = empty($args[$key]) ? null : Resolver::sqlDate($ctx, $args[$key]);
            }
        }

        if (isset($args['text'])) {
            $data['articletext'] = $args['text'];
        } elseif (isset($args['introtext']) || isset($args['fulltext'])) {
            $intro = $args['introtext'] ?? $existing['introtext'] ?? '';
            $full  = $args['fulltext'] ?? $existing['fulltext'] ?? '';

            $data['articletext'] = trim($full) === '' ? $intro : $intro . self::READMORE . $full;
        }

        if (isset($args['tags'])) {
            $data['tags'] = Resolver::tags($ctx, $args['tags'], (bool) ($args['create_missing_tags'] ?? false));
        }

        if (isset($args['fields'])) {
            $data['com_fields'] = self::fieldValues($data, (array) $args['fields']);
        }

        return $data;
    }

    /**
     * Validate custom field names against the fields assigned to the article's category.
     */
    private static function fieldValues(array $data, array $values): array
    {
        $item   = (object) ['id' => (int) ($data['id'] ?? 0), 'catid' => (int) $data['catid'], 'language' => $data['language'] ?? '*'];
        $fields = [];

        foreach (FieldsHelper::getFields('com_content.article', $item) as $field) {
            $fields[$field->name] = $field;
        }

        $result = [];

        foreach ($values as $name => $value) {
            if (!isset($fields[$name])) {
                throw new ToolException(\sprintf(
                    'Custom field "%s" does not exist for this article\'s category. Available fields: %s.',
                    $name,
                    $fields ? implode(', ', array_keys($fields)) : '(none)'
                ));
            }

            $result[$name] = $value ?? '';
        }

        return $result;
    }

    /**
     * Load an article as model data (the same shape the back-end edit form uses).
     */
    private static function load(ToolContext $ctx, int $id): array
    {
        $model = self::model($ctx, $id);
        $item  = $model->getItem($id);

        if (!$item || empty($item->id)) {
            throw new ToolException(\sprintf('Article %d not found.', $id));
        }

        Resolver::assertCategoryAllowed($ctx, (int) $item->catid);

        $data = method_exists($item, 'getProperties') ? $item->getProperties() : get_object_vars($item);

        // getItem() returns a TagsHelper object; the model expects a list of IDs
        $data['tags'] = array_map('intval', array_column(self::articleTags($ctx, $id), 'id'));

        foreach (['attribs', 'metadata', 'urls', 'images'] as $key) {
            if (isset($data[$key]) && \is_object($data[$key])) {
                $data[$key] = (array) $data[$key];
            }
        }

        return $data;
    }

    /**
     * Validate and save through the core model.
     *
     * @return  array{0: int, 1: string|null}  The article ID and a warning, if a plugin failed after the article was stored
     */
    private static function save(ToolContext $ctx, array $data): array
    {
        $id    = (int) ($data['id'] ?? 0);
        $asset = $id ? 'com_content.article.' . $id : 'com_content.category.' . (int) $data['catid'];

        if ($id) {
            $own = (int) ($data['created_by'] ?? 0) === (int) $ctx->user->id && $ctx->user->authorise('core.edit.own', $asset);

            if (!$own && !$ctx->user->authorise('core.edit', $asset)) {
                throw new ToolException(\sprintf('You are not allowed to edit article %d.', $id));
            }
        } elseif (!$ctx->user->authorise('core.create', $asset)) {
            throw new ToolException('You are not allowed to create articles in this category.');
        }

        $model = self::model($ctx, $id);
        $form  = $model->getForm($data, false);

        if (!$form) {
            throw new ToolException('Could not load the article form: ' . $model->getError());
        }

        $valid = $model->validate($form, self::toUserTime($ctx, $data));

        if ($valid === false) {
            $errors = array_map(fn ($e) => $e instanceof \Throwable ? $e->getMessage() : (string) $e, $model->getErrors());

            throw new ToolException('Validation failed: ' . implode(' ', $errors));
        }

        // validate() drops values it does not know; keep our custom field values and tags.
        // Tag IDs must be strings: Joomla's TagsHelper treats an integer as the title of a new tag.
        if (isset($data['com_fields'])) {
            $valid['com_fields'] = $data['com_fields'];
        }

        if (isset($data['tags'])) {
            $valid['tags'] = array_map('strval', $data['tags']);
        }

        // The table splits articletext into introtext/fulltext, but stale values would overwrite that
        if (isset($valid['articletext'])) {
            unset($valid['introtext'], $valid['fulltext']);
        }

        $before = self::saveMarker($ctx, $id);

        if ($model->save($valid)) {
            return [$id ?: (int) $model->getState($model->getName() . '.id'), null];
        }

        $error = (string) $model->getError();

        // Plugins run after the article is stored; if one of them fails the article is saved anyway.
        // Report that as a warning, otherwise the model would retry and create duplicates.
        $savedId = self::findSaved($ctx, $id, $before, $valid);

        if ($savedId) {
            return [$savedId, 'The article was saved, but a plugin reported an error afterwards: ' . $error];
        }

        throw new ToolException('Save failed: ' . $error);
    }

    /**
     * The article form treats dates as the user's local time and converts them to UTC ("user_utc"
     * filter). Our dates are UTC, so convert them to local time first, as the edit form does when it
     * displays them; otherwise every save would shift them by the time zone offset.
     */
    private static function toUserTime(ToolContext $ctx, array $data): array
    {
        $tz = new \DateTimeZone($ctx->user->getParam('timezone', $ctx->app->get('offset', 'UTC')) ?: 'UTC');

        foreach (['created', 'modified', 'publish_up', 'publish_down', 'featured_up', 'featured_down'] as $key) {
            if (empty($data[$key]) || str_starts_with((string) $data[$key], '0000')) {
                continue;
            }

            $data[$key] = (new \DateTimeImmutable((string) $data[$key], new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d H:i:s');
        }

        return $data;
    }

    /**
     * State before saving: the highest article ID for new articles, the modified date for existing ones.
     */
    private static function saveMarker(ToolContext $ctx, int $id): string
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)->from($db->quoteName('#__content'));

        if ($id) {
            $query->select('CONCAT(modified, version)')->where('id = ' . $id);
        } else {
            $query->select('MAX(id)');
        }

        return (string) $db->setQuery($query)->loadResult();
    }

    private static function findSaved(ToolContext $ctx, int $id, string $before, array $data): int
    {
        if ($id) {
            return self::saveMarker($ctx, $id) !== $before ? $id : 0;
        }

        $db    = $ctx->db;
        $max   = (int) $before;
        $catid = (int) $data['catid'];
        $user  = (int) $ctx->user->id;
        $query = $db->getQuery(true)
            ->select('id')
            ->from($db->quoteName('#__content'))
            ->where('id > :max')
            ->where('catid = :catid')
            ->where('created_by = :user')
            ->where('title = :title')
            ->bind(':max', $max, ParameterType::INTEGER)
            ->bind(':catid', $catid, ParameterType::INTEGER)
            ->bind(':user', $user, ParameterType::INTEGER)
            ->bind(':title', $data['title'])
            ->order('id DESC');

        return (int) $db->setQuery($query, 0, 1)->loadResult();
    }

    /**
     * @param  int[]  $ids
     */
    private static function publish(ToolContext $ctx, array $ids, int $state): void
    {
        // AdminModel::publish() refuses items the user may not change, but it reports that through
        // the message queue and returns false without setting an error, so checking getError()
        // would report a refusal as success. Authorise up front instead.
        foreach ($ids as $id) {
            if (!$ctx->user->authorise('core.edit.state', 'com_content.article.' . $id)) {
                throw new ToolException(\sprintf('You are not allowed to change the state of article %d.', $id));
            }
        }

        $model = self::model($ctx);

        if (!$model->publish($ids, $state)) {
            $error = (string) $model->getError();

            throw new ToolException('Could not change the state' . ($error !== '' ? ': ' . $error : '. The site refused the change.'));
        }
    }

    /**
     * Check that articles exist and are inside the allowed categories.
     *
     * @return int[]
     */
    private static function checkIds(ToolContext $ctx, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (!$ids) {
            throw new ToolException('No article IDs given.');
        }

        $db    = $ctx->db;
        $query = $db->getQuery(true)->select('a.id')->from($db->quoteName('#__content', 'a'))->whereIn('a.id', $ids);
        self::applyVisibility($ctx, $query);

        $found   = array_map('intval', $db->setQuery($query)->loadColumn());
        $missing = array_diff($ids, $found);

        if ($missing) {
            throw new ToolException('Articles not found or not accessible: ' . implode(', ', $missing) . '.');
        }

        return $ids;
    }

    private static function summaries(ToolContext $ctx, array $ids): array
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select('id, title, state, featured')
            ->from($db->quoteName('#__content'))
            ->whereIn('id', $ids);

        return array_map(fn ($r) => [
            'id'       => (int) $r->id,
            'title'    => $r->title,
            'state'    => self::stateName((int) $r->state),
            'featured' => (bool) $r->featured,
        ], $db->setQuery($query)->loadObjectList());
    }

    private static function articleTags(ToolContext $ctx, int $id): array
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select('t.id, t.title, t.path')
            ->from($db->quoteName('#__contentitem_tag_map', 'm'))
            ->join('INNER', $db->quoteName('#__tags', 't'), 't.id = m.tag_id')
            ->where('m.type_alias = ' . $db->quote('com_content.article'))
            ->where('m.content_item_id = :id')
            ->bind(':id', $id, ParameterType::INTEGER)
            ->order('t.lft');

        return array_map(fn ($t) => ['id' => (int) $t->id, 'title' => $t->title, 'path' => $t->path], $db->setQuery($query)->loadObjectList());
    }

    private static function articleFields(object $row): array
    {
        $fields = [];

        foreach (FieldsHelper::getFields('com_content.article', $row) as $field) {
            $value = $field->rawvalue;

            if (\is_string($value) && $value !== '' && ($value[0] === '[' || $value[0] === '{')) {
                $value = json_decode($value, true) ?? $value;
            }

            $fields[] = [
                'name'  => $field->name,
                'label' => $field->label,
                'type'  => $field->type,
                'value' => $value === '' ? null : $value,
            ];
        }

        return $fields;
    }

    private static function siteUrl(ToolContext $ctx, object $row): ?string
    {
        try {
            $link = 'index.php?option=com_content&view=article&id=' . (int) $row->id . ':' . $row->alias . '&catid=' . (int) $row->catid;

            if ($row->language && $row->language !== '*') {
                $link .= '&lang=' . $row->language;
            }

            return Route::link('site', $link, false, Route::TLS_IGNORE, true);
        } catch (\Throwable) {
            return $ctx->siteUrl() . 'index.php?option=com_content&view=article&id=' . (int) $row->id . '&catid=' . (int) $row->catid;
        }
    }

    /**
     * @return \Joomla\Component\Content\Administrator\Model\ArticleModel
     */
    private static function model(ToolContext $ctx, int $id = 0): object
    {
        return ModelHelper::admin($ctx, 'com_content', 'Article', ['id' => $id, 'a_id' => $id]);
    }
}
