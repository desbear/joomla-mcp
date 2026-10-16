<?php

/**
 * @package     Guides
 * @subpackage  com_guides
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Guides\Administrator\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The FLEXIcontent content types, for the type guides are published as; empty without FLEXIcontent.
 *
 * @since  0.1.0
 */
class GuidesfctypeField extends ListField
{
    protected $type = 'Guidesfctype';

    protected function getOptions(): array
    {
        $options = [HTMLHelper::_('select.option', '0', Text::_('JSELECT'))];

        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $types = $db->setQuery($db->getQuery(true)->select('id, name')->from($db->quoteName('#__flexicontent_types'))->order('name'))->loadObjectList();
        } catch (\Throwable) {
            // FLEXIcontent is not installed: no types to choose from
            $types = [];
        }

        foreach ($types as $type) {
            $options[] = HTMLHelper::_('select.option', (string) $type->id, $type->name);
        }

        return array_merge(parent::getOptions(), $options);
    }
}
