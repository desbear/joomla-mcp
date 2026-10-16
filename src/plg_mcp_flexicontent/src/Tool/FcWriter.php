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

\defined('_JEXEC') or die;

/**
 * Writes FLEXIcontent items through FLEXIcontent's own item model, so its tables (item extension,
 * categories, tags, field values, versions, search index) stay consistent and its field plugins
 * validate the values. Saving through com_content instead would wipe the custom field values.
 *
 * @since  0.4.0
 */
final class FcWriter
{
    /**
     * Save an item. For existing items $data must contain the complete current custom field
     * values (FcTools::changes() builds that), because FLEXIcontent clears fields not sent.
     *
     * @return int  The item ID
     */
    public static function save(ToolContext $ctx, array $data, ?int $newTypeId): int
    {
        FcEnvironment::boot($ctx);

        $id  = (int) $data['id'];
        $user = $ctx->user;

        if ($id) {
            $asset = 'com_content.article.' . $id;
            $own   = (int) FcData::item($ctx, $id)->created_by === (int) $user->id && $user->authorise('core.edit.own', $asset);

            if (!$own && !$user->authorise('core.edit', $asset)) {
                throw new ToolException(\sprintf('You are not allowed to edit item %d.', $id));
            }
        } elseif (!$user->authorise('core.create', 'com_content.category.' . (int) $data['catid'])) {
            throw new ToolException('You are not allowed to create items in this category.');
        }

        foreach ((array) ($data['cid'] ?? []) as $catid) {
            Resolver::assertCategoryAllowed($ctx, (int) $catid);
        }

        // Approve the new version immediately (otherwise changes of existing items only go to the version history)
        $data['vstate'] = 2;

        return FcEnvironment::asFlexicontentRequest($ctx, $id, function () use ($data, $id, $newTypeId) {
            $model = new FcItemModel();

            if ($id) {
                $model->setId($id);
            } else {
                $model->setId(0, (int) $data['catid'], $newTypeId);
            }

            // Check FLEXIcontent's own permissions too (allowed categories, edit state, field access)
            $ok    = $model->store($data, true);
            $error = $model->getError();
            $error = $error instanceof \Throwable ? $error->getMessage() : (string) $error;

            if (!$ok) {
                throw new ToolException('FLEXIcontent refused to save the item: ' . ($error ?: 'unknown error'));
            }

            return $id ?: (int) $model->getId();
        });
    }

    /**
     * Validate and convert custom field values given by the model, merged into the current values.
     *
     * @param  array  $input    Field name => value, list of values, null or ''
     * @param  array  $current  Field name => current values (list)
     */
    public static function fieldValues(ToolContext $ctx, int $typeId, array $input, array $current): array
    {
        $fields  = array_filter(FcData::fields($ctx, $typeId), [FcData::class, 'holdsValues']);
        $members = FcData::groupMembers(FcData::fields($ctx));

        foreach ($input as $name => $value) {
            if (!isset($fields[$name])) {
                throw new ToolException(\sprintf(
                    'Field "%s" does not belong to this content type. Fields: %s.',
                    $name,
                    implode(', ', array_keys($fields)) ?: '(none)'
                ));
            }

            $field = $fields[$name];

            if (isset($members[$field->id])) {
                throw new ToolException(\sprintf('Field "%s" is part of the field group "%s" and cannot be set on its own; edit it in the back-end.', $name, $members[$field->id]));
            }

            if (!\in_array($field->field_type, FcData::WRITABLE_TYPES, true)) {
                throw new ToolException(\sprintf('Field "%s" (%s) cannot be set through MCP; edit it in the back-end.', $name, $field->field_type));
            }

            $values = $value === null || $value === '' ? [] : (\is_array($value) ? array_values($value) : [$value]);
            $multi  = \in_array($field->field_type, ['checkbox', 'checkboximage', 'selectmultiple'], true) || !empty($field->params['allow_multiple']);

            if (!$multi && \count($values) > 1) {
                throw new ToolException(\sprintf('Field "%s" takes a single value.', $name));
            }

            if ($options = FcData::options($field)) {
                $values = array_map(fn ($v) => self::optionValue($field, $options, (string) $v), $values);
            } elseif ($field->field_type === 'date') {
                $values = array_map(fn ($v) => self::date((string) $v, $name), $values);
            } elseif ($field->field_type === 'email') {
                foreach ($values as $v) {
                    if (!filter_var((string) $v, FILTER_VALIDATE_EMAIL)) {
                        throw new ToolException(\sprintf('Field "%s" needs an e-mail address, got "%s".', $name, (string) $v));
                    }
                }
            } else {
                $values = array_map(fn ($v) => \is_scalar($v) ? (string) $v : throw new ToolException(\sprintf('Field "%s" takes text values.', $name)), $values);
            }

            $current[$name] = $values;
        }

        return $current;
    }

    /**
     * Create a FLEXIcontent tag (FLEXIcontent links it to a Joomla tag when it is first used).
     */
    public static function createTag(ToolContext $ctx, string $name): int
    {
        FcEnvironment::boot($ctx);

        if (!$ctx->user->authorise('flexicontent.createtags', 'com_flexicontent') && !$ctx->user->authorise('core.admin')) {
            throw new ToolException('You are not allowed to create FLEXIcontent tags.');
        }

        $table            = \Joomla\CMS\Table\Table::getInstance('flexicontent_tags', '');
        $table->name      = trim($name);
        $table->published = 1;

        if (!$table->check() || !$table->store()) {
            throw new ToolException(\sprintf('Could not create tag "%s": %s', $name, $table->getError()));
        }

        return (int) $table->id;
    }

    /**
     * Change state and/or featured flag through FLEXIcontent's model (updates its item tables too).
     *
     * @param  int[]  $ids
     */
    public static function setState(ToolContext $ctx, array $ids, ?int $state, ?bool $featured): array
    {
        FcEnvironment::boot($ctx);

        $ids = array_values(array_unique(array_filter($ids)));

        if (!$ids) {
            throw new ToolException('No item IDs given.');
        }

        foreach ($ids as $id) {
            FcData::item($ctx, $id);

            if (!$ctx->user->authorise('core.edit.state', 'com_content.article.' . $id)) {
                throw new ToolException(\sprintf('You are not allowed to change the state of item %d.', $id));
            }
        }

        FcEnvironment::asFlexicontentRequest($ctx, $ids[0], function () use ($ids, $state, $featured) {
            $model = new FcItemModel();

            if ($state !== null) {
                foreach ($ids as $id) {
                    if (!$model->setitemstate($id, $state)) {
                        throw new ToolException(\sprintf('Could not change the state of item %d: %s', $id, (string) $model->getError()));
                    }
                }
            }

            if ($featured !== null && !$model->featured($ids, $featured ? 1 : 0)) {
                throw new ToolException('Could not change the featured flag: ' . (string) $model->getError());
            }
        });

        $db   = $ctx->db;
        $rows = $db->setQuery($db->getQuery(true)->select('id, title, state, featured')->from($db->quoteName('#__content'))->whereIn('id', $ids))->loadObjectList();

        return array_map(fn ($r) => [
            'id'       => (int) $r->id,
            'title'    => $r->title,
            'state'    => FcData::stateName((int) $r->state),
            'featured' => (bool) $r->featured,
        ], $rows);
    }

    private static function optionValue(object $field, array $options, string $given): string
    {
        foreach ($options as $option) {
            if ($option['value'] === $given) {
                return $given;
            }
        }

        foreach ($options as $option) {
            if (mb_strtolower($option['label']) === mb_strtolower($given) || mb_strtolower($option['value']) === mb_strtolower($given)) {
                return $option['value'];
            }
        }

        throw new ToolException(\sprintf(
            'Field "%s" has no option "%s". Options: %s.',
            $field->name,
            $given,
            implode(', ', array_map(fn ($o) => $o['value'] . ' (' . $o['label'] . ')', $options))
        ));
    }

    private static function date(string $value, string $name): string
    {
        $value = trim($value);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $value)) {
            throw new ToolException(\sprintf('Field "%s" needs a date as YYYY-MM-DD or YYYY-MM-DD HH:MM.', $name));
        }

        return $value;
    }
}
