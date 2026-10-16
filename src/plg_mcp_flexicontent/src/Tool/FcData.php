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
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

\defined('_JEXEC') or die;

/**
 * Reads FLEXIcontent data straight from its tables (fast, and independent of its models).
 *
 * Items are rows of #__content extended by #__flexicontent_items_ext (type), with several
 * categories (#__flexicontent_cats_item_relations), FLEXIcontent tags and custom field values
 * (#__flexicontent_fields_item_relations: one row per value and sub-value).
 *
 * @since  0.4.0
 */
final class FcData
{
    /** FLEXIcontent item states. */
    public const STATES = [
        'published'   => 1,
        'unpublished' => 0,
        'archived'    => 2,
        'trashed'     => -2,
        'pending'     => -3,
        'draft'       => -4,
        'in_progress' => -5,
    ];

    /** Field types whose values are chosen from a list of options. */
    public const OPTION_TYPES = ['select', 'selectmultiple', 'radio', 'radioimage', 'checkbox', 'checkboximage'];

    /** Field types that never hold values: edit form layout, loaded modules, navigation, ... */
    public const NO_VALUE_TYPES = ['coreprops', 'custom_form_html', 'fcloadmodule', 'fcpagenav', 'toolbar', 'comments', 'account_via_submit'];

    /** Field types that can be written through MCP. */
    public const WRITABLE_TYPES = ['text', 'textarea', 'textselect', 'select', 'selectmultiple', 'radio', 'radioimage', 'checkbox', 'checkboximage', 'date', 'email', 'color'];

    /**
     * Items that are not published are only visible to someone who could edit them in the back
     * end; without this any account granted "Use MCP Server" could read every draft on the site.
     */
    public static function canSeeUnpublished(ToolContext $ctx): bool
    {
        $user = $ctx->user;

        return $user->authorise('core.edit.state', 'com_content')
            || $user->authorise('core.edit', 'com_content')
            || $user->authorise('core.manage', 'com_content');
    }

    /**
     * Date column for date_from/date_to and the period grouping. A map, so the argument never
     * reaches the SQL as it was given.
     */
    public static function dateColumn(string $dateField): string
    {
        return match ($dateField) {
            'modified'   => 'a.modified',
            'publish_up' => 'a.publish_up',
            default      => 'a.created',
        };
    }

    // ------------------------------------------------------------------ Translation

    /**
     * Translate a label in the site's default language, including the site template's strings and
     * the site language overrides (the API application would look for those below /api).
     */
    public static function text(string $key): string
    {
        static $lang = null;
        static $overrides = [];

        $key = trim($key);

        if ($key === '' || !preg_match('/^[A-Z0-9_]+$/', $key)) {
            return $key;
        }

        if ($lang === null) {
            $tag  = \Joomla\CMS\Component\ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
            $lang = \Joomla\CMS\Language\Language::getInstance($tag);

            foreach ([JPATH_ADMINISTRATOR, JPATH_SITE] as $base) {
                $lang->load('com_flexicontent', $base, $tag, false, true);
            }

            try {
                $db       = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
                $template = (string) $db->setQuery(
                    $db->getQuery(true)->select('template')->from($db->quoteName('#__template_styles'))->where('client_id = 0')->where('home = ' . $db->quote('1'))
                )->loadResult();

                if ($template !== '') {
                    $lang->load('tpl_' . $template, JPATH_SITE, $tag, false, true);
                    $lang->load('tpl_' . $template, JPATH_SITE . '/templates/' . $template, $tag, false, true);
                }
            } catch (\Throwable) {
            }

            $file = JPATH_SITE . '/language/overrides/' . $tag . '.override.ini';

            if (is_file($file)) {
                $overrides = \Joomla\CMS\Language\LanguageHelper::parseIniFile($file) ?: [];
            }
        }

        return $overrides[$key] ?? $lang->_($key);
    }

    // ------------------------------------------------------------------ Types and fields

    /**
     * @return array<int, object>  Types keyed by ID: id, name, alias, published, description, attribs (array)
     */
    public static function types(ToolContext $ctx): array
    {
        static $cache = null;

        if ($cache === null) {
            $db    = $ctx->db;
            $cache = $db->setQuery(
                $db->getQuery(true)
                    ->select('id, name, alias, published, description, attribs')
                    ->from($db->quoteName('#__flexicontent_types'))
                    ->order('id')
            )->loadObjectList('id');

            foreach ($cache as $type) {
                $type->id      = (int) $type->id;
                $type->attribs = json_decode((string) $type->attribs, true) ?: [];
            }
        }

        return $cache;
    }

    public static function type(ToolContext $ctx, mixed $ref): object
    {
        $types = self::types($ctx);

        if (\is_int($ref) || ctype_digit((string) $ref)) {
            if (isset($types[(int) $ref])) {
                return $types[(int) $ref];
            }
        } else {
            $lower = mb_strtolower(trim((string) $ref));

            foreach ($types as $type) {
                if (mb_strtolower($type->name) === $lower || mb_strtolower($type->alias) === $lower) {
                    return $type;
                }
            }
        }

        throw new ToolException(\sprintf(
            'Content type "%s" not found. Types: %s.',
            (string) $ref,
            implode(', ', array_map(fn ($t) => $t->id . ' (' . $t->name . ')', $types))
        ));
    }

    /**
     * Fields assigned to a type (or all fields), in the type's order.
     *
     * @return array<string, object>  Keyed by field name: id, name, label, field_type, iscore, published, description, params (array)
     */
    public static function fields(ToolContext $ctx, ?int $typeId = null): array
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select('f.id, f.name, f.label, f.field_type, f.iscore, f.published, f.description, f.attribs, f.formhidden')
            ->from($db->quoteName('#__flexicontent_fields', 'f'));

        if ($typeId !== null) {
            $query->join('INNER', $db->quoteName('#__flexicontent_fields_type_relations', 'r'), 'r.field_id = f.id')
                ->where('r.type_id = :type')
                ->bind(':type', $typeId, ParameterType::INTEGER)
                ->order('r.ordering');
        } else {
            $query->order('f.ordering, f.id');
        }

        $fields = [];

        foreach ($db->setQuery($query)->loadObjectList() as $field) {
            $field->id     = (int) $field->id;
            $field->params = json_decode((string) $field->attribs, true) ?: [];
            unset($field->attribs);
            $fields[$field->name] = $field;
        }

        return $fields;
    }

    public static function field(ToolContext $ctx, string $name): object
    {
        $fields = self::fields($ctx);

        if (!isset($fields[$name])) {
            throw new ToolException(\sprintf('FLEXIcontent field "%s" not found. Use list_fields.', $name));
        }

        return $fields[$name];
    }

    /**
     * Options of a list field ("value::label %% value::label"), empty for SQL-driven lists.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(object $field): array
    {
        if (!\in_array($field->field_type, self::OPTION_TYPES, true) || !empty($field->params['sql_mode'])) {
            return [];
        }

        $options = [];

        foreach (preg_split('/\s*%%\s*/', trim((string) ($field->params['field_elements'] ?? ''))) as $element) {
            if ($element === '') {
                continue;
            }

            $parts     = explode('::', $element);
            $options[] = ['value' => trim($parts[0]), 'label' => self::text(trim($parts[1] ?? $parts[0]))];
        }

        return $options;
    }

    /**
     * Whether a field holds values (not a form layout or display-only field).
     */
    public static function holdsValues(object $field): bool
    {
        return !$field->iscore && !\in_array($field->field_type, self::NO_VALUE_TYPES, true);
    }

    /**
     * Field group memberships: member field ID => group field name.
     *
     * @param  object[]  $fields
     */
    public static function groupMembers(array $fields): array
    {
        $members = [];

        foreach ($fields as $field) {
            if ($field->field_type === 'fieldgroup') {
                foreach (preg_split('/[\s,]+/', \is_array($field->params['fields'] ?? null) ? implode(',', $field->params['fields']) : (string) ($field->params['fields'] ?? '')) as $id) {
                    if ((int) $id > 0) {
                        $members[(int) $id] = $field->name;
                    }
                }
            }
        }

        return $members;
    }

    /**
     * Describe a field for the model.
     *
     * @param  string|null  $group  Name of the field group this field belongs to
     */
    public static function describe(object $field, ?string $group = null): array
    {
        $options  = self::options($field);
        $writable = \in_array($field->field_type, self::WRITABLE_TYPES, true) && $group === null;

        return array_filter([
            'name'        => $field->name,
            'label'       => self::text($field->label),
            'type'        => $field->field_type,
            'required'    => !empty($field->params['required']) ?: null,
            'multiple'    => (\in_array($field->field_type, ['checkbox', 'checkboximage', 'selectmultiple'], true) || !empty($field->params['allow_multiple'])) ?: null,
            'options'     => $options ?: null,
            'options_from_sql' => (!empty($field->params['sql_mode']) && \in_array($field->field_type, self::OPTION_TYPES, true)) ?: null,
            'in_field_group' => $group,
            'writable'    => $writable ? null : false,
            'published'   => (int) $field->published === 1 ? null : false,
            'description' => trim(strip_tags(self::text((string) $field->description))) ?: null,
        ], fn ($v) => $v !== null);
    }

    // ------------------------------------------------------------------ Tags

    /**
     * Resolve FLEXIcontent tags by ID, name or alias.
     *
     * @return  array{0: int[], 1: string[]}  Found tag IDs and unknown names
     */
    public static function tags(ToolContext $ctx, array $refs): array
    {
        $db      = $ctx->db;
        $ids     = [];
        $missing = [];

        foreach ($refs as $ref) {
            $query = $db->getQuery(true)->select('id')->from($db->quoteName('#__flexicontent_tags'));

            if (\is_int($ref) || ctype_digit((string) $ref)) {
                $id = (int) $ref;
                $query->where('id = :id')->bind(':id', $id, ParameterType::INTEGER);
            } else {
                $lower = mb_strtolower(trim((string) $ref));
                $query->where('(LOWER(name) = :name OR LOWER(alias) = :alias)')->bind(':name', $lower)->bind(':alias', $lower);
            }

            $found = $db->setQuery($query)->loadColumn();

            if (\count($found) > 1) {
                throw new ToolException(\sprintf('Tag "%s" is ambiguous (IDs %s).', (string) $ref, implode(', ', $found)));
            }

            if ($found) {
                $ids[] = (int) $found[0];
            } elseif (\is_int($ref) || ctype_digit((string) $ref)) {
                throw new ToolException(\sprintf('FLEXIcontent tag ID %s not found. Use list_tags.', (string) $ref));
            } else {
                $missing[] = trim((string) $ref);
            }
        }

        return [array_values(array_unique($ids)), array_values(array_unique($missing))];
    }

    // ------------------------------------------------------------------ Items

    public static function item(ToolContext $ctx, int $id): object
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select('a.*, ie.type_id')
            ->from($db->quoteName('#__content', 'a'))
            ->join('INNER', $db->quoteName('#__flexicontent_items_ext', 'ie'), 'ie.item_id = a.id')
            ->where('a.id = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        Resolver::applyArticleVisibility($ctx, $query);

        $item = $db->setQuery($query)->loadObject();

        if (!$item) {
            throw new ToolException(\sprintf('Item %d not found (or not accessible).', $id));
        }

        $item->type_id = (int) $item->type_id;

        return $item;
    }

    /**
     * Custom field values per item: [item ID][field name] => value, or a list for multi-value
     * fields. Sub-values (multi-select inside a value) become nested lists; serialized values
     * (images, links, ...) are unserialized without allowing objects.
     *
     * @param  int[]  $itemIds
     */
    public static function values(ToolContext $ctx, array $itemIds, bool $includeCore = false): array
    {
        if (!$itemIds) {
            return [];
        }

        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select('r.item_id, f.name, f.field_type, r.valueorder, r.suborder, r.value')
            ->from($db->quoteName('#__flexicontent_fields_item_relations', 'r'))
            ->join('INNER', $db->quoteName('#__flexicontent_fields', 'f'), 'f.id = r.field_id')
            ->whereIn('r.item_id', array_map('intval', $itemIds))
            ->order('r.item_id, f.ordering, r.valueorder, r.suborder');

        if (!$includeCore) {
            $query->where('f.iscore = 0');
        }

        $raw = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $value = $row->value;

            if (\is_string($value) && preg_match('/^(a|s|i|d|b):\d*[:;]/', $value)) {
                $decoded = @unserialize($value, ['allowed_classes' => false]);
                $value   = $decoded === false && $value !== 'b:0;' ? $value : $decoded;
            }

            $raw[(int) $row->item_id][$row->name][(int) $row->valueorder][(int) $row->suborder] = $value;
        }

        $result = [];

        foreach ($raw as $itemId => $fields) {
            foreach ($fields as $name => $orders) {
                $values = array_map(fn ($subs) => \count($subs) === 1 ? reset($subs) : array_values($subs), array_values($orders));
                $result[$itemId][$name] = $values;
            }
        }

        return $result;
    }

    /**
     * All categories of items: [item ID] => [[id, title, path, main]].
     *
     * @param  int[]  $itemIds
     */
    public static function categories(ToolContext $ctx, array $itemIds): array
    {
        if (!$itemIds) {
            return [];
        }

        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select('r.itemid, c.id, c.title, c.path, CASE WHEN a.catid = c.id THEN 1 ELSE 0 END AS main')
            ->from($db->quoteName('#__flexicontent_cats_item_relations', 'r'))
            ->join('INNER', $db->quoteName('#__categories', 'c'), 'c.id = r.catid')
            ->join('INNER', $db->quoteName('#__content', 'a'), 'a.id = r.itemid')
            ->whereIn('r.itemid', array_map('intval', $itemIds))
            ->order('main DESC, c.lft');

        $result = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $result[(int) $row->itemid][] = ['id' => (int) $row->id, 'title' => $row->title, 'path' => $row->path, 'main' => (bool) $row->main];
        }

        return $result;
    }

    /**
     * FLEXIcontent tags of items: [item ID] => [[id, name]].
     *
     * @param  int[]  $itemIds
     */
    public static function itemTags(ToolContext $ctx, array $itemIds): array
    {
        if (!$itemIds) {
            return [];
        }

        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select('r.itemid, t.id, t.name')
            ->from($db->quoteName('#__flexicontent_tags_item_relations', 'r'))
            ->join('INNER', $db->quoteName('#__flexicontent_tags', 't'), 't.id = r.tid')
            ->whereIn('r.itemid', array_map('intval', $itemIds))
            ->order('t.name');

        $result = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $result[(int) $row->itemid][] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $result;
    }

    // ------------------------------------------------------------------ Filters shared by search and statistics

    /**
     * JSON schema of the item filters.
     */
    public static function filterSchema(): array
    {
        $ref = ['type' => ['integer', 'string']];

        return [
            'query'                 => ['type' => 'string', 'description' => 'Text in the title (or, with search_in, the content).'],
            'search_in'             => ['type' => 'string', 'enum' => ['title', 'content', 'all']],
            'type'                  => $ref + ['description' => 'Content type ID or name (see list_types).'],
            'category'              => $ref + ['description' => 'Category ID, title or path; matches items assigned to it (main or additional category).'],
            'main_category_only'    => ['type' => 'boolean', 'description' => 'Only match the main category (default false).'],
            'include_subcategories' => ['type' => 'boolean', 'description' => 'Default true.'],
            'tag'                   => $ref + ['description' => 'FLEXIcontent tag ID or name.'],
            'author'                => $ref + ['description' => 'User ID, username or name.'],
            'state'                 => ['type' => 'string', 'enum' => array_merge(array_keys(self::STATES), ['any']), 'description' => 'Default: everything except trashed.'],
            'featured'              => ['type' => 'boolean'],
            'language'              => ['type' => 'string'],
            'date_field'            => ['type' => 'string', 'enum' => ['created', 'modified', 'publish_up'], 'description' => 'Default created.'],
            'date_from'             => ['type' => 'string', 'description' => 'YYYY-MM-DD, site time zone.'],
            'date_to'               => ['type' => 'string', 'description' => 'YYYY-MM-DD, inclusive.'],
            'fields'                => [
                'type'        => 'object',
                'description' => 'Custom field conditions keyed by field name (see list_fields), e.g. {"product": "acme-crm"}. '
                    . 'List fields match the option value or label exactly; other fields match text contained in the value. '
                    . 'An array matches any of the values.',
            ],
        ];
    }

    /**
     * Apply the filters to a query on #__content AS a joined with #__flexicontent_items_ext AS ie.
     *
     * @return array  Description of the applied filters
     */
    public static function applyFilters(ToolContext $ctx, QueryInterface $query, array $args): array
    {
        $db      = $ctx->db;
        $applied = [];

        Resolver::applyArticleVisibility($ctx, $query);

        if (($args['query'] ?? '') !== '') {
            $cols   = match ($args['search_in'] ?? 'title') {
                'content' => ['a.introtext', 'a.fulltext'],
                'all'     => ['a.title', 'a.alias', 'a.introtext', 'a.fulltext'],
                default   => ['a.title', 'a.alias', 'a.note'],
            };

            Resolver::applyLike($query, $cols, $args['query'], 'fcsearch');
            $applied['query'] = $args['query'];
        }

        if (isset($args['type'])) {
            $type = self::type($ctx, $args['type']);
            $query->where('ie.type_id = ' . $type->id);
            $applied['type'] = $type->name;
        }

        if (isset($args['category'])) {
            $category = Resolver::category($ctx, $args['category']);
            $ids      = ($args['include_subcategories'] ?? true) ? Resolver::categoryTree($ctx, $category->id) : [$category->id];
            $ids      = implode(',', array_map('intval', $ids));

            $query->where(!empty($args['main_category_only'])
                ? 'a.catid IN (' . $ids . ')'
                : 'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__flexicontent_cats_item_relations') . ' fcr WHERE fcr.itemid = a.id AND fcr.catid IN (' . $ids . '))');
            $applied['category'] = $category->path;
        }

        if (isset($args['tag'])) {
            [$tagIds] = self::tags($ctx, [$args['tag']]);

            if (!$tagIds) {
                throw new ToolException(\sprintf('FLEXIcontent tag "%s" not found. Use list_tags.', (string) $args['tag']));
            }

            $query->where('EXISTS (SELECT 1 FROM ' . $db->quoteName('#__flexicontent_tags_item_relations') . ' fct WHERE fct.itemid = a.id AND fct.tid IN (' . implode(',', $tagIds) . '))');
            $applied['tag'] = $args['tag'];
        }

        if (isset($args['author'])) {
            $author = Resolver::userId($ctx, $args['author']);
            $query->where('a.created_by = ' . $author);
            $applied['author'] = $author;
        }

        $state = $args['state'] ?? null;

        if (!self::canSeeUnpublished($ctx)) {
            if ($state !== null && $state !== 'published') {
                throw new ToolException('You are not allowed to list anything other than published items.');
            }

            $query->where('a.state = 1');
        } elseif ($state === null) {
            $query->where('a.state <> -2');
        } elseif ($state !== 'any') {
            $query->where('a.state = ' . (int) self::STATES[$state]);
            $applied['state'] = $state;
        }

        if (isset($args['featured'])) {
            $query->where('a.featured = ' . ($args['featured'] ? 1 : 0));
        }

        if (!empty($args['language'])) {
            $query->where('a.language = :fclang')->bind(':fclang', $args['language']);
            $applied['language'] = $args['language'];
        }

        $dateField = self::dateColumn($args['date_field'] ?? 'created');

        if (!empty($args['date_from'])) {
            $from = Resolver::sqlDate($ctx, $args['date_from']);
            $query->where($dateField . ' >= :fcfrom')->bind(':fcfrom', $from);
            $applied['date_from'] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $to = Resolver::sqlDate($ctx, $args['date_to'], true);
            $query->where($dateField . ' <= :fcto')->bind(':fcto', $to);
            $applied['date_to'] = $args['date_to'];
        }

        foreach ((array) ($args['fields'] ?? []) as $name => $wanted) {
            $field  = self::field($ctx, (string) $name);
            $wanted = array_values(array_filter((array) $wanted, fn ($v) => $v !== null && $v !== ''));

            if (!$wanted) {
                continue;
            }

            $options    = self::options($field);
            $conditions = [];

            foreach ($wanted as $value) {
                $value = (string) $value;

                if ($options || \in_array($field->field_type, self::OPTION_TYPES, true)) {
                    // Accept the option label as well as its value
                    foreach ($options as $option) {
                        if (mb_strtolower($option['label']) === mb_strtolower($value)) {
                            $value = $option['value'];
                            break;
                        }
                    }

                    $conditions[] = 'fv.value = ' . $db->quote($value);
                } else {
                    $conditions[] = 'LOWER(fv.value) LIKE ' . $db->quote('%' . Resolver::likeValue(mb_strtolower($value)) . '%');
                }
            }

            $query->where('EXISTS (SELECT 1 FROM ' . $db->quoteName('#__flexicontent_fields_item_relations') . ' fv WHERE fv.item_id = a.id AND fv.field_id = '
                . $field->id . ' AND (' . implode(' OR ', $conditions) . '))');
            $applied['fields'][$name] = $wanted;
        }

        return $applied;
    }

    public static function stateName(int $state): string
    {
        return array_search($state, self::STATES, true) ?: (string) $state;
    }
}
