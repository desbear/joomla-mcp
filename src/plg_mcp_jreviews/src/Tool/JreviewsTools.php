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
use Gss\Component\Mcp\Administrator\Mcp\Tool;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/**
 * JReviews tools: listings (articles in JReviews categories with their custom fields and ratings)
 * and the reviews written about them.
 *
 * @since  0.6.0
 */
final class JreviewsTools
{
    public static function isAvailable(): bool
    {
        return JrData::isAvailable();
    }

    /**
     * @return Tool[]
     */
    public static function tools(): array
    {
        $ref  = ['type' => ['integer', 'string']];
        $can  = fn (string $action) => fn (ToolContext $c) => $c->user->authorise($action, 'com_content');

        $writable = [
            'summary'       => ['type' => 'string', 'description' => 'Short description shown in listings (HTML).'],
            'description'   => ['type' => 'string', 'description' => 'Full description (HTML).'],
            'fields'        => ['type' => 'object', 'description' => 'Custom field values keyed by field name (see list_listing_fields). Option fields take a value or its label; multi-value fields take an array.'],
            'featured'      => ['type' => 'boolean', 'description' => 'JReviews featured listing.'],
            'listing_email' => ['type' => 'string', 'description' => 'Contact e-mail stored with the listing.'],
            'alias'         => ['type' => 'string'],
            'language'      => ['type' => 'string'],
            'access'        => ['type' => 'integer', 'description' => 'Access level ID (1 = Public).'],
            'metakey'       => ['type' => 'string'],
            'metadesc'      => ['type' => 'string'],
            'publish_up'    => ['type' => 'string', 'description' => 'Site time zone, YYYY-MM-DD HH:MM.'],
            'publish_down'  => ['type' => 'string', 'description' => 'Site time zone, YYYY-MM-DD HH:MM.'],
        ];

        return [
            new Tool(
                'list_listing_types',
                'List the JReviews listing types with their rating criteria, field groups and the categories that use them.',
                ['properties' => [], 'additionalProperties' => false],
                [self::class, 'listTypes'],
                'List listing types',
                Tool::SCOPE_READ,
                'jreviews',
                false,
                $can('core.manage')
            ),
            new Tool(
                'list_listing_fields',
                'List the JReviews custom fields of a listing type: name, type, options and whether MCP can write them. '
                . 'Pass location=review for the fields of review forms.',
                [
                    'properties' => [
                        'listing_type' => $ref + ['description' => 'Listing type ID or title. Default: all fields.'],
                        'location'     => ['type' => 'string', 'enum' => ['listing', 'review'], 'description' => 'Default listing.'],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'listFields'],
                'List listing fields',
                Tool::SCOPE_READ,
                'jreviews',
                false,
                $can('core.manage')
            ),
            new Tool(
                'list_listings',
                'Search JReviews listings by text, category, listing type, custom field values, rating, number of reviews, author, '
                . 'featured flag, state and dates. Returns summary rows; use get_listing for all fields and ratings.',
                [
                    'properties' => JrData::filterSchema() + [
                        'order_by'  => ['type' => 'string', 'enum' => ['created', 'modified', 'title', 'hits', 'rating', 'reviews', 'id']],
                        'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                        'limit'     => ['type' => 'integer', 'description' => 'Default 20.'],
                        'offset'    => ['type' => 'integer'],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'listListings'],
                'List listings',
                Tool::SCOPE_READ,
                'jreviews',
                false,
                $can('core.manage')
            ),
            new Tool(
                'get_listing',
                'Get one JReviews listing: description, all custom field values, category, listing type, average ratings per criterion, '
                . 'review counts and the newest reviews.',
                [
                    'properties' => [
                        'id'      => ['type' => 'integer'],
                        'reviews' => ['type' => 'integer', 'description' => 'How many recent reviews to include (default 5).'],
                    ],
                    'required' => ['id'],
                ],
                [self::class, 'getListing'],
                'Get listing',
                Tool::SCOPE_READ,
                'jreviews',
                false,
                $can('core.manage')
            ),
            new Tool(
                'list_reviews',
                'List JReviews reviews with their ratings, author, state and the listing they belong to. Reviews are read-only through MCP.',
                [
                    'properties' => [
                        'listing'    => ['type' => 'integer', 'description' => 'Only reviews of this listing.'],
                        'author'     => $ref + ['description' => 'User ID, username or name.'],
                        'state'      => ['type' => 'string', 'enum' => array_merge(array_keys(JrData::REVIEW_STATES), ['any']), 'description' => 'Default published.'],
                        'type'       => ['type' => 'string', 'enum' => ['user', 'editor'], 'description' => 'User reviews or editor reviews.'],
                        'min_rating' => ['type' => 'number'],
                        'max_rating' => ['type' => 'number'],
                        'date_from'  => ['type' => 'string', 'description' => 'YYYY-MM-DD, site time zone.'],
                        'date_to'    => ['type' => 'string'],
                        'query'      => ['type' => 'string', 'description' => 'Text in the review title or text.'],
                        'order_by'   => ['type' => 'string', 'enum' => ['created', 'rating', 'helpful']],
                        'direction'  => ['type' => 'string', 'enum' => ['asc', 'desc']],
                        'limit'      => ['type' => 'integer', 'description' => 'Default 20.'],
                        'offset'     => ['type' => 'integer'],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'listReviews'],
                'List reviews',
                Tool::SCOPE_READ,
                'jreviews',
                false,
                $can('core.manage')
            ),
            new Tool(
                'jreviews_stats',
                'Count JReviews listings grouped by category, listing type, custom field values, rating range, author or a period, '
                . 'with average ratings and review counts. Accepts the same filters as list_listings.',
                [
                    'properties' => [
                        'group_by' => ['type' => 'string', 'enum' => ['category', 'listing_type', 'field', 'rating', 'author', 'state', 'year', 'quarter', 'month']],
                        'field'    => ['type' => 'string', 'description' => 'Field name, for group_by=field.'],
                        'sort'     => ['type' => 'string', 'enum' => ['count', 'rating', 'reviews', 'key']],
                        'limit'    => ['type' => 'integer', 'description' => 'Maximum number of groups (default 100).'],
                    ] + JrData::filterSchema(),
                    'required'             => ['group_by'],
                    'additionalProperties' => false,
                ],
                [self::class, 'stats'],
                'Listing statistics',
                Tool::SCOPE_READ,
                'jreviews',
                false,
                $can('core.manage')
            ),
            new Tool(
                'create_listing',
                'Create a JReviews listing in a JReviews category, with custom field values. Call list_listing_fields for the '
                . 'category\'s listing type first. Unless "state" is given the configured default for new content is used.',
                [
                    'properties' => [
                        'title'    => ['type' => 'string'],
                        'category' => $ref + ['description' => 'JReviews category (ID, title or path).'],
                        'state'    => ['type' => 'string', 'enum' => ['published', 'draft', 'pending']],
                    ] + $writable,
                    'required'             => ['title', 'category'],
                    'additionalProperties' => false,
                ],
                [self::class, 'create'],
                'Create listing',
                Tool::SCOPE_WRITE,
                'jreviews',
                false,
                $can('core.create')
            ),
            new Tool(
                'update_listing',
                'Update a JReviews listing: title, texts, category, custom field values, featured flag and metadata. '
                . 'Only the given properties change; custom fields not listed keep their values.',
                [
                    'properties' => [
                        'id'       => ['type' => 'integer'],
                        'title'    => ['type' => 'string'],
                        'category' => $ref + ['description' => 'Move to another JReviews category (this can change the listing type).'],
                    ] + $writable,
                    'required'             => ['id'],
                    'additionalProperties' => false,
                ],
                [self::class, 'update'],
                'Update listing',
                Tool::SCOPE_WRITE,
                'jreviews',
                true,
                $can('core.edit')
            ),
        ];
    }

    /**
     * May the caller see reviews that are not published? Those are the moderation queue, which
     * the back end only shows to someone who can change the state of content.
     */
    public static function canModerateReviews(ToolContext $ctx): bool
    {
        return $ctx->user->authorise('core.edit.state', 'com_content')
            || $ctx->user->authorise('core.manage', 'com_jreviews');
    }

    // ------------------------------------------------------------------ Read

    public static function listTypes(array $args, ToolContext $ctx): array
    {
        $db         = $ctx->db;
        $categories = JrData::categories($ctx);
        $criteria   = $db->setQuery(
            $db->getQuery(true)
                ->select('criteria_id, listing_type_id, title, weight, required, ordering')
                ->from($db->quoteName('#__jreviews_criteria_ratings'))
                ->order('listing_type_id, ordering')
        )->loadObjectList();

        $ratings = [];

        foreach ($criteria as $row) {
            $ratings[(int) $row->listing_type_id][] = array_filter([
                'id'       => (int) $row->criteria_id,
                'title'    => $row->title,
                'weight'   => (int) $row->weight ?: null,
                'required' => (bool) $row->required ?: null,
            ], fn ($v) => $v !== null);
        }

        $types = [];

        foreach (JrData::listingTypes($ctx) as $type) {
            $inType = array_values(array_filter($categories, fn ($c) => $c->listing_type_id === $type->id));

            $types[] = [
                'id'               => $type->id,
                'title'            => $type->title,
                'enabled'          => (int) $type->enabled === 1,
                'rating_criteria'  => $ratings[$type->id] ?? [],
                'categories'       => array_map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'listings' => (int) $c->listing_count], $inType),
                'listing_count'    => array_sum(array_map(fn ($c) => (int) $c->listing_count, $inType)),
            ];
        }

        return ['listing_types' => $types];
    }

    public static function listFields(array $args, ToolContext $ctx): array
    {
        $type     = isset($args['listing_type']) ? JrData::listingType($ctx, $args['listing_type']) : null;
        $location = ($args['location'] ?? 'listing') === 'review' ? 'review' : 'content';
        $fields   = JrData::fields($ctx, $type?->id, $location);

        return [
            'listing_type' => $type ? ['id' => $type->id, 'title' => $type->title] : null,
            'location'     => $location === 'review' ? 'review' : 'listing',
            'fields'       => array_values(array_map([JrData::class, 'describeField'], $fields)),
            'note'         => 'Values of option fields are stored as the option value; searching and writing also accept the label.',
        ];
    }

    public static function listListings(array $args, ToolContext $ctx): array
    {
        $db    = $ctx->db;
        $query = JrData::baseQuery($ctx);

        $filters = JrData::applyFilters($ctx, $query, $args);
        $total   = (int) $db->setQuery((clone $query)->select('COUNT(DISTINCT a.id)'))->loadResult();
        $limit   = $ctx->limit($args['limit'] ?? null);
        $offset  = max(0, (int) ($args['offset'] ?? 0));
        $dir     = strtolower($args['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $order   = match ($args['order_by'] ?? 'created') {
            'rating'  => 't.user_rating',
            'reviews' => 't.user_comment_count',
            'title'   => 'a.title',
            'hits'    => 'a.hits',
            'id'      => 'a.id',
            'modified' => 'a.modified',
            default   => 'a.created',
        };

        $query->select('a.id, a.title, a.alias, a.state, a.catid, a.created, a.hits, a.language, a.created_by')
            ->select('c.title AS category_title, jc.criteriaid AS listing_type_id, u.name AS author_name')
            ->select('jr.featured AS featured_listing, t.user_rating, t.user_rating_count, t.user_comment_count, t.editor_rating, t.editor_comment_count')
            ->order($order . ' ' . $dir . ', a.id ' . $dir)
            ->setLimit($limit, $offset);

        $types = JrData::listingTypes($ctx);

        $items = array_map(fn ($r) => array_filter([
            'id'            => (int) $r->id,
            'title'         => $r->title,
            'state'         => array_search((int) $r->state, JrData::LISTING_STATES, true) ?: (string) $r->state,
            'category'      => ['id' => (int) $r->catid, 'title' => $r->category_title],
            'listing_type'  => $types[(int) $r->listing_type_id]->title ?? null,
            'author'        => $r->author_name,
            'created'       => Resolver::localDate($ctx, $r->created),
            'rating'        => (int) $r->user_rating_count > 0 ? round((float) $r->user_rating, 2) : null,
            'reviews'       => (int) $r->user_comment_count,
            'editor_rating' => (int) $r->editor_comment_count > 0 ? round((float) $r->editor_rating, 2) : null,
            'featured'      => (bool) $r->featured_listing ?: null,
            'hits'          => (int) $r->hits,
        ], fn ($v) => $v !== null), $db->setQuery($query)->loadObjectList());

        return ['total' => $total, 'offset' => $offset, 'has_more' => $offset + \count($items) < $total, 'filters' => $filters ?: null, 'listings' => $items];
    }

    public static function getListing(array $args, ToolContext $ctx): array
    {
        $id      = (int) $args['id'];
        $listing = JrData::listing($ctx, $id);

        // Same rule as list_listings; the message stays the one used for a listing that is not
        // there, so it does not confirm that this one exists
        if ((int) $listing->state !== JrData::LISTING_STATES['published'] && !JrData::canSeeUnpublished($ctx)) {
            throw new ToolException(\sprintf('Listing %d not found, or its category is not a JReviews category.', $id));
        }

        return self::listingDetails($ctx, $listing, max(0, (int) ($args['reviews'] ?? 5)));
    }

    /**
     * The full representation of a listing, also used to report back what a write changed.
     */
    private static function listingDetails(ToolContext $ctx, object $listing, int $want): array
    {
        $id      = (int) $listing->id;
        $types   = JrData::listingTypes($ctx);
        $db      = $ctx->db;

        // Average rating per criterion
        $criteria = $db->setQuery(
            $db->getQuery(true)
                ->select('cr.title, cr.ordering, lr.user_rating, lr.user_rating_count, lr.editor_rating')
                ->from($db->quoteName('#__jreviews_listing_ratings', 'lr'))
                ->join('INNER', $db->quoteName('#__jreviews_criteria_ratings', 'cr'), 'cr.criteria_id = lr.criteria_id')
                ->where('lr.listing_id = ' . $id)
                ->where('lr.extension = ' . $db->quote('com_content'))
                ->order('cr.ordering')
        )->loadObjectList();

        $reviews = $want > 0 ? self::listReviews(['listing' => $id, 'limit' => $want], $ctx)['reviews'] : [];

        return [
            'id'           => $id,
            'title'        => $listing->title,
            'alias'        => $listing->alias,
            'state'        => array_search((int) $listing->state, JrData::LISTING_STATES, true) ?: (string) $listing->state,
            'category'     => ['id' => (int) $listing->catid, 'title' => $listing->category_title, 'path' => $listing->category_path],
            'listing_type' => ['id' => $listing->listing_type_id, 'title' => $types[$listing->listing_type_id]->title ?? null],
            'author'       => ['id' => (int) $listing->created_by, 'name' => $listing->author_name, 'alias' => $listing->created_by_alias ?: null],
            'created'      => Resolver::localDate($ctx, $listing->created),
            'modified'     => Resolver::localDate($ctx, $listing->modified),
            'publish_up'   => Resolver::localDate($ctx, $listing->publish_up),
            'publish_down' => Resolver::localDate($ctx, $listing->publish_down),
            'featured'     => (bool) $listing->featured_listing,
            'language'     => $listing->language,
            'hits'         => (int) $listing->hits,
            'contact_email' => $listing->listing_email ?: null,
            'ratings'      => [
                'user'     => ['average' => (int) $listing->user_rating_count > 0 ? round((float) $listing->user_rating, 2) : null, 'reviews' => (int) $listing->user_comment_count],
                'editor'   => ['average' => (int) $listing->editor_comment_count > 0 ? round((float) $listing->editor_rating, 2) : null, 'reviews' => (int) $listing->editor_comment_count],
                'criteria' => array_map(fn ($c) => [
                    'title'   => $c->title,
                    'average' => (int) $c->user_rating_count > 0 ? round((float) $c->user_rating, 2) : null,
                    'reviews' => (int) $c->user_rating_count,
                ], $criteria),
            ],
            'fields'       => JrData::listingFieldValues($ctx, $listing),
            'summary'      => $listing->introtext,
            'description'  => $listing->fulltext,
            'metakey'      => $listing->metakey,
            'metadesc'     => $listing->metadesc,
            'recent_reviews' => $reviews,
            'url'          => rtrim(Uri::root(), '/') . '/index.php?option=com_content&view=article&id=' . $id . '&catid=' . (int) $listing->catid,
            'edit_url'     => rtrim(Uri::root(), '/') . '/administrator/index.php?option=com_content&task=article.edit&id=' . $id,
        ];
    }

    public static function listReviews(array $args, ToolContext $ctx): array
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->from($db->quoteName('#__jreviews_comments', 'r'))
            // INNER: a review whose listing is missing would otherwise skip the visibility filter
            ->join('INNER', $db->quoteName('#__content', 'a'), 'a.id = r.pid')
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = r.userid')
            ->where('r.mode = ' . $db->quote('com_content'));

        // A review is as visible as the listing it belongs to: without this the review text,
        // the reviewer and the listing title leak from categories the server is confined away from
        Resolver::applyArticleVisibility($ctx, $query);

        $state       = $args['state'] ?? 'published';
        $canModerate = self::canModerateReviews($ctx);

        // "any" and every state but published expose the moderation queue, so they need the
        // permission that shows that queue in the back end
        if ($state !== 'published' && !$canModerate) {
            throw new ToolException('Listing reviews other than published ones needs "Edit State" on com_content or "Access Administration Interface" on com_jreviews.');
        }

        if ($state !== 'any') {
            $query->where('r.published = ' . (int) JrData::REVIEW_STATES[$state]);
        }

        // The listing itself is an article, so who may see an unpublished one is decided by the
        // article rule, not by the review moderation permission
        if (!JrData::canSeeUnpublished($ctx)) {
            $query->where('a.state = 1');
        }

        if (!empty($args['listing'])) {
            $query->where('r.pid = ' . (int) $args['listing']);
        }

        if (isset($args['author'])) {
            $query->where('r.userid = ' . Resolver::userId($ctx, $args['author']));
        }

        if (isset($args['type'])) {
            $query->where('r.author = ' . ($args['type'] === 'editor' ? 1 : 0));
        }

        foreach ([['min_rating', '>='], ['max_rating', '<=']] as [$key, $operator]) {
            if (isset($args[$key])) {
                $query->where('r.rating ' . $operator . ' ' . (float) $args[$key]);
            }
        }

        if (!empty($args['date_from'])) {
            $from = Resolver::sqlDate($ctx, $args['date_from']);
            $query->where('r.created >= :from')->bind(':from', $from);
        }

        if (!empty($args['date_to'])) {
            $to = Resolver::sqlDate($ctx, $args['date_to'], true);
            $query->where('r.created <= :to')->bind(':to', $to);
        }

        if (($args['query'] ?? '') !== '') {
            $search = '%' . Resolver::likeValue(mb_strtolower($args['query'])) . '%';
            $query->where('(LOWER(r.title) LIKE :s1 OR LOWER(r.comments) LIKE :s2)')->bind(':s1', $search)->bind(':s2', $search);
        }

        $total  = (int) $db->setQuery((clone $query)->select('COUNT(*)'))->loadResult();
        $limit  = $ctx->limit($args['limit'] ?? null);
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $dir   = strtolower($args['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $order = match ($args['order_by'] ?? 'created') {
            'rating'  => 'r.rating',
            'helpful' => 'r.vote_helpful',
            default   => 'r.created',
        };

        $query->select('r.id, r.pid AS listing_id, r.title, r.comments, r.rating, r.created, r.published, r.author AS is_editor')
            ->select('r.userid, r.name AS reviewer, u.name AS user_name, r.vote_helpful, r.vote_total, r.owner_reply_text')
            ->select('a.title AS listing_title')
            ->order($order . ' ' . $dir)
            ->setLimit($limit, $offset);

        $rows = $db->setQuery($query)->loadObjectList();
        $ids  = array_map(fn ($r) => (int) $r->id, $rows);
        $byId = [];

        if ($ids) {
            $ratings = $db->setQuery(
                $db->getQuery(true)
                    ->select('rr.review_id, cr.title, rr.rating')
                    ->from($db->quoteName('#__jreviews_review_ratings', 'rr'))
                    ->join('INNER', $db->quoteName('#__jreviews_criteria_ratings', 'cr'), 'cr.criteria_id = rr.criteria_id')
                    ->whereIn('rr.review_id', $ids)
                    ->where('rr.extension = ' . $db->quote('com_content'))
                    ->order('cr.ordering')
            )->loadObjectList();

            foreach ($ratings as $row) {
                $byId[(int) $row->review_id][$row->title] = round((float) $row->rating, 2);
            }
        }

        $reviews = array_map(fn ($r) => array_filter([
            'id'              => (int) $r->id,
            'listing'         => ['id' => (int) $r->listing_id, 'title' => $r->listing_title],
            'title'           => $r->title,
            'rating'          => round((float) $r->rating, 2),
            'criteria'        => $byId[(int) $r->id] ?? null,
            'type'            => (int) $r->is_editor === 1 ? 'editor' : 'user',
            'state'           => array_search((int) $r->published, JrData::REVIEW_STATES, true) ?: (string) $r->published,
            'reviewer'        => $r->user_name ?: $r->reviewer,
            'created'         => Resolver::localDate($ctx, $r->created),
            'helpful_votes'   => (int) $r->vote_helpful ?: null,
            'total_votes'     => (int) $r->vote_total ?: null,
            'has_owner_reply' => trim((string) $r->owner_reply_text) !== '' ?: null,
            'text'            => $r->comments,
        ], fn ($v) => $v !== null && $v !== []), $rows);

        return ['total' => $total, 'offset' => $offset, 'has_more' => $offset + \count($reviews) < $total, 'reviews' => $reviews];
    }

    public static function stats(array $args, ToolContext $ctx): array
    {
        $db      = $ctx->db;
        $groupBy = $args['group_by'];
        $query   = JrData::baseQuery($ctx);
        $filters = JrData::applyFilters($ctx, $query, $args);
        $totals  = $db->setQuery((clone $query)->select('COUNT(DISTINCT a.id) AS n, AVG(NULLIF(t.user_rating, 0)) AS rating, SUM(t.user_comment_count) AS reviews'))->loadObject();
        $periods = ['year' => '%Y', 'quarter' => 'Q', 'month' => '%Y-%m'];
        $types   = JrData::listingTypes($ctx);
        $labels  = [];

        switch (true) {
            case isset($periods[$groupBy]):
                $expression = $groupBy === 'quarter'
                    ? 'CONCAT(YEAR(a.created), "-Q", QUARTER(a.created))'
                    : 'DATE_FORMAT(a.created, ' . $db->quote($periods[$groupBy]) . ')';
                $query->select($expression . ' AS k')->group('k');
                break;

            case $groupBy === 'category':
                $query->select('a.catid AS k, c.title AS label')->group('a.catid, c.title');
                break;

            case $groupBy === 'listing_type':
                $query->select('jc.criteriaid AS k')->group('jc.criteriaid');
                $labels = array_map(fn ($t) => $t->title, $types);
                break;

            case $groupBy === 'author':
                $query->select('a.created_by AS k, u.name AS label')->group('a.created_by, u.name');
                break;

            case $groupBy === 'state':
                $query->select('a.state AS k')->group('a.state');
                break;

            case $groupBy === 'rating':
                $query->select('FLOOR(COALESCE(t.user_rating, 0)) AS k')->group('k');
                break;

            case $groupBy === 'field':
                if (empty($args['field'])) {
                    throw new ToolException('group_by=field needs "field" (a field name, see list_listing_fields).');
                }

                $field = JrData::fieldByName($ctx, $args['field']);
                $query->select($db->quoteName('jr.' . JrData::assertFieldName($field->name)) . ' AS k')->group('k');

                foreach ($field->fieldOptions ?? [] as $option) {
                    $labels[(string) $option['value']] = $option['label'];
                }
                break;
        }

        $query->select('COUNT(DISTINCT a.id) AS n, AVG(NULLIF(t.user_rating, 0)) AS rating, SUM(t.user_comment_count) AS reviews');

        $groups = array_map(function ($row) use ($groupBy, $labels) {
            $key   = $row->k;
            $label = $row->label ?? ($groupBy === 'field' ? null : ($labels[(string) $row->k] ?? null));

            if ($groupBy === 'state') {
                $label = array_search((int) $row->k, JrData::LISTING_STATES, true) ?: null;
            } elseif ($groupBy === 'rating') {
                $label = (int) $row->k === 0 ? 'not rated' : (int) $row->k . '–' . ((int) $row->k + 1);
            } elseif ($groupBy === 'field') {
                // Option fields are stored as *value* or *a*b*; show the option labels instead
                $parts = array_values(array_filter(explode('*', (string) $key), fn ($v) => $v !== ''));
                $label = $parts
                    ? implode(', ', array_map(fn ($v) => $labels[$v] ?? $v, $parts))
                    : 'not set';
            }

            return array_filter([
                'key'        => is_numeric($key) ? $key + 0 : $key,
                'label'      => $label,
                'count'      => (int) $row->n,
                'avg_rating' => $row->rating !== null ? round((float) $row->rating, 2) : null,
                'reviews'    => (int) $row->reviews,
            ], fn ($v) => $v !== null);
        }, $db->setQuery($query)->loadObjectList());

        $sort = $args['sort'] ?? (isset($periods[$groupBy]) ? 'key' : 'count');

        usort($groups, fn ($a, $b) => match ($sort) {
            'key'     => strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? '')),
            'rating'  => ($b['avg_rating'] ?? 0) <=> ($a['avg_rating'] ?? 0),
            'reviews' => $b['reviews'] <=> $a['reviews'],
            default   => $b['count'] <=> $a['count'],
        });

        return array_filter([
            'group_by'       => $groupBy === 'field' ? 'field:' . $args['field'] : $groupBy,
            'filters'        => $filters ?: null,
            'total_listings' => (int) $totals->n,
            'average_rating' => $totals->rating !== null ? round((float) $totals->rating, 2) : null,
            'total_reviews'  => (int) $totals->reviews,
            'group_count'    => \count($groups),
            'groups'         => \array_slice($groups, 0, max(1, min((int) ($args['limit'] ?? 100), 1000))),
            'note'           => $groupBy === 'field' ? 'Multi-value fields are grouped by their stored combination of values.' : null,
        ], fn ($v) => $v !== null);
    }

    // ------------------------------------------------------------------ Write

    public static function create(array $args, ToolContext $ctx): array
    {
        [$id, $warning] = JrWriter::create($ctx, $args);

        return array_filter(['result' => 'created', 'warning' => $warning])
            + self::listingDetails($ctx, JrData::listing($ctx, $id), 0);
    }

    public static function update(array $args, ToolContext $ctx): array
    {
        $id      = (int) $args['id'];
        $warning = JrWriter::update($ctx, $id, $args);

        return array_filter(['result' => 'updated', 'warning' => $warning])
            + self::listingDetails($ctx, JrData::listing($ctx, $id), 0);
    }
}
