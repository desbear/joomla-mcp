<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Mcp;

\defined('_JEXEC') or die;

/**
 * An error that is reported back to the model as a tool result (isError: true).
 *
 * The message should tell the model how to correct the call, e.g. list valid values.
 *
 * @since  0.1.0
 */
class ToolException extends \RuntimeException
{
}
