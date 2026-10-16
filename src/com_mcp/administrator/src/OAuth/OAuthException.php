<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\OAuth;

\defined('_JEXEC') or die;

/**
 * An OAuth error response (RFC 6749 section 5.2).
 *
 * @since  0.2.0
 */
final class OAuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description = '',
        public readonly int $status = 400
    ) {
        parent::__construct($description ?: $error);
    }

    public function toArray(): array
    {
        return array_filter(['error' => $this->error, 'error_description' => $this->getMessage() !== $this->error ? $this->getMessage() : null]);
    }
}
