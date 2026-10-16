<?php

/**
 * @package     Guides
 * @subpackage  com_guides
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Guides\Administrator\Guide;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Table\Table;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Publishing a guide as a FLEXIcontent item, through FLEXIcontent's own item model, so its
 * permissions, versions and relations (categories, tags, type) are kept as when an editor saves.
 *
 * @since  0.1.0
 */
final class Flexicontent
{
    private static bool $booted = false;

    public static function isAvailable(): bool
    {
        return ComponentHelper::isInstalled('com_flexicontent')
            && ComponentHelper::isEnabled('com_flexicontent')
            && is_file(JPATH_ADMINISTRATOR . '/components/com_flexicontent/defineconstants.php');
    }

    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        $site = JPATH_SITE . '/components/com_flexicontent';

        // A file missing from a partial install would be a fatal error, not a message
        foreach ([
            JPATH_ADMINISTRATOR . '/components/com_flexicontent/defineconstants.php',
            $site . '/classes/flexicontent.helper.php',
            $site . '/classes/flexicontent.categories.php',
            $site . '/classes/flexicontent.fields.php',
            $site . '/helpers/permission.php',
            $site . '/helpers/route.php',
        ] as $file) {
            if (!is_file($file)) {
                throw new \RuntimeException('FLEXIcontent is not installed completely: ' . ltrim(str_replace([JPATH_ADMINISTRATOR, JPATH_SITE], ['administrator', ''], $file), '/') . ' is missing.');
            }

            require_once $file;
        }

        Table::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_flexicontent/tables');
        \JLoader::register('FlexicontentModelItem', JPATH_ADMINISTRATOR . '/components/com_flexicontent/models/item.php');
        \JLoader::register('ParentClassItem', JPATH_ADMINISTRATOR . '/components/com_flexicontent/models/parentclassitem.php');

        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_flexicontent/models/forms');
        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_flexicontent/forms');
        Form::addFieldPath(JPATH_ADMINISTRATOR . '/components/com_flexicontent/elements');
        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_content/forms');

        $lang = Factory::getApplication()->getLanguage();
        $lang->load('com_flexicontent', JPATH_ADMINISTRATOR);
        $lang->load('com_flexicontent', JPATH_SITE);
        $lang->load('com_content', JPATH_ADMINISTRATOR);

        PluginHelper::importPlugin('flexicontent');

        self::$booted = true;
    }

    /**
     * Create or update an item.
     *
     * @param   array{id: int, type_id: int, title: string, catid: int, state: int, access: int, language: string, text: string, tags: int[], metadesc: string}  $data
     *
     * @return  int  The item's ID
     */
    public static function save(array $data, User $user): int
    {
        self::boot();

        $id = (int) $data['id'];

        if ($id ? !$user->authorise('core.edit', 'com_content.article.' . $id) : !$user->authorise('core.create', 'com_content.category.' . (int) $data['catid'])) {
            throw new \RuntimeException($id ? 'Not allowed to update item ' . $id . '.' : 'Not allowed to create items in this category.');
        }

        $item = [
            'id'       => $id,
            'type_id'  => (int) $data['type_id'],
            'title'    => $data['title'],
            'catid'    => (int) $data['catid'],
            'cid'      => [(int) $data['catid']],
            'state'    => (int) $data['state'],
            'access'   => (int) $data['access'],
            'language' => $data['language'],
            'text'     => $data['text'],
            'tag'      => self::fcTags($data['tags']),
            'metadesc' => $data['metadesc'],
            'custom'   => [],
            // The new version is the current one at once, not only an entry in the history
            'vstate'   => 2,
        ];

        // FLEXIcontent stores a new item without some columns its own form sends, which a
        // database in strict mode (as Joomla 4 and later set it) refuses: give each column that
        // needs a value and has no default an empty one, and the item its creation date as its
        // modification date, as Joomla's content table does
        $required   = self::requiredColumns();
        $dispatcher = Factory::getApplication()->getDispatcher();
        $listener   = static function ($event) use ($required) {
            $record = $event instanceof \Joomla\Event\EventInterface ? $event->getArgument(0) : null;

            if (!\is_object($record) || !empty($record->id)) {
                return;
            }

            if (empty($record->modified)) {
                $record->modified = $record->created ?: Factory::getDate()->toSql();
            }

            foreach ($required as $column => $empty) {
                if (!isset($record->$column)) {
                    $record->$column = $empty;
                }
            }
        };

        $dispatcher->addListener('onBeforeSaveItem', $listener);

        try {
            return self::store($item, $id);
        } finally {
            $dispatcher->removeListener('onBeforeSaveItem', $listener);
        }
    }

    private static function store(array $item, int $id): int
    {
        return self::asItemRequest($id, function () use ($item, $id) {
            $model = new FcItemModel();

            if ($id) {
                $model->setId($id);
            } else {
                $model->setId(0, $item['catid'], $item['type_id']);
            }

            if (!$model->store($item, true)) {
                $error = $model->getError();

                throw new \RuntimeException('FLEXIcontent refused to save the item: ' . (($error instanceof \Throwable ? $error->getMessage() : (string) $error) ?: 'unknown error'));
            }

            return $id ?: (int) $model->getId();
        });
    }

    /**
     * Columns of the tables an item is stored in that need a value and have none by default,
     * with the empty value of each.
     *
     * @return  array<string, string|int>
     */
    private static function requiredColumns(): array
    {
        $db       = Factory::getContainer()->get(DatabaseInterface::class);
        $required = [];

        foreach (['#__content', '#__flexicontent_items_ext', '#__flexicontent_items_tmp'] as $table) {
            try {
                $columns = $db->getTableColumns($table, false);
            } catch (\Throwable) {
                continue;
            }

            foreach ($columns as $name => $column) {
                if ($column->Null !== 'NO' || $column->Default !== null || str_contains((string) $column->Extra, 'auto_increment') || isset($required[$name])) {
                    continue;
                }

                $type            = strtolower((string) $column->Type);
                $required[$name] = match (true) {
                    str_contains($type, 'int'), str_contains($type, 'decimal'), str_contains($type, 'float'), str_contains($type, 'double') => 0,
                    str_contains($type, 'date'), str_contains($type, 'time') => Factory::getDate()->toSql(),
                    \in_array($name, ['images', 'urls', 'attribs', 'metadata'], true) => '{}',
                    default => '',
                };
            }
        }

        // What the item is, not something to fill
        unset($required['id'], $required['item_id'], $required['type_id'], $required['catid'], $required['title']);

        return $required;
    }

    /**
     * FLEXIcontent keeps tags of its own, each linked to a Joomla tag: the guide's Joomla tags
     * as FLEXIcontent's. A Joomla tag FLEXIcontent has no tag for is left out.
     *
     * @param   int[]  $joomlaTags
     *
     * @return  int[]
     */
    private static function fcTags(array $joomlaTags): array
    {
        $ids = array_values(array_filter(array_map('intval', $joomlaTags)));

        if (!$ids) {
            return [];
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            return array_map('intval', $db->setQuery(
                $db->getQuery(true)
                    ->select('id')
                    ->from($db->quoteName('#__flexicontent_tags'))
                    ->whereIn('jtag_id', $ids)
            )->loadColumn());
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * FLEXIcontent's model reads the request as if its own item form were being saved.
     */
    private static function asItemRequest(int $id, callable $callback): mixed
    {
        $input = Factory::getApplication()->getInput();
        $keys  = ['option', 'view', 'task', 'id', 'cid', 'isflexicontent', 'unique_tmp_itemid'];
        $saved = [];

        foreach ($keys as $key) {
            $saved[$key] = $input->get($key, null, 'raw');
        }

        $input->set('option', 'com_flexicontent');
        $input->set('view', 'item');
        $input->set('task', '');
        $input->set('id', $id);
        $input->set('cid', [$id]);
        $input->set('unique_tmp_itemid', $id);

        try {
            return $callback();
        } finally {
            foreach ($saved as $key => $value) {
                $input->set($key, $value);
            }
        }
    }
}
