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
 * A protocol level error, returned as a JSON-RPC error object.
 *
 * @since  0.1.0
 */
final class JsonRpcException extends \RuntimeException
{
}
