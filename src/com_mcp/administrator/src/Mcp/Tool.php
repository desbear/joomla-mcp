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
 * A single MCP tool.
 *
 * Tools are registered by plugins of the "mcp" group through the onMcpRegisterTools event.
 *
 * @since  0.1.0
 */
final class Tool
{
    /** Read-only access (queries, statistics). */
    public const SCOPE_READ = 'read';

    /** Create or edit content. */
    public const SCOPE_WRITE = 'write';

    /** Change publishing state (publish, unpublish, archive, feature). */
    public const SCOPE_PUBLISH = 'publish';

    /** Trash content. */
    public const SCOPE_DELETE = 'delete';

    /** @var callable(array, ToolContext): mixed */
    private $handler;

    /** @var callable(ToolContext): bool|null */
    private $availability;

    /**
     * @param   string         $name          Unique tool name (snake_case, a-z0-9_)
     * @param   string         $description   Description for the model: what it does, when to use it
     * @param   array          $inputSchema   JSON schema of the arguments
     * @param   callable       $handler       function (array $arguments, ToolContext $context): mixed
     * @param   string         $title         Human readable title
     * @param   string         $scope         One of the SCOPE_* constants
     * @param   string         $group         Group label (usually the plugin providing the tool)
     * @param   bool           $idempotent    Calling repeatedly with the same arguments has no additional effect
     * @param   callable|null  $availability  function (ToolContext $context): bool, e.g. for ACL checks
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        callable $handler,
        public readonly string $title = '',
        public readonly string $scope = self::SCOPE_READ,
        public readonly string $group = '',
        public readonly bool $idempotent = false,
        ?callable $availability = null
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('Invalid MCP tool name "%s"', $name));
        }

        $this->handler      = $handler;
        $this->availability = $availability;
    }

    public function isReadOnly(): bool
    {
        return $this->scope === self::SCOPE_READ;
    }

    public function isAvailable(ToolContext $context): bool
    {
        return $this->availability === null || (bool) \call_user_func($this->availability, $context);
    }

    public function call(array $arguments, ToolContext $context): mixed
    {
        return \call_user_func($this->handler, $arguments, $context);
    }

    /**
     * The tool definition as returned by tools/list.
     */
    public function toArray(): array
    {
        $schema = $this->inputSchema + ['type' => 'object'];

        // An empty PHP array would be encoded as a JSON list; the schema requires an object
        if (empty($schema['properties'])) {
            $schema['properties'] = new \stdClass();
        }

        $definition = [
            'name'        => $this->name,
            'description' => $this->description,
            'inputSchema' => $schema,
            'annotations' => [
                'readOnlyHint'    => $this->isReadOnly(),
                'destructiveHint' => $this->scope === self::SCOPE_DELETE,
                'idempotentHint'  => $this->idempotent || $this->isReadOnly(),
                'openWorldHint'   => false,
            ],
        ];

        if ($this->title !== '') {
            $definition['title']                = $this->title;
            $definition['annotations']['title'] = $this->title;
        }

        return $definition;
    }
}
