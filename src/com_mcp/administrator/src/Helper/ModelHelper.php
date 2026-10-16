<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Helper;

use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Joomla\CMS\Form\Form;

\defined('_JEXEC') or die;

/**
 * Creates core back-end models so they work inside the API application.
 *
 * @since  0.1.0
 */
final class ModelHelper
{
    /**
     * @param   string  $component  e.g. "com_content"
     * @param   string  $name       Model name, e.g. "Article"
     * @param   array   $input      Request variables some models read (id, extension, ...)
     */
    public static function admin(ToolContext $ctx, string $component, string $name, array $input = []): object
    {
        $app = $ctx->app;
        $app->getLanguage()->load($component, JPATH_ADMINISTRATOR);

        // In the API application JPATH_COMPONENT is com_mcp, so point the form loader at the component's forms
        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/' . $component . '/forms');
        Form::addFieldPath(JPATH_ADMINISTRATOR . '/components/' . $component . '/src/Field');

        // The request input holds the JSON-RPC message; models must not read its "id" as a record ID
        foreach ($input + ['id' => 0] as $key => $value) {
            $app->getInput()->set($key, $value);
        }

        return $app->bootComponent($component)->getMVCFactory()
            ->createModel($name, 'Administrator', ['ignore_request' => true]);
    }
}
