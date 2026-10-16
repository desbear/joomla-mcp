<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Helper;

use Joomla\CMS\Menu\AbstractMenu;

\defined('_JEXEC') or die;

/**
 * An empty menu for the API application: it has no menu items, so nothing is ever active.
 *
 * @since  0.5.0
 */
class ApiMenu extends AbstractMenu
{
    public function load()
    {
        return true;
    }
}
