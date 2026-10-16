<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Mcp;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Everything a tool handler needs: the application, the authenticated user, the database,
 * the com_mcp options and, for OAuth connections, the granted scopes.
 *
 * @since  0.1.0
 */
final class ToolContext
{
    public function __construct(
        public readonly CMSApplicationInterface $app,
        public readonly User $user,
        public readonly DatabaseInterface $db,
        public readonly Registry $params,
        public readonly ?array $scopes = null
    ) {
    }

    /**
     * Whether the connection was granted a tool scope. API token connections have all scopes;
     * OAuth connections only those the user approved.
     */
    public function hasScope(string $scope): bool
    {
        return $this->scopes === null || \in_array($scope, $this->scopes, true);
    }

    /**
     * Whether the server is in read-only mode.
     */
    public function isReadOnly(): bool
    {
        // Safe by default: a fresh install, where Options has never been saved, is read-only
        // until an administrator decides otherwise. config.xml carries the same default.
        return (bool) $this->params->get('read_only', 1);
    }

    /**
     * Clamp a requested page size to the configured maximum.
     */
    public function limit(mixed $requested, int $default = 20): int
    {
        $max       = max(1, (int) $this->params->get('max_limit', 100));
        $requested = (int) ($requested ?? $default);

        return max(1, min($requested ?: $default, $max));
    }

    /**
     * Category IDs the server may touch, empty for all categories.
     *
     * @return int[]
     */
    public function allowedCategories(): array
    {
        return array_values(array_filter(array_map('intval', (array) $this->params->get('allowed_categories', []))));
    }

    /**
     * Base URL of the site front-end, with trailing slash.
     */
    public function siteUrl(): string
    {
        return rtrim(\Joomla\CMS\Uri\Uri::root(), '/') . '/';
    }
}
