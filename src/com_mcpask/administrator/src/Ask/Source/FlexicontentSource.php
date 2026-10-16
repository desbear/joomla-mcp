<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Ask\Source;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Router\Route;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

\defined('_JEXEC') or die;

/**
 * FLEXIcontent items: rows of #__content extended by #__flexicontent_items_ext, with their own
 * custom fields and their own URLs.
 *
 * @since  0.1.0
 */
final class FlexicontentSource extends ContentSource
{
    /** FLEXIcontent field types whose value is text worth reading (textarea covers the editor). */
    protected const TEXT_FIELDS = ['text', 'textarea'];

    public static function isAvailable(): bool
    {
        return ComponentHelper::isInstalled('com_flexicontent') && ComponentHelper::isEnabled('com_flexicontent');
    }

    public function name(): string
    {
        return 'flexicontent';
    }

    public function url(string $link): string
    {
        $helper = JPATH_SITE . '/components/com_flexicontent/helpers/route.php';

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        try {
            if (!class_exists('FlexicontentHelperRoute', false) && is_file($helper)) {
                require_once $helper;
            }

            // FLEXIcontent finds the menu item an item belongs under, which decides its address
            if (class_exists('FlexicontentHelperRoute', false) && isset($query['id'], $query['cid'])) {
                $link = \FlexicontentHelperRoute::getItemRoute((string) $query['id'], (int) $query['cid']);
            }

            return Route::link('site', $link, false, Route::TLS_IGNORE, true);
        } catch (\Throwable) {
            return parent::url($link);
        }
    }

    public function textFields(): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('f.name, f.label')
            ->from($db->quoteName('#__flexicontent_fields', 'f'))
            ->where('f.iscore = 0')
            ->where('f.published = 1')
            ->whereIn('f.field_type', self::TEXT_FIELDS, ParameterType::STRING)
            ->order('f.ordering, f.label');
        $fields = [];

        foreach ($db->setQuery($query)->loadObjectList() as $field) {
            $fields[(string) $field->name] = (string) $field->label;
        }

        return $fields;
    }

    protected function eligible(): QueryInterface
    {
        // FLEXIcontent gives each content type an access level of its own. (A type that is
        // unpublished only takes no new items: its items stay on the site.)
        return parent::eligible()
            ->join('INNER', $this->db->quoteName('#__flexicontent_items_ext', 'ie'), 'ie.item_id = a.id')
            ->join('INNER', $this->db->quoteName('#__flexicontent_types', 'ty'), 'ty.id = ie.type_id')
            ->where('ty.access IN (' . implode(',', $this->levels()) . ')');
    }

    protected function link(object $row): string
    {
        return 'index.php?option=com_flexicontent&view=item&cid=' . (int) $row->catid . '&id=' . (int) $row->id . ':' . $row->alias;
    }

    /**
     * Text fields of the item that are published, public, and still part of the item's content type
     * (a value can outlive the field's place in the type).
     *
     * FLEXIcontent decides in the templates of each type where a field is shown, which cannot be
     * read from here: a public field that no template shows is still read. The option "Only these
     * fields" is there for sites where that matters.
     */
    protected function fields(object $row): array
    {
        $db    = $this->db;
        $id    = (int) $row->id;
        $query = $db->getQuery(true)
            ->select('f.name, r.value')
            ->from($db->quoteName('#__flexicontent_fields_item_relations', 'r'))
            ->join('INNER', $db->quoteName('#__flexicontent_fields', 'f'), 'f.id = r.field_id')
            ->where('f.iscore = 0')
            ->where('f.published = 1')
            ->where('f.access IN (' . implode(',', $this->levels()) . ')')
            ->where(
                'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__flexicontent_fields_type_relations', 'ft')
                . ' INNER JOIN ' . $db->quoteName('#__flexicontent_items_ext', 'ie') . ' ON ie.type_id = ft.type_id'
                . ' WHERE ft.field_id = f.id AND ie.item_id = r.item_id)'
            )
            ->whereIn('f.field_type', self::TEXT_FIELDS, ParameterType::STRING)
            ->where('r.item_id = :item')
            ->where('r.value <> ' . $db->quote(''))
            ->order('f.ordering, r.valueorder, r.suborder')
            ->bind(':item', $id, ParameterType::INTEGER);

        // Serialized values belong to other field types that reuse these names; they are not prose
        return array_values(array_filter(
            $db->setQuery($query)->loadObjectList(),
            fn ($field) => $this->wanted((string) $field->name) && !preg_match('/^(a|s|i|d|b|O):\d*[:;]/', (string) $field->value)
        ));
    }
}
