<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_jreviews
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Jreviews\Tool;

use Gss\Component\Mcp\Administrator\Helper\ModelHelper;
use Gss\Component\Mcp\Administrator\Helper\Resolver;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Writes JReviews listings.
 *
 * A listing is a Joomla article plus JReviews rows. The article is written with Joomla's own
 * model, so assets, versions, workflow and the search index stay correct, and the JReviews rows
 * (custom field values in #__jreviews_content, the rating summary row) are written here.
 *
 * Reviews and ratings are not written: their summary tables have to be rebuilt by JReviews'
 * own code, so those stay read-only for now.
 *
 * @since  0.6.0
 */
final class JrWriter
{
    private const READMORE = '<hr id="system-readmore">';

    /**
     * Create a listing: article + JReviews rows.
     *
     * @return  array{0: int, 1: string|null}  The listing ID and a warning, if any
     */
    public static function create(ToolContext $ctx, array $args): array
    {
        $category = JrData::category($ctx, $args['category']);

        Resolver::assertCategoryAllowed($ctx, $category->id);

        if (!$category->listing_type_id) {
            throw new ToolException(\sprintf('Category "%s" has no listing type, so listings cannot be created in it.', $category->title));
        }

        if (!$ctx->user->authorise('core.create', 'com_content.category.' . $category->id)) {
            throw new ToolException('You are not allowed to create listings in this category.');
        }

        $state = $args['state'] ?? ((int) $ctx->params->get('new_article_state', 0) === 1 ? 'published' : 'draft');

        if ($state === 'published' && !$ctx->params->get('allow_publish', 1)) {
            throw new ToolException('Publishing is disabled on this MCP server; create the listing unpublished.');
        }

        $values = self::fieldValues($ctx, $category->listing_type_id, (array) ($args['fields'] ?? []));

        $data = [
            'id'          => 0,
            'title'       => $args['title'],
            'catid'       => $category->id,
            'state'       => JrData::LISTING_STATES[$state],
            'language'    => $args['language'] ?? '*',
            'access'      => (int) ($args['access'] ?? 1),
            'articletext' => self::text($args),
            'metakey'     => $args['metakey'] ?? '',
            'metadesc'    => $args['metadesc'] ?? '',
        ];

        foreach (['publish_up', 'publish_down'] as $key) {
            if (!empty($args[$key])) {
                $data[$key] = Resolver::sqlDate($ctx, $args[$key]);
            }
        }

        [$id, $warning] = self::saveArticle($ctx, $data);

        self::writeJreviewsRow($ctx, $id, $values, $args);
        self::ensureTotals($ctx, $id);

        return [$id, $warning];
    }

    /**
     * Update a listing: any of the article properties and custom field values.
     *
     * @return  string|null  A warning, if a plugin failed after the article was stored
     */
    public static function update(ToolContext $ctx, int $id, array $args): ?string
    {
        $listing = JrData::listing($ctx, $id);

        if (!$ctx->user->authorise('core.edit', 'com_content.article.' . $id)
            && !((int) $listing->created_by === (int) $ctx->user->id && $ctx->user->authorise('core.edit.own', 'com_content.article.' . $id))) {
            throw new ToolException(\sprintf('You are not allowed to edit listing %d.', $id));
        }

        $typeId = $listing->listing_type_id;
        $data   = ['id' => $id, 'catid' => (int) $listing->catid];

        if (isset($args['category'])) {
            $category = JrData::category($ctx, $args['category']);
            Resolver::assertCategoryAllowed($ctx, $category->id);
            $data['catid'] = $category->id;
            $typeId        = $category->listing_type_id;
        }

        foreach (['title' => 'title', 'language' => 'language', 'metakey' => 'metakey', 'metadesc' => 'metadesc', 'alias' => 'alias'] as $arg => $column) {
            if (isset($args[$arg])) {
                $data[$column] = $args[$arg];
            }
        }

        if (isset($args['access'])) {
            $data['access'] = (int) $args['access'];
        }

        foreach (['publish_up', 'publish_down'] as $key) {
            if (\array_key_exists($key, $args)) {
                $data[$key] = empty($args[$key]) ? null : Resolver::sqlDate($ctx, $args[$key]);
            }
        }

        if (isset($args['summary']) || isset($args['description'])) {
            $data['articletext'] = self::text($args, $listing);
        }

        $values = isset($args['fields']) ? self::fieldValues($ctx, $typeId, (array) $args['fields']) : [];

        $warning = null;

        if (\count($data) > 2) {
            [, $warning] = self::saveArticle($ctx, $data);
        }

        if ($values || \array_key_exists('featured', $args) || isset($args['listing_email'])) {
            self::writeJreviewsRow($ctx, $id, $values, $args);
        }

        self::ensureTotals($ctx, $id);

        return $warning;
    }

    // ------------------------------------------------------------------ Helpers

    /**
     * Article text: summary before the read more, description after it.
     */
    private static function text(array $args, ?object $listing = null): string
    {
        $summary     = $args['summary'] ?? (string) ($listing->introtext ?? '');
        $description = $args['description'] ?? (string) ($listing->fulltext ?? '');

        return trim($description) === '' ? $summary : $summary . self::READMORE . $description;
    }

    /**
     * Validate and encode custom field values.
     *
     * @return array<string, mixed>  Column => stored value
     */
    private static function fieldValues(ToolContext $ctx, int $listingTypeId, array $input): array
    {
        if (!$input) {
            return [];
        }

        $fields = JrData::fields($ctx, $listingTypeId);
        $values = [];

        foreach ($input as $name => $value) {
            if (!isset($fields[$name])) {
                throw new ToolException(\sprintf(
                    'Field "%s" does not belong to this listing type. Fields: %s.',
                    $name,
                    implode(', ', array_keys($fields)) ?: '(none)'
                ));
            }

            $field = $fields[$name];

            if (!\in_array($field->type, JrData::WRITABLE_TYPES, true)) {
                throw new ToolException(\sprintf('Field "%s" (%s) cannot be set through MCP; edit it in the back-end.', $name, $field->type));
            }

            if (\in_array($field->type, JrData::OPTION_TYPES, true) && $value !== null && $value !== '') {
                $value = self::optionValues($field, $value);
            }

            if ($field->type === 'date' && $value) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', trim((string) $value))) {
                    throw new ToolException(\sprintf('Field "%s" needs a date as YYYY-MM-DD or YYYY-MM-DD HH:MM.', $name));
                }

                $value = trim((string) $value) . (\strlen(trim((string) $value)) === 10 ? ' 00:00:00' : '');
            }

            $values[$name] = JrData::encodeValue($field, $value);
        }

        return $values;
    }

    /**
     * Map option labels to their stored values and reject unknown ones.
     */
    private static function optionValues(object $field, mixed $value): array
    {
        $wanted = array_values(array_filter(array_map('strval', (array) $value), fn ($v) => $v !== ''));

        if (\count($wanted) > 1 && !\in_array($field->type, JrData::MULTI_TYPES, true)) {
            throw new ToolException(\sprintf('Field "%s" takes a single value.', $field->name));
        }

        $result = [];

        foreach ($wanted as $given) {
            $match = null;

            foreach ($field->fieldOptions ?? [] as $option) {
                if ((string) $option['value'] === $given || mb_strtolower((string) $option['label']) === mb_strtolower($given)) {
                    $match = (string) $option['value'];
                    break;
                }
            }

            if ($match === null) {
                throw new ToolException(\sprintf(
                    'Field "%s" has no option "%s". Options: %s.',
                    $field->name,
                    $given,
                    implode(', ', array_map(fn ($o) => $o['value'] . ' (' . $o['label'] . ')', $field->fieldOptions ?? [])) ?: '(none)'
                ));
            }

            $result[] = $match;
        }

        return $result;
    }

    /**
     * Save the article through Joomla's own model, the same way the article tools do: the
     * back-end form validates and filters the data, so plugins, workflow and versions all run.
     *
     * @return  array{0: int, 1: string|null}  The article ID, and a warning if a plugin failed after the article was stored
     */
    private static function saveArticle(ToolContext $ctx, array $data): array
    {
        $id    = (int) $data['id'];
        $model = ModelHelper::admin($ctx, 'com_content', 'Article', ['id' => $id, 'a_id' => $id]);

        if ($id) {
            $item = $model->getItem($id);

            if (!$item || empty($item->id)) {
                throw new ToolException(\sprintf('Listing %d not found.', $id));
            }

            $full = method_exists($item, 'getProperties') ? $item->getProperties() : get_object_vars($item);

            foreach (['attribs', 'metadata', 'urls', 'images'] as $key) {
                if (isset($full[$key]) && \is_object($full[$key])) {
                    $full[$key] = (array) $full[$key];
                }
            }

            // getItem() returns tags as a helper object; leaving them out keeps the current tags
            unset($full['tags'], $full['introtext'], $full['fulltext']);

            $data += $full;
        }

        $form = $model->getForm($data, false);

        if (!$form) {
            throw new ToolException('Could not load the article form: ' . $model->getError());
        }

        $valid = $model->validate($form, self::toUserTime($ctx, $data));

        if ($valid === false) {
            $errors = array_map(fn ($e) => $e instanceof \Throwable ? $e->getMessage() : (string) $e, $model->getErrors());

            throw new ToolException('Validation failed: ' . implode(' ', $errors));
        }

        // The table splits articletext into introtext/fulltext; stale values would overwrite that
        if (isset($valid['articletext'])) {
            unset($valid['introtext'], $valid['fulltext']);
        }

        $before = self::saveMarker($ctx, $id);

        if ($model->save($valid)) {
            return [$id ?: (int) $model->getState($model->getName() . '.id'), null];
        }

        $error = $model->getError();
        $error = $error instanceof \Throwable ? $error->getMessage() : (string) $error;

        // Plugins run after the article is stored; if one of them fails the article is saved
        // anyway. Reporting that as a failure would make the assistant retry and create duplicates.
        $savedId = self::findSaved($ctx, $id, $before, $valid);

        if ($savedId) {
            return [$savedId, 'The listing was saved, but a plugin reported an error afterwards: ' . $error];
        }

        throw new ToolException('Could not save the listing article: ' . $error);
    }

    /**
     * A value that changes when the article is stored, used to detect a save that a later
     * plugin turned into an error.
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
     * The article form stores dates in the user's time zone, so UTC values have to be converted
     * before validation or every save would shift them by the offset.
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
     * Insert or update the #__jreviews_content row.
     *
     * @param  array<string, mixed>  $values  Custom field values
     */
    private static function writeJreviewsRow(ToolContext $ctx, int $id, array $values, array $args): void
    {
        $db      = $ctx->db;
        $columns = $values;

        if (\array_key_exists('featured', $args)) {
            // Featuring a listing puts it on the front page, so it is a publishing change even
            // though create_listing and update_listing are only gated on core.edit
            if (!$ctx->params->get('allow_publish', 1)) {
                throw new ToolException('Publishing is disabled on this MCP server; the featured flag cannot be changed.');
            }

            if (!$ctx->user->authorise('core.edit.state', 'com_content.article.' . $id)) {
                throw new ToolException(\sprintf('You are not allowed to change the featured flag of listing %d.', $id));
            }

            $columns['featured'] = $args['featured'] ? 1 : 0;
        }

        if (isset($args['listing_email'])) {
            $columns['email'] = $args['listing_email'];
        }

        $exists = (bool) $db->setQuery(
            $db->getQuery(true)->select('contentid')->from($db->quoteName('#__jreviews_content'))->where('contentid = ' . $id)
        )->loadResult();

        if (!$exists) {
            // JReviews relaxes MySQL strict mode for its own connection; through Joomla's
            // connection every column without a default has to be given a value
            $columns = array_merge(self::emptyRow($ctx, '#__jreviews_content', ['contentid']), $columns, ['contentid' => $id]);

            // insertObject() takes the object by reference, so it has to be a variable
            $row = (object) $columns;
            $db->insertObject('#__jreviews_content', $row);

            return;
        }

        if (!$columns) {
            return;
        }

        $query = $db->getQuery(true)->update($db->quoteName('#__jreviews_content'))->where('contentid = ' . $id);

        foreach ($columns as $column => $value) {
            $query->set($db->quoteName(JrData::assertFieldName((string) $column)) . ' = ' . ($value === null ? 'NULL' : $db->quote($value)));
        }

        $db->setQuery($query)->execute();
    }

    /**
     * Make sure the listing has a rating summary row, which JReviews expects to exist.
     */
    private static function ensureTotals(ToolContext $ctx, int $id): void
    {
        $db = $ctx->db;

        $exists = (bool) $db->setQuery(
            $db->getQuery(true)
                ->select('listing_id')
                ->from($db->quoteName('#__jreviews_listing_totals'))
                ->where('listing_id = ' . $id)
                ->where('extension = ' . $db->quote('com_content'))
        )->loadResult();

        if ($exists) {
            return;
        }

        $row = (object) array_merge(self::emptyRow($ctx, '#__jreviews_listing_totals', []), ['listing_id' => $id, 'extension' => 'com_content']);

        $db->insertObject('#__jreviews_listing_totals', $row);
    }

    /**
     * An empty value for every column of a table, so inserts work under MySQL strict mode.
     *
     * @param  string[]  $skip
     *
     * @return array<string, mixed>
     */
    private static function emptyRow(ToolContext $ctx, string $table, array $skip): array
    {
        $columns = $ctx->db->getTableColumns($table, false);
        $row     = [];

        foreach ($columns as $name => $meta) {
            $type  = strtolower(\is_object($meta) ? $meta->Type : (string) $meta);
            $null  = \is_object($meta) && isset($meta->Null) ? strtoupper($meta->Null) === 'YES' : true;
            $extra = strtolower(\is_object($meta) && isset($meta->Extra) ? $meta->Extra : '');
            $auto  = str_contains($extra, 'auto_increment');

            // Nullable columns and auto-increment keys need no value; the rest do under strict mode
            if ($null || $auto || \in_array($name, $skip, true)) {
                continue;
            }

            $row[$name] = match (true) {
                (bool) preg_match('/int|decimal|float|double|bit/', $type) => 0,
                (bool) preg_match('/datetime|timestamp/', $type)           => '1970-01-01 00:00:00',
                (bool) preg_match('/^date/', $type)                        => '1970-01-01',
                default                                                    => '',
            };
        }

        return $row;
    }
}
