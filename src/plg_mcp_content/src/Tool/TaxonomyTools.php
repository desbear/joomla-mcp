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
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Categories, tags and custom field definitions.
 *
 * @since  0.1.0
 */
final class TaxonomyTools
{
    /**
     * @return Tool[]
     */
    public static function tools(): array
    {
        $ref = ['type' => ['integer', 'string']];

        // Reading the taxonomy of com_content needs one of its back-end permissions, not just mcp.use
        $canRead = fn (ToolContext $c) => $c->user->authorise('core.manage', 'com_content')
            || $c->user->authorise('core.edit', 'com_content')
            || $c->user->authorise('core.edit.own', 'com_content')
            || $c->user->authorise('core.create', 'com_content');

        return [
            new Tool(
                'list_categories',
                'List article categories as a tree (ordered by position) with their article counts.',
                [
                    'properties' => [
                        'query'         => ['type' => 'string', 'description' => 'Filter by title or path.'],
                        'parent'        => $ref + ['description' => 'Only this category and its descendants (ID, title or path).'],
                        'include_empty' => ['type' => 'boolean', 'description' => 'Include categories without articles (default true).'],
                        'state'         => ['type' => 'string', 'enum' => ['published', 'unpublished', 'any'], 'description' => 'Default any (trashed excluded).'],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'listCategories'],
                'List categories',
                Tool::SCOPE_READ,
                'content',
                false,
                $canRead
            ),
            new Tool(
                'create_category',
                'Create an article category.',
                [
                    'properties' => [
                        'title'       => ['type' => 'string'],
                        'parent'      => $ref + ['description' => 'Parent category (ID, title or path). Default: top level.'],
                        'alias'       => ['type' => 'string'],
                        'description' => ['type' => 'string', 'description' => 'HTML description.'],
                        'published'   => ['type' => 'boolean', 'description' => 'Default true.'],
                        'language'    => ['type' => 'string', 'description' => 'Default "*".'],
                    ],
                    'required'             => ['title'],
                    'additionalProperties' => false,
                ],
                [self::class, 'createCategory'],
                'Create category',
                Tool::SCOPE_WRITE,
                'content',
                false,
                fn (ToolContext $c) => $c->user->authorise('core.create', 'com_content')
            ),
            new Tool(
                'list_tags',
                'List tags with the number of articles using each tag. Only articles the account may see are counted, '
                . 'and only articles: tagged items of other content types are not included.',
                [
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Filter by title or path.'],
                        'limit' => ['type' => 'integer', 'description' => 'Default 100.'],
                        'offset' => ['type' => 'integer'],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'listTags'],
                'List tags',
                Tool::SCOPE_READ,
                'content',
                false,
                $canRead
            ),
            new Tool(
                'create_tag',
                'Create a tag. Tags can also be created on the fly with create_missing_tags when saving an article.',
                [
                    'properties' => [
                        'title'       => ['type' => 'string'],
                        'parent'      => $ref + ['description' => 'Parent tag (ID, title or path).'],
                        'description' => ['type' => 'string'],
                    ],
                    'required'             => ['title'],
                    'additionalProperties' => false,
                ],
                [self::class, 'createTag'],
                'Create tag',
                Tool::SCOPE_WRITE,
                'content',
                false,
                fn (ToolContext $c) => $c->user->authorise('core.create', 'com_tags')
            ),
            new Tool(
                'list_fields',
                'List the custom fields available for articles: name (use it as key when setting values), type, required, '
                . 'allowed options for list/radio/checkbox fields, and the categories each field is limited to.',
                [
                    'properties' => [
                        'category' => $ref + ['description' => 'Only fields that apply to this category.'],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'listFields'],
                'List custom fields',
                Tool::SCOPE_READ,
                'content',
                false,
                $canRead
            ),
        ];
    }

    public static function listCategories(array $args, ToolContext $ctx): array
    {
        $db      = $ctx->db;
        $allowed = Resolver::allowedCategoryIds($ctx);
        $count   = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__content', 'a'))
            ->where('a.catid = c.id')
            ->where('a.state <> -2');

        // Mirror Resolver::applyArticleVisibility, so the number does not reveal articles the caller
        // cannot see. This subquery is embedded as SQL text and would lose bound parameters, so the
        // values are inlined; all of them are integers.
        if (!$ctx->user->authorise('core.admin')) {
            $count->where('a.access IN (' . implode(',', array_map('intval', $ctx->user->getAuthorisedViewLevels()) ?: [0]) . ')');
        }

        if ($allowed !== null) {
            $count->where('a.catid IN (' . implode(',', array_map('intval', $allowed) ?: [0]) . ')');
        }

        $query = $db->getQuery(true)
            ->select('c.id, c.title, c.alias, c.path, c.level, c.parent_id, c.published, c.language')
            ->select('(' . $count . ') AS article_count')
            ->from($db->quoteName('#__categories', 'c'))
            ->where('c.extension = ' . $db->quote('com_content'))
            ->order('c.lft');

        $state = $args['state'] ?? 'any';
        $query->where($state === 'any' ? 'c.published IN (0, 1)' : 'c.published = ' . ($state === 'published' ? 1 : 0));

        if (isset($args['parent'])) {
            $query->whereIn('c.id', Resolver::categoryTree($ctx, Resolver::category($ctx, $args['parent'])->id));
        }

        if (($args['query'] ?? '') !== '') {
            $search = '%' . Resolver::likeValue(mb_strtolower($args['query'])) . '%';
            $query->where('(LOWER(c.title) LIKE :s1 OR LOWER(c.path) LIKE :s2)')->bind(':s1', $search)->bind(':s2', $search);
        }

        if ($allowed !== null) {
            $query->whereIn('c.id', $allowed ?: [0]);
        }

        $rows = $db->setQuery($query)->loadObjectList();

        if (!($args['include_empty'] ?? true)) {
            $rows = array_filter($rows, fn ($r) => $r->article_count > 0);
        }

        return ['categories' => array_values(array_map(fn ($r) => [
            'id'            => (int) $r->id,
            'title'         => $r->title,
            'path'          => $r->path,
            'level'         => (int) $r->level,
            'parent_id'     => (int) $r->parent_id,
            'published'     => (int) $r->published === 1,
            'language'      => $r->language,
            'article_count' => (int) $r->article_count,
        ], $rows))];
    }

    public static function createCategory(array $args, ToolContext $ctx): array
    {
        $parentId = isset($args['parent']) ? Resolver::category($ctx, $args['parent'])->id : 1;

        if ($parentId === 1 && $ctx->allowedCategories()) {
            throw new ToolException('This MCP server is limited to certain categories; pass a "parent" inside them.');
        }

        if (!$ctx->user->authorise('core.create', $parentId > 1 ? 'com_content.category.' . $parentId : 'com_content')) {
            throw new ToolException('You are not allowed to create categories here.');
        }

        /** @var \Joomla\Component\Categories\Administrator\Model\CategoryModel $model */
        $model = ModelHelper::admin($ctx, 'com_categories', 'Category', ['extension' => 'com_content']);
        $model->setState('category.extension', 'com_content');

        $data = [
            'id'          => 0,
            'extension'   => 'com_content',
            'title'       => $args['title'],
            'alias'       => $args['alias'] ?? '',
            'parent_id'   => $parentId,
            'description' => $args['description'] ?? '',
            'published'   => ($args['published'] ?? true) ? 1 : 0,
            'language'    => $args['language'] ?? '*',
            'access'      => 1,
        ];

        if (!$model->save($data)) {
            throw new ToolException('Could not create the category: ' . $model->getError());
        }

        $id = (int) $model->getState('category.id');

        return ['result' => 'created', 'category' => self::listCategories(['parent' => $id], $ctx)['categories'][0] ?? ['id' => $id]];
    }

    public static function listTags(array $args, ToolContext $ctx): array
    {
        $db      = $ctx->db;
        $allowed = Resolver::allowedCategoryIds($ctx);

        // A map row says nothing about whether the caller may see the article behind it, so the count
        // only counts articles that pass the checks of Resolver::applyArticleVisibility.
        $article = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName('#__content', 'a'))
            ->where('a.id = m.content_item_id')
            ->where('a.state <> -2');

        // Both subqueries are embedded as SQL text and would lose bound parameters, so these values
        // are inlined; all of them are integers.
        if (!$ctx->user->authorise('core.admin')) {
            $article->where('a.access IN (' . implode(',', array_map('intval', $ctx->user->getAuthorisedViewLevels()) ?: [0]) . ')');
        }

        if ($allowed !== null) {
            $article->where('a.catid IN (' . implode(',', array_map('intval', $allowed) ?: [0]) . ')');
        }

        // The type_alias condition already limited this to articles, so nothing that used to be
        // counted is dropped by joining #__content
        $count = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__contentitem_tag_map', 'm'))
            ->where('m.tag_id = t.id')
            ->where('m.type_alias = ' . $db->quote('com_content.article'))
            ->where('EXISTS (' . $article . ')');

        $query = $db->getQuery(true)
            ->from($db->quoteName('#__tags', 't'))
            ->where('t.id > 1')
            ->where('t.published IN (0, 1)');

        if (($args['query'] ?? '') !== '') {
            $search = '%' . Resolver::likeValue(mb_strtolower($args['query'])) . '%';
            $query->where('(LOWER(t.title) LIKE :s1 OR LOWER(t.path) LIKE :s2)')->bind(':s1', $search)->bind(':s2', $search);
        }

        $total  = (int) $db->setQuery((clone $query)->select('COUNT(*)'))->loadResult();
        $limit  = $ctx->limit($args['limit'] ?? null, 100);
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $query->select('t.id, t.title, t.path, t.published, t.language')
            ->select('(' . $count . ') AS article_count')
            ->order('t.lft')
            ->setLimit($limit, $offset);

        $items = array_map(fn ($r) => [
            'id'            => (int) $r->id,
            'title'         => $r->title,
            'path'          => $r->path,
            'published'     => (int) $r->published === 1,
            'article_count' => (int) $r->article_count,
        ], $db->setQuery($query)->loadObjectList());

        return ['total' => $total, 'offset' => $offset, 'has_more' => $offset + \count($items) < $total, 'tags' => $items];
    }

    public static function createTag(array $args, ToolContext $ctx): array
    {
        $parentId = isset($args['parent']) ? (int) Resolver::tags($ctx, [$args['parent']])[0] : 1;

        /** @var \Joomla\Component\Tags\Administrator\Model\TagModel $model */
        $model = ModelHelper::admin($ctx, 'com_tags', 'Tag');

        $data = [
            'id'          => 0,
            'title'       => $args['title'],
            'parent_id'   => $parentId,
            'description' => $args['description'] ?? '',
            'published'   => 1,
            'language'    => '*',
            'access'      => 1,
        ];

        if (!$model->save($data)) {
            throw new ToolException('Could not create the tag: ' . $model->getError());
        }

        $id = (int) $model->getState('tag.id');

        return ['result' => 'created', 'tag' => ['id' => $id, 'title' => $args['title']]];
    }

    public static function listFields(array $args, ToolContext $ctx): array
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select('f.id, f.name, f.label, f.type, f.required, f.default_value, f.description, f.fieldparams, f.state, f.language, g.title AS group_title')
            ->from($db->quoteName('#__fields', 'f'))
            ->join('LEFT', $db->quoteName('#__fields_groups', 'g'), 'g.id = f.group_id')
            ->where('f.context = ' . $db->quote('com_content.article'))
            ->where('f.state = 1')
            ->order('f.ordering');

        $fields = $db->setQuery($query)->loadObjectList('id');

        if (!$fields) {
            return ['fields' => []];
        }

        $query = $db->getQuery(true)
            ->select('field_id, category_id')
            ->from($db->quoteName('#__fields_categories'))
            ->whereIn('field_id', array_keys($fields));

        $assignments = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $assignments[(int) $row->field_id][] = (int) $row->category_id;
        }

        $filterCategories = null;

        if (isset($args['category'])) {
            $category = Resolver::category($ctx, $args['category']);

            // A field assigned to a parent category also applies to its children
            $query = $db->getQuery(true)
                ->select('p.id')
                ->from($db->quoteName('#__categories', 'p'))
                ->join('INNER', $db->quoteName('#__categories', 'c'), 'c.lft BETWEEN p.lft AND p.rgt AND c.extension = p.extension')
                ->where('c.id = :id')
                ->bind(':id', $category->id, ParameterType::INTEGER);

            $filterCategories = array_map('intval', $db->setQuery($query)->loadColumn());
        }

        $result = [];

        foreach ($fields as $id => $field) {
            $categories = $assignments[(int) $id] ?? [];

            // -1 means "no category": the field is not shown on articles (typically used inside subform fields)
            $noCategory = $categories === [-1];

            if ($filterCategories !== null && ($noCategory || ($categories && !array_intersect($categories, $filterCategories)))) {
                continue;
            }

            $params  = json_decode((string) $field->fieldparams, true) ?: [];
            $options = [];

            foreach ((array) ($params['options'] ?? []) as $option) {
                if (isset($option['value'])) {
                    $options[] = ['value' => $option['value'], 'text' => $option['name'] ?? $option['value']];
                }
            }

            $entry = [
                'name'        => $field->name,
                'label'       => $field->label,
                'type'        => $field->type,
                'required'    => (bool) $field->required,
                'group'       => $field->group_title,
                'description' => $field->description,
                'default'     => $field->default_value,
                'categories'  => $noCategory ? 'none (only used inside subform fields)' : ($categories ?: 'all'),
                'multiple'    => $field->type === 'checkboxes' || !empty($params['multiple']),
            ];

            if ($options) {
                $entry['options'] = $options;
            }

            if ($field->language !== '*') {
                $entry['language'] = $field->language;
            }

            $result[] = $entry;
        }

        return ['fields' => $result];
    }
}
