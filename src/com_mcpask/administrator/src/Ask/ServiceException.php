<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Ask;

\defined('_JEXEC') or die;

/**
 * The AI service could not be reached or refused the request. The message is the provider's and
 * is meant for the administrator and the log, never for a visitor.
 *
 * @since  0.1.0
 */
final class ServiceException extends \RuntimeException
{
    /**
     * @param  bool  $temporary  The service was down, overloaded or out of time (5xx, 429, a timeout, no
     *                           connection), so another service may well succeed; not for a refused request
     * @param  bool  $partial    Part of an answer had already been handed over when it failed
     */
    public function __construct(string $message, public readonly bool $temporary = false, public readonly bool $partial = false)
    {
        parent::__construct($message);
    }
}
