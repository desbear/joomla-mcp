<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_flexicontent
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Flexicontent\Tool;

use Gss\Component\Mcp\Administrator\Helper\Resolver;
use Gss\Component\Mcp\Administrator\Mcp\Tool;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * FLEXIcontent versions of the article tools. When FLEXIcontent is installed they replace the core
 * article tools (same names), so every query, statistic and change goes through FLEXIcontent's
 * data: content types, multiple categories, FLEXIcontent tags and custom fields.
 *
 * @since  0.4.0
 */
final class FcTools
{
    private const READMORE = '<hr id="system-readmore" />';

    /** Most items one bulk call may touch: each one is a full FLEXIcontent save. */
    public const MAX_BULK = 100;

    /**
     * @return Tool[]
     */
    public static function tools(): array
    {
        $ref     = ['type' => ['integer', 'string']];
        $refList = ['type' => 'array', 'items' => $ref];
        $note    = ' (FLEXIcontent items)';

        $writable = [
            'text'                => ['type' => 'string', 'description' => 'Main text HTML. Use <hr id="system-readmore"> to split intro and full text.'],
            'introtext'           => ['type' => 'string', 'description' => 'Intro text HTML (alternative to "text").'],
            'fulltext'            => ['type' => 'string', 'description' => 'Text after "read more" (alternative to "text").'],
            'categories'          => $refList + ['description' => 'Additional categories besides the main one (ID, title or path).'],
            'tags'                => $refList + ['description' => 'FLEXIcontent tags (ID or name). Replaces the current tags.'],
            'create_missing_tags' => ['type' => 'boolean', 'description' => 'Create FLEXIcontent tags that do not exist yet (default false).'],
            'fields'              => ['type' => 'object', 'description' => 'Custom field values keyed by field name (see list_fields). List fields take option values or labels; multi-value fields take an array.'],
            'alias'               => ['type' => 'string'],
            'featured'            => ['type' => 'boolean'],
            'language'            => ['type' => 'string', 'description' => 'Language tag, or "*" for all languages.'],
            'access'              => ['type' => 'integer', 'description' => 'Access level ID (1 = Public).'],
            'metadesc'            => ['type' => 'string'],
            'metakey'             => ['type' => 'string'],
            'publish_up'          => ['type' => 'string', 'description' => 'Site time zone, YYYY-MM-DD HH:MM.'],
            'publish_down'        => ['type' => 'string', 'description' => 'Site time zone, YYYY-MM-DD HH:MM.'],
        ];

        // These tools replace the core article tools, so they have to carry the same permission
        // callbacks: without them a read-only user is offered create_article and trash_articles
        $canCreate = fn (ToolContext $c) => $c->user->authorise('core.create', 'com_content');
        $canEdit   = fn (ToolContext $c) => $c->user->authorise('core.edit', 'com_content') || $c->user->authorise('core.edit.own', 'com_content');
        $canState  = fn (ToolContext $c) => $c->user->authorise('core.edit.state', 'com_content');
        $canRead   = fn (ToolContext $c) => $c->user->authorise('core.manage', 'com_content')
            || $c->user->authorise('core.edit', 'com_content')
            || $c->user->authorise('core.edit.own', 'com_content')
            || $c->user->authorise('core.create', 'com_content');
        $canTag    = fn (ToolContext $c) => $c->user->authorise('flexicontent.createtags', 'com_flexicontent') || $c->user->authorise('core.admin');

        $read  = fn (string $name, string $desc, array $schema, string $handler, string $title) => new Tool($name, $desc, $schema, [self::class, $handler], $title, Tool::SCOPE_READ, 'flexicontent', false, $canRead);
        $write = fn (string $name, string $desc, array $schema, string $handler, string $title, ?callable $available = null, string $scope = Tool::SCOPE_WRITE) => new Tool($name, $desc, $schema, [self::class, $handler], $title, $scope, 'flexicontent', true, $available);

        return [
            $read('list_types', 'List the FLEXIcontent content types with their number of items and custom fields.', ['properties' => [], 'additionalProperties' => false], 'listTypes', 'List content types'),
            $read(
                'list_fields',
                'List the FLEXIcontent fields of a content type (or all types): name, type, required, allowed options and whether MCP can write them.',
                ['properties' => ['type' => $ref + ['description' => 'Content type ID or name. Default: all fields.']], 'additionalProperties' => false],
                'listFields',
                'List fields' . $note
            ),
            $read(
                'search_articles',
                'Search FLEXIcontent items by text, content type, category (main or additional), FLEXIcontent tag, author, state, dates and '
                . 'custom field values, with sorting and paging. Returns summary rows; use get_article for fields and text.',
                [
                    'properties' => FcData::filterSchema() + [
                        'order_by'  => ['type' => 'string', 'enum' => ['created', 'modified', 'publish_up', 'hits', 'title', 'id']],
                        'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                        'limit'     => ['type' => 'integer', 'description' => 'Default 20.'],
                        'offset'    => ['type' => 'integer'],
                    ],
                    'additionalProperties' => false,
                ],
                'search',
                'Search items' . $note
            ),
            $read(
                'get_article',
                'Get one FLEXIcontent item: type, all categories, FLEXIcontent tags, every custom field value, text, metadata and URLs.',
                ['properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
                'get',
                'Get item' . $note
            ),
            $read(
                'list_tags',
                'List FLEXIcontent tags with the number of items using each tag.',
                ['properties' => ['query' => ['type' => 'string'], 'limit' => ['type' => 'integer'], 'offset' => ['type' => 'integer']], 'additionalProperties' => false],
                'listTags',
                'List tags' . $note
            ),
            $read(
                'list_categories',
                'List categories with FLEXIcontent item counts (items whose main category it is, and all items assigned to it).',
                ['properties' => ['query' => ['type' => 'string'], 'parent' => $ref], 'additionalProperties' => false],
                'listCategories',
                'List categories' . $note
            ),
            $read(
                'content_overview',
                'Overview of the FLEXIcontent items: counts by state and content type, featured, recent activity, most read and most recent items.',
                ['properties' => [], 'additionalProperties' => false],
                'overview',
                'Content overview' . $note
            ),
            $read(
                'content_stats',
                'Count FLEXIcontent items (and sum hits) grouped by a dimension: content type, category (main or all assignments), '
                . 'FLEXIcontent tag, author, state, language, featured, a period, or the values of a custom field (group_by=field with "field"). '
                . 'Accepts the same filters as search_articles.',
                [
                    'properties' => [
                        'group_by'      => ['type' => 'string', 'enum' => ['type', 'category', 'tag', 'author', 'state', 'language', 'featured', 'field', 'year', 'quarter', 'month', 'week', 'day']],
                        'field'         => ['type' => 'string', 'description' => 'Field name, for group_by=field.'],
                        'category_mode' => ['type' => 'string', 'enum' => ['main', 'all'], 'description' => 'For group_by=category: main category only (default) or every assigned category.'],
                        'sort'          => ['type' => 'string', 'enum' => ['count', 'hits', 'key']],
                        'limit'         => ['type' => 'integer', 'description' => 'Maximum number of groups (default 100).'],
                    ] + FcData::filterSchema(),
                    'required'             => ['group_by'],
                    'additionalProperties' => false,
                ],
                'stats',
                'Content statistics' . $note
            ),
            $write(
                'create_article',
                'Create a FLEXIcontent item of a content type, with main and additional categories, FLEXIcontent tags and custom field values. '
                . 'Call list_fields for the type first. Unless "state" is given the configured default for new content is used (normally unpublished).',
                [
                    'properties' => [
                        'title'    => ['type' => 'string'],
                        'type'     => $ref + ['description' => 'Content type ID or name (see list_types). Optional when there is only one type.'],
                        'category' => $ref + ['description' => 'Main category (ID, title or path).'],
                        'state'    => ['type' => 'string', 'enum' => ['published', 'unpublished', 'draft']],
                    ] + $writable,
                    'required'             => ['title', 'category'],
                    'additionalProperties' => false,
                ],
                'create',
                'Create item' . $note,
                $canCreate
            ),
            $write(
                'update_article',
                'Update a FLEXIcontent item. Only the given properties change: custom fields not listed in "fields" keep their values, '
                . '"tags" and "categories" replace the current ones when given. Use set_article_state to publish.',
                [
                    'properties' => [
                        'id'       => ['type' => 'integer'],
                        'title'    => ['type' => 'string'],
                        'category' => $ref + ['description' => 'New main category.'],
                    ] + $writable,
                    'required'             => ['id'],
                    'additionalProperties' => false,
                ],
                'update',
                'Update item' . $note,
                $canEdit
            ),
            $write(
                'set_article_fields',
                'Set custom field values of a FLEXIcontent item; other fields keep their values. Pass null or "" to clear a field.',
                ['properties' => ['id' => ['type' => 'integer'], 'fields' => ['type' => 'object']], 'required' => ['id', 'fields'], 'additionalProperties' => false],
                'setFields',
                'Set item fields' . $note,
                $canEdit
            ),
            $write(
                'set_article_tags',
                'Add, remove or replace FLEXIcontent tags on items.',
                [
                    'properties' => [
                        'ids'                 => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => self::MAX_BULK],
                        'tags'                => $refList,
                        'mode'                => ['type' => 'string', 'enum' => ['add', 'remove', 'replace'], 'description' => 'Default add.'],
                        'create_missing_tags' => ['type' => 'boolean'],
                    ],
                    'required'             => ['ids', 'tags'],
                    'additionalProperties' => false,
                ],
                'setTags',
                'Set item tags' . $note,
                $canEdit
            ),
            $write(
                'create_tag',
                'Create a FLEXIcontent tag.',
                ['properties' => ['title' => ['type' => 'string']], 'required' => ['title'], 'additionalProperties' => false],
                'createTag',
                'Create tag' . $note,
                $canTag
            ),
            $write(
                'set_article_state',
                'Publish, unpublish or archive FLEXIcontent items, and/or change their featured flag.',
                [
                    'properties' => [
                        'ids'      => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => self::MAX_BULK],
                        'state'    => ['type' => 'string', 'enum' => ['published', 'unpublished', 'archived']],
                        'featured' => ['type' => 'boolean'],
                    ],
                    'required'             => ['ids'],
                    'additionalProperties' => false,
                ],
                'setState',
                'Change item state' . $note,
                $canState,
                Tool::SCOPE_PUBLISH
            ),
            $write(
                'trash_articles',
                'Move FLEXIcontent items to the trash (restorable in the back-end).',
                ['properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => self::MAX_BULK]], 'required' => ['ids'], 'additionalProperties' => false],
                'trash',
                'Trash items' . $note,
                $canState,
                Tool::SCOPE_DELETE
            ),
        ];
    }

    // ------------------------------------------------------------------ Read

    public static function listTypes(array $args, ToolContext $ctx): array
    {
        $db     = $ctx->db;
        $counts = $db->setQuery(
            $db->getQuery(true)
                ->select('ie.type_id, COUNT(*) AS n')
                ->from($db->quoteName('#__flexicontent_items_ext', 'ie'))
                ->join('INNER', $db->quoteName('#__content', 'a'), 'a.id = ie.item_id')
                ->where('a.state <> -2')
                ->group('ie.type_id')
        )->loadAssocList('type_id', 'n');

        $types = [];

        foreach (FcData::types($ctx) as $type) {
            $fields = FcData::fields($ctx, $type->id);

            $types[] = [
                'id'            => $type->id,
                'name'          => $type->name,
                'alias'         => $type->alias,
                'published'     => (int) $type->published === 1,
                'item_count'    => (int) ($counts[$type->id] ?? 0),
                'custom_fields' => array_values(array_map(fn ($f) => $f->name, array_filter($fields, [FcData::class, 'holdsValues']))),
                'default_category' => !empty($type->attribs['catid_default']) ? (int) $type->attribs['catid_default'] : null,
            ];
        }

        return ['types' => $types];
    }

    public static function listFields(array $args, ToolContext $ctx): array
    {
        $type    = isset($args['type']) ? FcData::type($ctx, $args['type']) : null;
        $fields  = FcData::fields($ctx, $type?->id);
        $members = FcData::groupMembers(FcData::fields($ctx));
        $result  = [];

        foreach ($fields as $field) {
            if (FcData::holdsValues($field)) {
                $result[] = FcData::describe($field, $members[$field->id] ?? null);
            }
        }

        return [
            'type'   => $type ? ['id' => $type->id, 'name' => $type->name] : null,
            'fields' => $result,
            'core_properties' => 'Title, text, categories, tags, state, language, dates and meta data are set with the tool\'s own arguments.',
            'note'   => 'Fields with "writable": false (images, files, relations, field groups and their members, ...) can be read but not set through MCP.',
        ];
    }

    public static function search(array $args, ToolContext $ctx): array
    {
        $db    = $ctx->db;
        $query = self::baseQuery($ctx);

        $filters = FcData::applyFilters($ctx, $query, $args);
        $total   = (int) $db->setQuery((clone $query)->select('COUNT(*)'))->loadResult();
        $limit   = $ctx->limit($args['limit'] ?? null);
        $offset  = max(0, (int) ($args['offset'] ?? 0));
        $dir     = strtolower($args['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $query->select('a.id, a.title, a.alias, a.state, a.catid, c.title AS category_title, a.created_by, u.name AS author_name, a.created, a.modified, a.publish_up, a.hits, a.featured, a.language, ie.type_id')
            ->join('LEFT', $db->quoteName('#__categories', 'c'), 'c.id = a.catid')
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = a.created_by')
            ->order(self::orderColumn($args['order_by'] ?? 'created') . ' ' . $dir . ', a.id ' . $dir)
            ->setLimit($limit, $offset);

        $rows  = $db->setQuery($query)->loadObjectList();
        $types = FcData::types($ctx);

        $items = array_map(fn ($r) => [
            'id'         => (int) $r->id,
            'title'      => $r->title,
            'type'       => $types[(int) $r->type_id]->name ?? (int) $r->type_id,
            'state'      => FcData::stateName((int) $r->state),
            'category'   => ['id' => (int) $r->catid, 'title' => $r->category_title],
            'author'     => $r->author_name,
            'created'    => Resolver::localDate($ctx, $r->created),
            'modified'   => Resolver::localDate($ctx, $r->modified),
            'hits'       => (int) $r->hits,
            'featured'   => (bool) $r->featured,
            'language'   => $r->language,
        ], $rows);

        return ['total' => $total, 'offset' => $offset, 'has_more' => $offset + \count($items) < $total, 'filters' => $filters ?: null, 'items' => $items];
    }

    public static function get(array $args, ToolContext $ctx): array
    {
        $item = FcData::item($ctx, (int) $args['id']);

        // The back end would not show an item that is not published to this caller either; the
        // message stays the same as for a missing item so it does not confirm that the item exists
        if ((int) $item->state !== FcData::STATES['published'] && !FcData::canSeeUnpublished($ctx)) {
            throw new ToolException(\sprintf('Item %d not found (or not accessible).', (int) $args['id']));
        }

        return self::itemDetails($ctx, $item);
    }

    /**
     * The full representation of an item, also used to report back what a write changed.
     */
    private static function itemDetails(ToolContext $ctx, object $item): array
    {
        $types  = FcData::types($ctx);
        $fields = FcData::fields($ctx, $item->type_id);
        $values = FcData::values($ctx, [$item->id])[$item->id] ?? [];
        $db     = $ctx->db;
        $author = $db->setQuery($db->getQuery(true)->select('name')->from($db->quoteName('#__users'))->where('id = ' . (int) $item->created_by))->loadResult();

        $custom = [];

        foreach ($fields as $name => $field) {
            if (!FcData::holdsValues($field)) {
                continue;
            }

            $value = $values[$name] ?? [];

            $custom[] = [
                'name'  => $name,
                'label' => FcData::text($field->label),
                'type'  => $field->field_type,
                'value' => \count($value) === 0 ? null : (\count($value) === 1 ? $value[0] : $value),
            ];
        }

        return [
            'id'           => (int) $item->id,
            'title'        => $item->title,
            'alias'        => $item->alias,
            'type'         => ['id' => $item->type_id, 'name' => $types[$item->type_id]->name ?? null],
            'state'        => FcData::stateName((int) $item->state),
            'featured'     => (bool) $item->featured,
            'categories'   => FcData::categories($ctx, [$item->id])[$item->id] ?? [],
            'tags'         => FcData::itemTags($ctx, [$item->id])[$item->id] ?? [],
            'fields'       => $custom,
            'author'       => ['id' => (int) $item->created_by, 'name' => $author],
            'created'      => Resolver::localDate($ctx, $item->created),
            'modified'     => Resolver::localDate($ctx, $item->modified),
            'publish_up'   => Resolver::localDate($ctx, $item->publish_up),
            'publish_down' => Resolver::localDate($ctx, $item->publish_down),
            'language'     => $item->language,
            'access'       => (int) $item->access,
            'hits'         => (int) $item->hits,
            'version'      => (int) $item->version,
            'metadesc'     => $item->metadesc,
            'metakey'      => $item->metakey,
            'introtext'    => $item->introtext,
            'fulltext'     => $item->fulltext,
            'url'          => rtrim(Uri::root(), '/') . '/index.php?option=com_flexicontent&view=item&id=' . (int) $item->id . '&cid=' . (int) $item->catid,
            'edit_url'     => rtrim(Uri::root(), '/') . '/administrator/index.php?option=com_flexicontent&task=items.edit&cid=' . (int) $item->id,
        ];
    }

    public static function listTags(array $args, ToolContext $ctx): array
    {
        $db    = $ctx->db;
        $count = $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__flexicontent_tags_item_relations', 'r'))->where('r.tid = t.id');
        $query = $db->getQuery(true)->from($db->quoteName('#__flexicontent_tags', 't'))->where('t.published IN (0, 1)');

        if (($args['query'] ?? '') !== '') {
            $search = '%' . Resolver::likeValue(mb_strtolower($args['query'])) . '%';
            $query->where('(LOWER(t.name) LIKE :s1 OR LOWER(t.alias) LIKE :s2)')->bind(':s1', $search)->bind(':s2', $search);
        }

        $total  = (int) $db->setQuery((clone $query)->select('COUNT(*)'))->loadResult();
        $limit  = $ctx->limit($args['limit'] ?? null, 100);
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $query->select('t.id, t.name, t.alias, t.published')->select('(' . $count . ') AS item_count')->order('t.name')->setLimit($limit, $offset);

        $tags = array_map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name, 'alias' => $t->alias, 'published' => (int) $t->published === 1, 'item_count' => (int) $t->item_count], $db->setQuery($query)->loadObjectList());

        return ['total' => $total, 'offset' => $offset, 'has_more' => $offset + \count($tags) < $total, 'tags' => $tags];
    }

    public static function listCategories(array $args, ToolContext $ctx): array
    {
        $db   = $ctx->db;
        $main = $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__content', 'a'))->where('a.catid = c.id')->where('a.state <> -2');
        $all  = $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__flexicontent_cats_item_relations', 'r'))
            ->join('INNER', $db->quoteName('#__content', 'b'), 'b.id = r.itemid')->where('r.catid = c.id')->where('b.state <> -2');

        $query = $db->getQuery(true)
            ->select('c.id, c.title, c.path, c.level, c.parent_id, c.published, c.language')
            ->select('(' . $main . ') AS main_count, (' . $all . ') AS assigned_count')
            ->from($db->quoteName('#__categories', 'c'))
            ->where('c.extension = ' . $db->quote('com_content'))
            ->where('c.published IN (0, 1)')
            ->order('c.lft');

        if (isset($args['parent'])) {
            $query->whereIn('c.id', Resolver::categoryTree($ctx, Resolver::category($ctx, $args['parent'])->id));
        }

        if (($args['query'] ?? '') !== '') {
            $search = '%' . Resolver::likeValue(mb_strtolower($args['query'])) . '%';
            $query->where('(LOWER(c.title) LIKE :s1 OR LOWER(c.path) LIKE :s2)')->bind(':s1', $search)->bind(':s2', $search);
        }

        if (($allowed = Resolver::allowedCategoryIds($ctx)) !== null) {
            $query->whereIn('c.id', $allowed ?: [0]);
        }

        return ['categories' => array_map(fn ($c) => [
            'id'                  => (int) $c->id,
            'title'               => $c->title,
            'path'                => $c->path,
            'level'               => (int) $c->level,
            'parent_id'           => (int) $c->parent_id,
            'published'           => (int) $c->published === 1,
            'items_main_category' => (int) $c->main_count,
            'items_assigned'      => (int) $c->assigned_count,
        ], $db->setQuery($query)->loadObjectList())];
    }

    public static function overview(array $args, ToolContext $ctx): array
    {
        $db    = $ctx->db;
        $types = FcData::types($ctx);
        $base  = function () use ($ctx) {
            $query = self::baseQuery($ctx);
            Resolver::applyArticleVisibility($ctx, $query);

            return $query;
        };

        $byState = [];

        foreach ($db->setQuery($base()->select('a.state, COUNT(*) AS n, SUM(a.hits) AS hits')->group('a.state'))->loadObjectList() as $row) {
            $byState[FcData::stateName((int) $row->state)] = ['count' => (int) $row->n, 'hits' => (int) $row->hits];
        }

        $byType = [];

        foreach ($db->setQuery($base()->select('ie.type_id, COUNT(*) AS n')->where('a.state <> -2')->group('ie.type_id'))->loadObjectList() as $row) {
            $byType[$types[(int) $row->type_id]->name ?? (string) $row->type_id] = (int) $row->n;
        }

        $recent = [];

        foreach (['last_7_days' => 7, 'last_30_days' => 30, 'last_365_days' => 365] as $key => $days) {
            $since        = Factory::getDate('-' . $days . ' days')->toSql();
            $recent[$key] = [
                'created'  => (int) $db->setQuery($base()->select('COUNT(*)')->where('a.state <> -2')->where('a.created >= :since')->bind(':since', $since))->loadResult(),
                'modified' => (int) $db->setQuery($base()->select('COUNT(*)')->where('a.state <> -2')->where('a.modified >= :since')->bind(':since', $since))->loadResult(),
            ];
        }

        $list = fn (string $order) => array_map(fn ($r) => [
            'id'      => (int) $r->id,
            'title'   => $r->title,
            'type'    => $types[(int) $r->type_id]->name ?? null,
            'hits'    => (int) $r->hits,
            'created' => Resolver::localDate($ctx, $r->created),
        ], $db->setQuery($base()->select('a.id, a.title, a.hits, a.created, ie.type_id')->where('a.state = 1')->order($order)->setLimit(10))->loadObjectList());

        return [
            'items' => [
                'by_state' => $byState,
                'by_type'  => $byType,
                'featured_published' => (int) $db->setQuery($base()->select('COUNT(*)')->where('a.state = 1')->where('a.featured = 1'))->loadResult(),
            ],
            'activity'    => $recent,
            'types'       => \count($types),
            'tags'        => (int) $db->setQuery($db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__flexicontent_tags'))->where('published = 1'))->loadResult(),
            'most_read'   => $list('a.hits DESC'),
            'most_recent' => $list('a.created DESC'),
            'time_zone'   => $ctx->app->get('offset', 'UTC'),
        ];
    }

    public static function stats(array $args, ToolContext $ctx): array
    {
        $db      = $ctx->db;
        $groupBy = $args['group_by'];
        $query   = self::baseQuery($ctx);
        $filters = FcData::applyFilters($ctx, $query, $args);
        $totals  = $db->setQuery((clone $query)->select('COUNT(*) AS n, SUM(a.hits) AS hits'))->loadObject();
        $periods = ['year' => 'Y', 'quarter' => 'Q', 'month' => 'Y-m', 'week' => 'o-\WW', 'day' => 'Y-m-d'];
        $types   = FcData::types($ctx);
        $labels  = [];

        if (isset($periods[$groupBy])) {
            $groups = self::groupByPeriod($ctx, $query, FcData::dateColumn($args['date_field'] ?? 'created'), $periods[$groupBy]);
        } else {
            switch ($groupBy) {
                case 'type':
                    $query->select('ie.type_id AS k')->group('ie.type_id');
                    $labels = array_map(fn ($t) => $t->name, $types);
                    break;

                case 'category':
                    if (($args['category_mode'] ?? 'main') === 'all') {
                        $query->join('INNER', $db->quoteName('#__flexicontent_cats_item_relations', 'gr'), 'gr.itemid = a.id')
                            ->join('INNER', $db->quoteName('#__categories', 'gc'), 'gc.id = gr.catid')
                            ->select('gc.id AS k, gc.path AS label')->group('gc.id, gc.path');
                    } else {
                        $query->join('LEFT', $db->quoteName('#__categories', 'gc'), 'gc.id = a.catid')
                            ->select('a.catid AS k, gc.path AS label')->group('a.catid, gc.path');
                    }
                    break;

                case 'tag':
                    $query->join('INNER', $db->quoteName('#__flexicontent_tags_item_relations', 'gt'), 'gt.itemid = a.id')
                        ->join('INNER', $db->quoteName('#__flexicontent_tags', 't'), 't.id = gt.tid')
                        ->select('t.id AS k, t.name AS label')->group('t.id, t.name');
                    break;

                case 'author':
                    $query->join('LEFT', $db->quoteName('#__users', 'gu'), 'gu.id = a.created_by')
                        ->select('a.created_by AS k, gu.name AS label')->group('a.created_by, gu.name');
                    break;

                case 'field':
                    if (empty($args['field'])) {
                        throw new ToolException('group_by=field needs "field" (a field name, see list_fields).');
                    }

                    $field = FcData::field($ctx, $args['field']);
                    $query->join('INNER', $db->quoteName('#__flexicontent_fields_item_relations', 'gf'), 'gf.item_id = a.id AND gf.field_id = ' . $field->id)
                        ->select('gf.value AS k')->group('gf.value');

                    foreach (FcData::options($field) as $option) {
                        $labels[$option['value']] = $option['label'];
                    }
                    break;

                default:
                    $column = ['state' => 'a.state', 'language' => 'a.language', 'featured' => 'a.featured'][$groupBy];
                    $query->select($column . ' AS k')->group($column);
            }

            // Items are counted once per group; a field value or tag can appear several times per item
            $query->select('COUNT(DISTINCT a.id) AS n, SUM(a.hits) AS hits');

            $groups = array_map(function ($row) use ($groupBy, $labels) {
                $label = $row->label ?? ($labels[$row->k] ?? null);

                if ($groupBy === 'state') {
                    $label = FcData::stateName((int) $row->k);
                } elseif ($groupBy === 'featured') {
                    $label = (int) $row->k ? 'featured' : 'not featured';
                }

                return ['key' => is_numeric($row->k) ? $row->k + 0 : $row->k, 'label' => $label, 'count' => (int) $row->n, 'hits' => (int) $row->hits];
            }, $db->setQuery($query)->loadObjectList());
        }

        $sort = $args['sort'] ?? (isset($periods[$groupBy]) ? 'key' : 'count');

        usort($groups, fn ($a, $b) => match ($sort) {
            'key'   => strcmp((string) $a['key'], (string) $b['key']),
            'hits'  => $b['hits'] <=> $a['hits'],
            default => $b['count'] <=> $a['count'],
        });

        return [
            'group_by'       => $groupBy === 'field' ? 'field:' . $args['field'] : $groupBy,
            'filters'        => $filters ?: null,
            'total_items'    => (int) $totals->n,
            'total_hits'     => (int) $totals->hits,
            'group_count'    => \count($groups),
            'groups'         => \array_slice($groups, 0, max(1, min((int) ($args['limit'] ?? 100), 1000))),
            'note'           => \in_array($groupBy, ['tag', 'field'], true) || ($groupBy === 'category' && ($args['category_mode'] ?? '') === 'all')
                ? 'Items with several values are counted once per value, so group counts can add up to more than total_items.' : null,
        ];
    }

    // ------------------------------------------------------------------ Write

    public static function create(array $args, ToolContext $ctx): array
    {
        $types = FcData::types($ctx);

        if (isset($args['type'])) {
            $type = FcData::type($ctx, $args['type']);
        } elseif (\count($types) === 1) {
            $type = reset($types);
        } else {
            throw new ToolException('Pass "type". Content types: ' . implode(', ', array_map(fn ($t) => $t->id . ' (' . $t->name . ')', $types)) . '.');
        }

        $state = isset($args['state']) ? FcData::STATES[$args['state']] : (int) $ctx->params->get('new_article_state', 0);

        if ($state === 1 && !$ctx->params->get('allow_publish', 1)) {
            throw new ToolException('Publishing is disabled on this MCP server; create the item unpublished.');
        }

        $main = Resolver::category($ctx, $args['category']);

        $data = [
            'id'       => 0,
            'type_id'  => $type->id,
            'title'    => $args['title'],
            'catid'    => $main->id,
            'cid'      => [$main->id],
            'state'    => $state,
            'language' => $args['language'] ?? '*',
            'access'   => (int) ($args['access'] ?? 1),
            'text'     => '',
            'custom'   => [],
        ];

        $data = self::applyChanges($ctx, $data, $args, $type->id, ['introtext' => '', 'fulltext' => '', 'custom' => []]);
        $id   = FcWriter::save($ctx, $data, $type->id);

        return ['result' => 'created'] + self::itemDetails($ctx, FcData::item($ctx, $id));
    }

    public static function update(array $args, ToolContext $ctx): array
    {
        $id = (int) $args['id'];

        FcWriter::save($ctx, self::changes($ctx, $id, $args), null);

        return ['result' => 'updated'] + self::itemDetails($ctx, FcData::item($ctx, $id));
    }

    public static function setFields(array $args, ToolContext $ctx): array
    {
        $id = (int) $args['id'];

        FcWriter::save($ctx, self::changes($ctx, $id, ['fields' => $args['fields']]), null);

        return ['result' => 'updated', 'id' => $id, 'fields' => self::itemDetails($ctx, FcData::item($ctx, $id))['fields']];
    }

    public static function setTags(array $args, ToolContext $ctx): array
    {
        $mode    = $args['mode'] ?? 'add';
        $wanted  = self::tagIds($ctx, $args['tags'], (bool) ($args['create_missing_tags'] ?? false) && $mode !== 'remove');
        $current = FcData::itemTags($ctx, array_map('intval', $args['ids']));
        $results = [];

        foreach (array_unique(array_map('intval', $args['ids'])) as $id) {
            try {
                $have = array_column($current[$id] ?? [], 'id');
                $tags = match ($mode) {
                    'replace' => $wanted,
                    'remove'  => array_values(array_diff($have, $wanted)),
                    default   => array_values(array_unique(array_merge($have, $wanted))),
                };

                FcWriter::save($ctx, self::changes($ctx, $id, ['tag_ids' => $tags]), null);

                $results[] = ['id' => $id, 'ok' => true, 'tags' => FcData::itemTags($ctx, [$id])[$id] ?? []];
            } catch (ToolException $e) {
                $results[] = ['id' => $id, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return ['results' => $results];
    }

    public static function createTag(array $args, ToolContext $ctx): array
    {
        [$ids] = FcData::tags($ctx, [$args['title']]);

        if ($ids) {
            return ['result' => 'exists', 'tag' => ['id' => $ids[0], 'name' => $args['title']]];
        }

        return ['result' => 'created', 'tag' => ['id' => FcWriter::createTag($ctx, $args['title']), 'name' => $args['title']]];
    }

    public static function setState(array $args, ToolContext $ctx): array
    {
        if (!isset($args['state']) && !isset($args['featured'])) {
            throw new ToolException('Pass "state" and/or "featured".');
        }

        if (($args['state'] ?? null) === 'published' && !$ctx->params->get('allow_publish', 1)) {
            throw new ToolException('Publishing is disabled on this MCP server.');
        }

        return ['results' => FcWriter::setState($ctx, array_map('intval', $args['ids']), isset($args['state']) ? FcData::STATES[$args['state']] : null, $args['featured'] ?? null)];
    }

    public static function trash(array $args, ToolContext $ctx): array
    {
        return ['results' => FcWriter::setState($ctx, array_map('intval', $args['ids']), -2, null)];
    }

    // ------------------------------------------------------------------ Helpers

    /**
     * Save data for an existing item: its current state with the requested changes applied.
     */
    private static function changes(ToolContext $ctx, int $id, array $args): array
    {
        $item   = FcData::item($ctx, $id);
        $custom = FcData::values($ctx, [$id])[$id] ?? [];

        // FLEXIcontent clears custom fields that are not sent, and per-item settings that are
        // not sent: always send the current values and change only what was asked for
        $data = [
            'id'       => $id,
            'type_id'  => $item->type_id,
            'catid'    => (int) $item->catid,
            'attribs'  => (new Registry($item->attribs))->toArray(),
            'metadata' => (new Registry($item->metadata))->toArray(),
            'custom'   => [],
        ];

        foreach (FcData::fields($ctx, $item->type_id) as $name => $field) {
            if (!$field->iscore) {
                $data['custom'][$name] = $custom[$name] ?? [];
            }
        }

        if (isset($args['title'])) {
            $data['title'] = $args['title'];
        }

        if (isset($args['category'])) {
            $main          = Resolver::category($ctx, $args['category']);
            $data['catid'] = $main->id;

            // Keep the other assigned categories, with the new main one included
            $current     = array_column(FcData::categories($ctx, [$id])[$id] ?? [], 'id');
            $data['cid'] = array_values(array_unique(array_merge([$main->id], array_diff($current, [(int) $item->catid]))));
        }

        if (isset($args['tag_ids'])) {
            $data['tag'] = $args['tag_ids'];
        }

        return self::applyChanges($ctx, $data, $args, $item->type_id, ['introtext' => $item->introtext, 'fulltext' => $item->fulltext, 'custom' => $custom]);
    }

    /**
     * Apply the common writable properties of create_article / update_article.
     */
    private static function applyChanges(ToolContext $ctx, array $data, array $args, int $typeId, array $existing): array
    {
        foreach (['alias', 'language', 'metadesc', 'metakey'] as $key) {
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
                // FLEXIcontent stores the given date as UTC
                $data[$key] = empty($args[$key]) ? '' : Resolver::sqlDate($ctx, $args[$key]);
            }
        }

        if (isset($args['text'])) {
            $data['text'] = $args['text'];
        } elseif (isset($args['introtext']) || isset($args['fulltext'])) {
            $intro = $args['introtext'] ?? $existing['introtext'];
            $full  = $args['fulltext'] ?? $existing['fulltext'];

            $data['text'] = trim((string) $full) === '' ? $intro : $intro . self::READMORE . $full;
        }

        if (isset($args['categories'])) {
            $ids = [$data['catid']];

            foreach ((array) $args['categories'] as $ref) {
                $ids[] = Resolver::category($ctx, $ref)->id;
            }

            $data['cid'] = array_values(array_unique($ids));
        }

        if (isset($args['tags'])) {
            $data['tag'] = self::tagIds($ctx, $args['tags'], (bool) ($args['create_missing_tags'] ?? false));
        }

        if (isset($args['fields'])) {
            $data['custom'] = FcWriter::fieldValues($ctx, $typeId, (array) $args['fields'], $data['custom'] ?? []);
        }

        return $data;
    }

    /**
     * @return int[]
     */
    private static function tagIds(ToolContext $ctx, array $refs, bool $createMissing): array
    {
        [$ids, $missing] = FcData::tags($ctx, $refs);

        if ($missing && !$createMissing) {
            throw new ToolException(\sprintf('FLEXIcontent tags not found: %s. Use list_tags, create_tag, or pass create_missing_tags: true.', implode(', ', $missing)));
        }

        foreach ($missing as $name) {
            $ids[] = FcWriter::createTag($ctx, $name);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Sort column for search_articles. A map, so the argument never reaches the SQL as it was given.
     */
    private static function orderColumn(string $orderBy): string
    {
        return match ($orderBy) {
            'modified'   => 'a.modified',
            'publish_up' => 'a.publish_up',
            'hits'       => 'a.hits',
            'title'      => 'a.title',
            'id'         => 'a.id',
            default      => 'a.created',
        };
    }

    /**
     * #__content AS a joined with the FLEXIcontent item extension AS ie.
     */
    private static function baseQuery(ToolContext $ctx): \Joomla\Database\QueryInterface
    {
        $db = $ctx->db;

        return $db->getQuery(true)
            ->from($db->quoteName('#__content', 'a'))
            ->join('INNER', $db->quoteName('#__flexicontent_items_ext', 'ie'), 'ie.item_id = a.id');
    }

    private static function groupByPeriod(ToolContext $ctx, \Joomla\Database\QueryInterface $query, string $dateField, string $format): array
    {
        $tz     = new \DateTimeZone($ctx->app->get('offset', 'UTC'));
        $groups = [];

        $ctx->db->setQuery($query->select($dateField . ' AS d, a.hits')->where($dateField . ' IS NOT NULL'));

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
