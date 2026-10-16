<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Field;

use Gss\Component\Mcp\Administrator\Mcp\ToolRegistry;
use Joomla\CMS\Form\Field\CheckboxesField;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Checkbox list of all registered MCP tools; checked tools are disabled.
 *
 * @since  0.1.0
 */
class McptoolsField extends CheckboxesField
{
    protected $type = 'Mcptools';

    protected function getOptions()
    {
        $options = [];

        try {
            $tools = ToolRegistry::load()->all();
        } catch (\Throwable) {
            $tools = [];
        }

        foreach ($tools as $tool) {
            $options[] = (object) [
                'value'    => $tool->name,
                'text'     => \sprintf('%s (%s) — %s', $tool->name, $tool->title ?: $tool->name, Text::_('COM_MCP_SCOPE_' . strtoupper($tool->scope))),
                'disable'  => false,
                'checked'  => false,
                'class'    => '',
                'onclick'  => '',
                'onchange' => '',
            ];
        }

        return array_merge(parent::getOptions(), $options);
    }
}
