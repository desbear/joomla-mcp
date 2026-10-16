<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Mcp;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Event;

\defined('_JEXEC') or die;

/**
 * Collects the tools provided by plugins.
 *
 * Third party extensions add tools by creating a plugin in the "mcp" group that listens to
 * onMcpRegisterTools and calls $event->getArgument('registry')->add(new Tool(...)).
 *
 * After all tools are registered, onMcpAlterTools lets plugins replace or remove tools of other
 * plugins, e.g. a content construction kit taking over the article tools.
 *
 * @since  0.1.0
 */
final class ToolRegistry
{
    public const EVENT = 'onMcpRegisterTools';

    public const ALTER_EVENT = 'onMcpAlterTools';

    /** @var Tool[] */
    private array $tools = [];

    /** @var array<string, string> Tool name => group of the plugin that replaced or removed it */
    private array $overridden = [];

    /**
     * Build a registry by asking all enabled "mcp" plugins for their tools.
     */
    public static function load(): self
    {
        $registry = new self();
        $app      = Factory::getApplication();

        foreach (CoreTools::tools() as $tool) {
            $registry->add($tool);
        }

        PluginHelper::importPlugin('mcp', null, true, $app->getDispatcher());

        $app->getDispatcher()->dispatch(self::EVENT, new Event(self::EVENT, ['registry' => $registry]));
        $app->getDispatcher()->dispatch(self::ALTER_EVENT, new Event(self::ALTER_EVENT, ['registry' => $registry]));

        return $registry;
    }

    public function add(Tool $tool): void
    {
        if (isset($this->tools[$tool->name])) {
            throw new \LogicException(\sprintf('MCP tool "%s" is already registered', $tool->name));
        }

        $this->tools[$tool->name] = $tool;
    }

    /**
     * Replace a registered tool (or add it when it does not exist yet). Use in onMcpAlterTools.
     */
    public function replace(Tool $tool): void
    {
        if (isset($this->tools[$tool->name]) && $this->tools[$tool->name]->group !== $tool->group) {
            $this->overridden[$tool->name] = $tool->group;
        }

        $this->tools[$tool->name] = $tool;
    }

    /**
     * Remove tools, e.g. because another plugin handles that data now. Use in onMcpAlterTools.
     *
     * @param  string  $group  Group of the plugin removing them, for the dashboard
     */
    public function remove(string $group, string ...$names): void
    {
        foreach ($names as $name) {
            if (isset($this->tools[$name])) {
                unset($this->tools[$name]);
                $this->overridden[$name] = $group;
            }
        }
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Tools of other plugins that were replaced or removed, with the group that did it.
     *
     * @return array<string, string>
     */
    public function overridden(): array
    {
        return $this->overridden;
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return Tool[]
     */
    public function all(): array
    {
        return $this->tools;
    }
}
