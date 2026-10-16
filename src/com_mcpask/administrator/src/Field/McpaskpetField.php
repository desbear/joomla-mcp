<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Field;

use Gss\Component\Mcpask\Administrator\Pet\Pets;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * What the floating button looks like: its icon, or a pet (the module's own, or one of the library).
 *
 * @since  0.12.0
 */
class McpaskpetField extends ListField
{
    protected $type = 'Mcpaskpet';

    protected function getOptions()
    {
        // Also on the module's and embeds' pages
        Factory::getApplication()->getLanguage()->load('com_mcpask', JPATH_ADMINISTRATOR);

        $options = [HTMLHelper::_('select.option', '', Text::_((string) ($this->element['none'] ?? '') ?: 'COM_MCPASK_PET_NONE'))];

        try {
            foreach ((new Pets(Factory::getContainer()->get(DatabaseInterface::class)))->choices() as $value => $title) {
                $options[] = HTMLHelper::_('select.option', $value, $title);
            }
        } catch (\Throwable) {
            // The icon only, until the component is up to date
        }

        return array_merge(parent::getOptions(), $options);
    }
}
