<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_jreviews
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Jreviews\Tool;

use Gss\Component\Mcp\Administrator\Helper\Resolver;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

\defined('_JEXEC') or die;

/**
 * Reads JReviews data.
 *
 * A listing is a Joomla article whose category is mapped in #__jreviews_categories; that mapping
 * also gives the listing type, which decides the custom fields. Field values are stored as one
 * column per field ("jr_...") on #__jreviews_content, and values of option fields are wrapped in
 * asterisks (*value*, *a*b*). Ratings and review counts are kept in summary tables.
 *
 * @since  0.6.0
 */
final class JrData
{
    /** Field types whose value is one or more options. */
    public const OPTION_TYPES = ['select', 'radiobuttons', 'checkboxes', 'selectmultiple'];

    /** Option fields that hold several values. */
    public const MULTI_TYPES = ['checkboxes', 'selectmultiple', 'relatedlisting'];

    /** Field types MCP can write. */
    public const WRITABLE_TYPES = ['text', 'textarea', 'integer', 'decimal', 'date', 'email', 'website', 'select', 'radiobuttons', 'checkboxes', 'selectmultiple'];

    /** Review states (#__jreviews_comments.published). */
    public const REVIEW_STATES = ['published' => 1, 'pending' => 0, 'unpublished' => -1, 'trashed' => -2];

    /** Most "fields" conditions one call may combine: each one is a LIKE over its own column. */
    public const MAX_FIELD_CONDITIONS = 20;

    /** Article states used by JReviews listings. */
    public const LISTING_STATES = ['published' => 1, 'archived' => 2, 'pending' => 0, 'draft' => -1, 'trashed' => -2];

    /**
     * A listing is a Joomla article, so the rule that governs unpublished articles governs
     * unpublished listings: only someone who could edit them in the back end may read them.
     * (Reviews are JReviews' own rows and use JreviewsTools::canModerateReviews() instead.)
     */
    public static function canSeeUnpublished(ToolContext $ctx): bool
    {
        $user = $ctx->user;

        return $user->authorise('core.edit.state', 'com_content')
            || $user->authorise('core.edit', 'com_content')
            || $user->authorise('core.manage', 'com_content');
    }

    public static function isAvailable(): bool
    {
        static $available = null;

        if ($available === null) {
            $available = ComponentHelper::isInstalled('com_jreviews')
                && ComponentHelper::isEnabled('com_jreviews')
                && self::tableExists('#__jreviews_content');
        }

        return $available;
    }

    private static function tableExists(string $table): bool
    {
        try {
            $db   = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $name = str_replace('#__', $db->getPrefix(), $table);

            return \in_array($name, $db->getTableList(), true);
        } catch (\Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------ Listing types, categories, fields

    /**
     * Listing types ("criteria"): id, title, ratings enabled, field group IDs.
     *
     * @return array<int, object>
     */
    public static function listingTypes(ToolContext $ctx): array
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select('c.id, c.title, c.state AS enabled, c.groupid')
            ->from($db->quoteName('#__jreviews_criteria', 'c'))
            ->order('c.title');

        $types = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $row->id       = (int) $row->id;
            $row->groupIds = array_values(array_filter(array_map('intval', explode(',', (string) $row->groupid))));
            $types[$row->id] = $row;
        }

        return $types;
    }

    public static function listingType(ToolContext $ctx, mixed $ref): object
    {
        $types = self::listingTypes($ctx);

        if (\is_int($ref) || ctype_digit((string) $ref)) {
            if (isset($types[(int) $ref])) {
                return $types[(int) $ref];
            }
        } else {
            $lower   = mb_strtolower(trim((string) $ref));
            $matches = array_filter($types, fn ($t) => mb_strtolower($t->title) === $lower);

            if (\count($matches) === 1) {
                return reset($matches);
            }
        }

        throw new ToolException(\sprintf(
            'Listing type "%s" not found. Types: %s.',
            (string) $ref,
            implode(', ', array_map(fn ($t) => $t->id . ' (' . $t->title . ')', $types))
        ));
    }

    /**
     * Categories that hold JReviews listings, with their listing type and directory.
     *
     * @return array<int, object>
     */
    public static function categories(ToolContext $ctx): array
    {
        $db    = $ctx->db;
        $count = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__content', 'a'))
            ->where('a.catid = c.id')
            ->where('a.state <> -2');

        $query = $db->getQuery(true)
            ->select('c.id, c.title, c.path, c.level, c.parent_id, c.published, jc.criteriaid AS listing_type_id, jc.dirid, d.title AS directory')
            ->select('(' . $count . ') AS listing_count')
            ->from($db->quoteName('#__jreviews_categories', 'jc'))
            ->join('INNER', $db->quoteName('#__categories', 'c'), 'c.id = jc.id')
            ->join('LEFT', $db->quoteName('#__jreviews_directories', 'd'), 'd.id = jc.dirid')
            ->where('jc.option = ' . $db->quote('com_content'))
            ->order('c.lft');

        $categories = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $row->id                 = (int) $row->id;
            $row->listing_type_id    = (int) $row->listing_type_id;
            $categories[$row->id]    = $row;
        }

        return $categories;
    }

    /**
     * Resolve a JReviews category by ID, title or path.
     */
    public static function category(ToolContext $ctx, mixed $ref): object
    {
        $categories = self::categories($ctx);
        $category   = Resolver::category($ctx, $ref);

        if (!isset($categories[$category->id])) {
            throw new ToolException(\sprintf(
                'Category "%s" is not a JReviews category (it has no listing type). JReviews categories: %s.',
                (string) $ref,
                implode(', ', array_map(fn ($c) => $c->title, \array_slice($categories, 0, 20)))
            ));
        }

        return $categories[$category->id];
    }

    /**
     * Fields of a listing type (or all fields), with their options.
     *
     * @param  string  $location  'content' for listing fields, 'review' for review fields
     *
     * @return array<string, object>  Keyed by field name
     */
    public static function fields(ToolContext $ctx, ?int $listingTypeId = null, string $location = 'content'): array
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select('f.fieldid, f.name, f.title, f.type, f.location, f.required, f.published, f.options, f.description, g.groupid, g.title AS group_title, g.ordering AS group_ordering, f.ordering')
            ->from($db->quoteName('#__jreviews_fields', 'f'))
            ->join('INNER', $db->quoteName('#__jreviews_groups', 'g'), 'g.groupid = f.groupid')
            ->where('f.location = :location')
            ->bind(':location', $location)
            ->order('g.ordering, f.ordering');

        if ($listingTypeId !== null) {
            $type = self::listingTypes($ctx)[$listingTypeId] ?? null;

            if (!$type || !$type->groupIds) {
                return [];
            }

            $query->whereIn('g.groupid', $type->groupIds);
        }

        $fields = [];

        foreach ($db->setQuery($query)->loadObjectList() as $field) {
            $field->fieldid = (int) $field->fieldid;
            $field->params  = json_decode((string) $field->options, true) ?: [];
            unset($field->options);
            $fields[$field->name] = $field;
        }

        if ($fields) {
            $optionRows = $db->setQuery(
                $db->getQuery(true)
                    ->select('fieldid, value, text')
                    ->from($db->quoteName('#__jreviews_fieldoptions'))
                    ->whereIn('fieldid', array_map(fn ($f) => $f->fieldid, $fields))
                    ->order('ordering')
            )->loadObjectList();

            $options = [];

            foreach ($optionRows as $row) {
                $options[(int) $row->fieldid][] = ['value' => $row->value, 'label' => $row->text];
            }

            foreach ($fields as $field) {
                $field->fieldOptions = $options[$field->fieldid] ?? [];
            }
        }

        return $fields;
    }

    public static function describeField(object $field): array
    {
        return array_filter([
            'name'     => $field->name,
            'label'    => $field->title,
            'type'     => $field->type,
            'group'    => $field->group_title,
            'required' => (bool) $field->required ?: null,
            'multiple' => \in_array($field->type, self::MULTI_TYPES, true) ?: null,
            'options'  => $field->fieldOptions ?: null,
            'writable' => \in_array($field->type, self::WRITABLE_TYPES, true) ? null : false,
            'published' => (int) $field->published === 1 ? null : false,
        ], fn ($v) => $v !== null);
    }

    // ------------------------------------------------------------------ Values

    /**
     * Wrap a value the way JReviews stores option fields: *value* or *a*b*.
     */
    public static function encodeValue(object $field, mixed $value): mixed
    {
        if (\in_array($field->type, self::OPTION_TYPES, true) || $field->type === 'relatedlisting') {
            $values = array_values(array_filter(array_map('strval', (array) $value), fn ($v) => $v !== ''));

            return $values ? '*' . implode('*', $values) . '*' : '';
        }

        if (\in_array($field->type, ['integer', 'decimal'], true)) {
            return $value === null || $value === '' ? null : $value + 0;
        }

        return \is_array($value) ? implode(', ', $value) : (string) $value;
    }

    /**
     * Turn a stored value into plain values, and option values into labels.
     *
     * @return array{0: mixed, 1: mixed}  [value(s), label(s)]
     */
    public static function decodeValue(object $field, mixed $stored): array
    {
        if ($stored === null || $stored === '') {
            return [null, null];
        }

        if (!\in_array($field->type, self::OPTION_TYPES, true) && $field->type !== 'relatedlisting') {
            return [$stored, null];
        }

        $values = array_values(array_filter(explode('*', (string) $stored), fn ($v) => $v !== ''));
        $labels = [];

        foreach ($values as $value) {
            foreach ($field->fieldOptions ?? [] as $option) {
                if ((string) $option['value'] === (string) $value) {
                    $labels[] = $option['label'];
                    continue 2;
                }
            }

            $labels[] = $value;
        }

        $multi = \in_array($field->type, self::MULTI_TYPES, true);

        return $multi ? [$values, $labels] : [$values[0] ?? null, $labels[0] ?? null];
    }

    // ------------------------------------------------------------------ Listings

    public static function listing(ToolContext $ctx, int $id): object
    {
        $db    = $ctx->db;
        $query = self::baseQuery($ctx)
            ->select('a.*, jc.criteriaid AS listing_type_id, c.title AS category_title, c.path AS category_path')
            ->select('jr.featured AS featured_listing, jr.email AS listing_email')
            ->select('t.user_rating, t.user_rating_count, t.user_comment_count, t.editor_rating, t.editor_rating_count, t.editor_comment_count')
            ->select('u.name AS author_name')
            ->where('a.id = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $listing = $db->setQuery($query)->loadObject();

        if (!$listing) {
            throw new ToolException(\sprintf('Listing %d not found, or its category is not a JReviews category.', $id));
        }

        $listing->listing_type_id = (int) $listing->listing_type_id;

        return $listing;
    }

    /**
     * Base query: articles in JReviews categories, with their JReviews rows joined.
     */
    public static function baseQuery(ToolContext $ctx): QueryInterface
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->from($db->quoteName('#__content', 'a'))
            ->join('INNER', $db->quoteName('#__jreviews_categories', 'jc'), 'jc.id = a.catid AND jc.option = ' . $db->quote('com_content'))
            ->join('LEFT', $db->quoteName('#__categories', 'c'), 'c.id = a.catid')
            ->join('LEFT', $db->quoteName('#__jreviews_content', 'jr'), 'jr.contentid = a.id')
            ->join('LEFT', $db->quoteName('#__jreviews_listing_totals', 't'), 't.listing_id = a.id AND t.extension = ' . $db->quote('com_content'))
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = a.created_by');

        Resolver::applyArticleVisibility($ctx, $query);

        return $query;
    }

    /**
     * Custom field values of a listing: [['name', 'label', 'type', 'value', 'text']].
     */
    public static function listingFieldValues(ToolContext $ctx, object $listing): array
    {
        $fields = self::fields($ctx, $listing->listing_type_id);

        if (!$fields) {
            return [];
        }

        $db      = $ctx->db;
        $columns = array_map(fn ($f) => $db->quoteName('jr.' . self::assertFieldName($f->name)), $fields);
        $row     = $db->setQuery(
            $db->getQuery(true)
                ->select($columns)
                ->from($db->quoteName('#__jreviews_content', 'jr'))
                ->where('jr.contentid = ' . (int) $listing->id)
        )->loadAssoc() ?: [];

        $values = [];

        foreach ($fields as $name => $field) {
            [$value, $label] = self::decodeValue($field, $row[$name] ?? null);

            if ($value === null || $value === [] || $value === '') {
                continue;
            }

            $values[] = array_filter([
                'name'  => $name,
                'label' => $field->title,
                'type'  => $field->type,
                'value' => $value,
                'text'  => $label,
            ], fn ($v) => $v !== null);
        }

        return $values;
    }

    // ------------------------------------------------------------------ Filters

    public static function filterSchema(): array
    {
        $ref = ['type' => ['integer', 'string']];

        return [
            'query'        => ['type' => 'string', 'description' => 'Text in the title (or the text with search_in).'],
            'search_in'    => ['type' => 'string', 'enum' => ['title', 'content', 'all']],
            'category'     => $ref + ['description' => 'JReviews category ID, title or path.'],
            'listing_type' => $ref + ['description' => 'Listing type ID or title (see list_listing_types).'],
            'author'       => $ref + ['description' => 'User ID, username or name.'],
            'state'        => ['type' => 'string', 'enum' => array_merge(array_keys(self::LISTING_STATES), ['any']), 'description' => 'Default: everything except trashed.'],
            'featured'     => ['type' => 'boolean', 'description' => 'JReviews featured listings.'],
            'min_rating'   => ['type' => 'number', 'description' => 'Minimum average user rating.'],
            'max_rating'   => ['type' => 'number'],
            'min_reviews'  => ['type' => 'integer', 'description' => 'Minimum number of user reviews.'],
            'language'     => ['type' => 'string'],
            'date_field'   => ['type' => 'string', 'enum' => ['created', 'modified', 'publish_up'], 'description' => 'Default created.'],
            'date_from'    => ['type' => 'string', 'description' => 'YYYY-MM-DD, site time zone.'],
            'date_to'      => ['type' => 'string', 'description' => 'YYYY-MM-DD, inclusive.'],
            'fields'       => [
                'type'        => 'object',
                'description' => 'Custom field conditions keyed by field name (see list_listing_fields), e.g. {"jr_city": "Taipei"}. '
                    . 'Option fields match an option value or label; text fields match text contained in the value; '
                    . 'number and date fields accept {"min": x, "max": y}.',
            ],
        ];
    }

    /**
     * Apply filters to a query built with baseQuery().
     */
    public static function applyFilters(ToolContext $ctx, QueryInterface $query, array $args): array
    {
        $db      = $ctx->db;
        $applied = [];

        $state = $args['state'] ?? null;

        if (!self::canSeeUnpublished($ctx)) {
            if ($state !== null && $state !== 'published') {
                throw new ToolException('You are not allowed to list anything other than published listings.');
            }

            $query->where('a.state = 1');
        } elseif ($state === null) {
            $query->where('a.state <> -2');
        } elseif ($state !== 'any') {
            $query->where('a.state = ' . (int) self::LISTING_STATES[$state]);
            $applied['state'] = $state;
        }

        if (($args['query'] ?? '') !== '') {
            $cols   = match ($args['search_in'] ?? 'title') {
                'content' => ['a.introtext', 'a.fulltext'],
                'all'     => ['a.title', 'a.introtext', 'a.fulltext'],
                default   => ['a.title', 'a.alias'],
            };

            Resolver::applyLike($query, $cols, $args['query'], 'jrsearch');
            $applied['query'] = $args['query'];
        }

        if (isset($args['category'])) {
            $category = self::category($ctx, $args['category']);
            $query->whereIn('a.catid', Resolver::categoryTree($ctx, $category->id));
            $applied['category'] = $category->title;
        }

        if (isset($args['listing_type'])) {
            $type = self::listingType($ctx, $args['listing_type']);
            $query->where('jc.criteriaid = ' . $type->id);
            $applied['listing_type'] = $type->title;
        }

        if (isset($args['author'])) {
            $author = Resolver::userId($ctx, $args['author']);
            $query->where('a.created_by = ' . $author);
            $applied['author'] = $author;
        }

        if (isset($args['featured'])) {
            $query->where($args['featured'] ? 'jr.featured = 1' : '(jr.featured = 0 OR jr.featured IS NULL)');
            $applied['featured'] = $args['featured'];
        }

        foreach ([['min_rating', '>='], ['max_rating', '<=']] as [$key, $operator]) {
            if (isset($args[$key])) {
                $query->where('t.user_rating ' . $operator . ' ' . (float) $args[$key]);
                $applied[$key] = $args[$key];
            }
        }

        if (isset($args['min_reviews'])) {
            $query->where('t.user_comment_count >= ' . (int) $args['min_reviews']);
            $applied['min_reviews'] = (int) $args['min_reviews'];
        }

        if (!empty($args['language'])) {
            $query->where('a.language = :jrlang')->bind(':jrlang', $args['language']);
            $applied['language'] = $args['language'];
        }

        $dateField = match ($args['date_field'] ?? 'created') {
            'modified'   => 'a.modified',
            'publish_up' => 'a.publish_up',
            default      => 'a.created',
        };

        if (!empty($args['date_from'])) {
            $from = Resolver::sqlDate($ctx, $args['date_from']);
            $query->where($dateField . ' >= :jrfrom')->bind(':jrfrom', $from);
            $applied['date_from'] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $to = Resolver::sqlDate($ctx, $args['date_to'], true);
            $query->where($dateField . ' <= :jrto')->bind(':jrto', $to);
            $applied['date_to'] = $args['date_to'];
        }

        $fields = (array) ($args['fields'] ?? []);

        // Every field adds a LIKE over a column of its own, so an unbounded list is a slow query
        if (\count($fields) > self::MAX_FIELD_CONDITIONS) {
            throw new ToolException(\sprintf('Too many field conditions (%d); at most %d can be combined.', \count($fields), self::MAX_FIELD_CONDITIONS));
        }

        foreach ($fields as $name => $condition) {
            $field = self::fieldByName($ctx, (string) $name);
            $query->where(self::fieldCondition($ctx, $field, $condition));
            $applied['fields'][$name] = $condition;
        }

        return $applied;
    }

    /**
     * Field names come from #__jreviews_fields and end up as column names, so they must be
     * harmless even if that table is ever filled with something else.
     */
    public static function assertFieldName(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new ToolException(\sprintf('Field "%s" has a name that cannot be used as a column name.', $name));
        }

        return $name;
    }

    public static function fieldByName(ToolContext $ctx, string $name): object
    {
        $fields = self::fields($ctx) + self::fields($ctx, null, 'review');

        if (!isset($fields[$name])) {
            throw new ToolException(\sprintf('Field "%s" not found. Use list_listing_fields.', $name));
        }

        return $fields[$name];
    }

    /**
     * SQL condition for a custom field filter.
     */
    private static function fieldCondition(ToolContext $ctx, object $field, mixed $condition): string
    {
        $db     = $ctx->db;
        $column = $db->quoteName('jr.' . self::assertFieldName($field->name));

        if (\is_array($condition) && (isset($condition['min']) || isset($condition['max']))) {
            $parts = [];

            if (isset($condition['min'])) {
                $parts[] = $column . ' >= ' . $db->quote((string) $condition['min']);
            }

            if (isset($condition['max'])) {
                $parts[] = $column . ' <= ' . $db->quote((string) $condition['max']);
            }

            return '(' . implode(' AND ', $parts) . ')';
        }

        $wanted = array_values(array_filter((array) $condition, fn ($v) => $v !== null && $v !== ''));
        $parts  = [];

        foreach ($wanted as $value) {
            $value = (string) $value;

            if (\in_array($field->type, self::OPTION_TYPES, true) || $field->type === 'relatedlisting') {
                // Accept the option label as well as its value; values are stored as *value*
                foreach ($field->fieldOptions ?? [] as $option) {
                    if (mb_strtolower($option['label']) === mb_strtolower($value)) {
                        $value = (string) $option['value'];
                        break;
                    }
                }

                $parts[] = $column . ' LIKE ' . $db->quote('%*' . Resolver::likeValue($value) . '*%');
            } else {
                $parts[] = 'LOWER(' . $column . ') LIKE ' . $db->quote('%' . Resolver::likeValue(mb_strtolower($value)) . '%');
            }
        }

        return $parts ? '(' . implode(' OR ', $parts) . ')' : '1 = 1';
    }
}
