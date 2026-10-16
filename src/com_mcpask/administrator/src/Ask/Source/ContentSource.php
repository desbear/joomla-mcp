<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Ask\Source;

use Gss\Component\Mcpask\Administrator\Ask\Document;
use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

\defined('_JEXEC') or die;

/**
 * Joomla articles.
 *
 * @since  0.1.0
 */
class ContentSource implements SourceInterface
{
    /** Custom field types whose value is text worth reading. */
    protected const TEXT_FIELDS = ['text', 'textarea', 'editor'];

    public function __construct(protected DatabaseInterface $db, protected Settings $settings)
    {
    }

    public function name(): string
    {
        return 'content';
    }

    public function ids(int $afterId = 0, int $limit = 0): array
    {
        $query = $this->eligible()->select('a.id')->where('a.id > ' . $afterId)->order('a.id');

        if ($limit > 0) {
            $query->setLimit($limit);
        }

        return array_map('intval', $this->db->setQuery($query)->loadColumn());
    }

    public function visible(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (!$ids) {
            return [];
        }

        $query = $this->eligible()->select('a.id')->whereIn('a.id', $ids);

        return array_map('intval', $this->db->setQuery($query)->loadColumn());
    }

    public function modified(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (!$ids) {
            return [];
        }

        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('id')
            // A date that was never set reads as empty or as the zero date
            ->select('CASE WHEN modified IS NULL OR modified < ' . $db->quote('1971-01-01') . ' THEN created ELSE modified END AS changed')
            ->from($db->quoteName('#__content'))
            ->whereIn('id', $ids);

        return array_map('strval', $db->setQuery($query)->loadAssocList('id', 'changed'));
    }

    public function load(int $id): ?Document
    {
        $query = $this->eligible()
            ->select('a.id, a.title, a.alias, a.catid, a.language, a.access, a.introtext, a.fulltext')
            ->where('a.id = :id')
            ->bind(':id', $id, ParameterType::INTEGER);
        $row   = $this->db->setQuery($query)->loadObject();

        if (!$row) {
            return null;
        }

        $html = $row->introtext . "\n\n" . $row->fulltext;

        if ($this->settings->includeFields()) {
            // Before the body and without a heading: a field such as a summary or a subtitle belongs
            // to the page as a whole, and its label would be shown to visitors as a section of it
            foreach (array_reverse($this->fields($row)) as $field) {
                $html = '<div>' . $field->value . "</div>\n\n" . $html;
            }
        }

        return new Document($this->name(), (int) $row->id, (string) $row->title, $this->link($row), (string) $row->language ?: '*', (int) $row->access, $html);
    }

    public function url(string $link): string
    {
        try {
            return Route::link('site', $link, false, Route::TLS_IGNORE, true);
        } catch (\Throwable) {
            return rtrim(Uri::root(), '/') . '/' . $link;
        }
    }

    public function textFields(): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('f.name, f.title, f.label')
            ->from($db->quoteName('#__fields', 'f'))
            ->where('f.context = ' . $db->quote('com_content.article'))
            ->where('f.state = 1')
            ->whereIn('f.type', self::TEXT_FIELDS, ParameterType::STRING)
            ->order('f.ordering, f.title');
        $fields = [];

        foreach ($db->setQuery($query)->loadObjectList() as $field) {
            $fields[(string) $field->name] = (string) ($field->label ?: $field->title);
        }

        return $fields;
    }

    /**
     * View levels of a visitor who is not signed in.
     *
     * @return  int[]
     */
    protected function levels(): array
    {
        return array_values(array_unique(array_map('intval', Access::getAuthorisedViewLevels(0)))) ?: [1];
    }

    /**
     * Published articles in published categories that a guest may read, within the categories the
     * component is limited to. Without a select list: the caller adds what it needs.
     */
    protected function eligible(): QueryInterface
    {
        $db     = $this->db;
        $now    = $db->quote(Factory::getDate()->toSql());
        $levels = implode(',', $this->levels());
        $never  = $db->getServerType() === 'mysql' ? ' OR a.publish_down = ' . $db->quote($db->getNullDate()) : '';

        $query = $db->getQuery(true)
            ->from($db->quoteName('#__content', 'a'))
            ->join('INNER', $db->quoteName('#__categories', 'c'), 'c.id = a.catid')
            ->where('a.state = 1')
            ->where('c.published = 1')
            ->where('a.access IN (' . $levels . ')')
            ->where('c.access IN (' . $levels . ')')
            ->where('(a.publish_up IS NULL OR a.publish_up <= ' . $now . ')')
            // Sites that came from Joomla 3 may still hold the zero date for "never"
            ->where('(a.publish_down IS NULL OR a.publish_down >= ' . $now . $never . ')')
            // A category inside an unpublished or not public one is not public either
            ->where(
                'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__categories', 'up')
                . ' WHERE up.lft < c.lft AND up.rgt > c.rgt AND up.extension = ' . $db->quote('com_content')
                . ' AND (up.published <> 1 OR up.access NOT IN (' . $levels . ')))'
            );

        if ($categories = $this->settings->categories()) {
            // The chosen categories and everything below them
            $query->where(
                'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__categories', 'p')
                . ' WHERE p.id IN (' . implode(',', $categories) . ') AND c.lft >= p.lft AND c.rgt <= p.rgt)'
            );
        }

        return $query;
    }

    protected function link(object $row): string
    {
        $link = 'index.php?option=com_content&view=article&id=' . (int) $row->id . ':' . $row->alias . '&catid=' . (int) $row->catid;

        return $row->language && $row->language !== '*' ? $link . '&lang=' . $row->language : $link;
    }

    /**
     * Text custom fields a guest sees on the page, with their values. The rules are Joomla's own:
     * the field and its group are published and public, the field belongs to the article's category
     * (a value can outlive that), it is displayed automatically, and not in the back end only.
     *
     * @param   object  $row  The article: id, catid
     *
     * @return  array<int, object{value: string}>
     */
    protected function fields(object $row): array
    {
        $db     = $this->db;
        $levels = implode(',', $this->levels());
        $itemId = (string) (int) $row->id;
        $catId  = (int) $row->catid;
        $query  = $db->getQuery(true)
            ->select('f.name, f.params, v.value')
            ->from($db->quoteName('#__fields_values', 'v'))
            ->join('INNER', $db->quoteName('#__fields', 'f'), 'f.id = v.field_id')
            ->join('LEFT', $db->quoteName('#__fields_groups', 'g'), 'g.id = f.group_id')
            ->where('f.context = ' . $db->quote('com_content.article'))
            ->where('f.state = 1')
            ->where('f.access IN (' . $levels . ')')
            ->where('(f.group_id = 0 OR (g.state = 1 AND g.access IN (' . $levels . ')))')
            ->where(
                '(NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__fields_categories', 'fc') . ' WHERE fc.field_id = f.id)'
                . ' OR EXISTS (SELECT 1 FROM ' . $db->quoteName('#__fields_categories', 'fc')
                . ' INNER JOIN ' . $db->quoteName('#__categories', 'p') . ' ON p.id = fc.category_id'
                . ' INNER JOIN ' . $db->quoteName('#__categories', 'c') . ' ON c.id = ' . $catId
                . ' WHERE fc.field_id = f.id AND p.lft <= c.lft AND p.rgt >= c.rgt))'
            )
            ->whereIn('f.type', self::TEXT_FIELDS, ParameterType::STRING)
            ->where('v.item_id = :item')
            ->where('v.value <> ' . $db->quote(''))
            ->order('f.ordering')
            ->bind(':item', $itemId);

        return array_values(array_filter($db->setQuery($query)->loadObjectList(), function ($field) {
            $params = json_decode((string) $field->params, true) ?: [];

            // "Do not automatically display", and "show on: administrator" (2)
            return $this->wanted((string) $field->name)
                && (string) ($params['display'] ?? '2') !== '0'
                && (string) ($params['show_on'] ?? '') !== '2';
        }));
    }

    /**
     * Whether a field is among the ones the administrator chose, when a choice was made.
     */
    protected function wanted(string $name): bool
    {
        $only = $this->settings->fieldNames();

        return !$only || \in_array(strtolower($name), $only, true);
    }
}
