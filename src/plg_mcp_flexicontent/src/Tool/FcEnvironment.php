<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_flexicontent
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Flexicontent\Tool;

use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;
use Gss\Component\Mcp\Administrator\Helper\ApiCompat;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Table\Table;

\defined('_JEXEC') or die;

/**
 * Prepares the environment FLEXIcontent's models expect, when running inside the API application.
 *
 * FLEXIcontent is a legacy component without a service provider; its own entry point
 * (admin/admin.flexicontent.php) loads helpers and constants by hand. The same is done here,
 * plus two things the API application lacks: a menu (the item model asks for the active menu
 * item) and admin form paths (the API application's JPATH_BASE is /api).
 *
 * @since  0.4.0
 */
final class FcEnvironment
{
    private static bool $booted = false;

    public static function isAvailable(): bool
    {
        return ComponentHelper::isInstalled('com_flexicontent')
            && ComponentHelper::isEnabled('com_flexicontent')
            && is_file(JPATH_ADMINISTRATOR . '/components/com_flexicontent/defineconstants.php');
    }

    /**
     * Load FLEXIcontent's classes. Needed before using its models or field helpers.
     */
    public static function boot(ToolContext $ctx): void
    {
        if (self::$booted) {
            return;
        }

        if (!self::isAvailable()) {
            throw new ToolException('FLEXIcontent is not installed or not enabled.');
        }

        $app  = $ctx->app;
        $site = JPATH_SITE . '/components/com_flexicontent';

        // The item model reads the active menu item; the API application has no menu of its own
        ApiCompat::apply($app);

        // A file missing from a partial install would be an uncatchable fatal error, so the MCP
        // client would get a broken response instead of a JSON-RPC error - and the message would
        // leak the absolute path whenever display_errors is on
        foreach ([
            JPATH_ADMINISTRATOR . '/components/com_flexicontent/defineconstants.php',
            $site . '/classes/flexicontent.helper.php',
            $site . '/classes/flexicontent.categories.php',
            $site . '/classes/flexicontent.fields.php',
            $site . '/helpers/permission.php',
            $site . '/helpers/route.php',
        ] as $file) {
            if (!is_file($file)) {
                throw new ToolException(\sprintf(
                    'FLEXIcontent is not installed completely: %s is missing.',
                    // Relative to the site root: the absolute path is not the client's business
                    ltrim(str_replace([JPATH_ADMINISTRATOR, JPATH_SITE], ['administrator', ''], $file), '/')
                ));
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

        $lang = $app->getLanguage();
        $lang->load('com_flexicontent', JPATH_ADMINISTRATOR);
        $lang->load('com_flexicontent', JPATH_SITE);
        $lang->load('com_content', JPATH_ADMINISTRATOR);

        PluginHelper::importPlugin('flexicontent');

        self::$booted = true;
    }

    /**
     * Run code with the request looking like a FLEXIcontent back-end save, so that FLEXIcontent
     * triggers the usual content events (search index, action log, ...), then restore it.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function asFlexicontentRequest(ToolContext $ctx, int $id, callable $callback): mixed
    {
        $input = $ctx->app->getInput();
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
