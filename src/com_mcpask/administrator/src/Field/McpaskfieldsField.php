<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Field;

use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Gss\Component\Mcpask\Administrator\Ask\Source\Sources;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The text custom fields of the site's content to choose from: FLEXIcontent's when it is
 * installed, Joomla's own otherwise.
 *
 * @since  0.1.0
 */
class McpaskfieldsField extends ListField
{
    protected $type = 'Mcpaskfields';

    protected function getOptions()
    {
        $options = [];

        try {
            $db     = Factory::getContainer()->get(DatabaseInterface::class);
            $fields = Sources::current($db, Settings::load())->textFields();
        } catch (\Throwable) {
            $fields = [];
        }

        // A field that was chosen and has since been unpublished or renamed stays listed, so that
        // saving the options does not silently widen what is read
        foreach ((array) $this->value as $name) {
            if (\is_string($name) && $name !== '' && !isset($fields[$name])) {
                $fields[$name] = $name;
            }
        }

        foreach ($fields as $name => $label) {
            $options[] = (object) [
                'value' => $name,
                'text'  => $label !== '' && $label !== $name ? $label . ' (' . $name . ')' : $name,
            ];
        }

        return array_merge(parent::getOptions(), $options);
    }
}
