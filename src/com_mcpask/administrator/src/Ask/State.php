<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Ask;

use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Small facts the component remembers between requests, such as when another site was last read.
 *
 * @since  0.2.0
 */
final class State
{
    public function __construct(private DatabaseInterface $db)
    {
    }

    public function get(string $name): array
    {
        $query = $this->db->getQuery(true)
            ->select('value')
            ->from($this->db->quoteName('#__mcpask_state'))
            ->where('name = :name')
            ->bind(':name', $name);

        return (array) json_decode((string) $this->db->setQuery($query)->loadResult(), true);
    }

    public function set(string $name, array $value): void
    {
        $query = $this->db->getQuery(true)
            ->delete($this->db->quoteName('#__mcpask_state'))
            ->where('name = :name')
            ->bind(':name', $name);

        $this->db->setQuery($query)->execute();

        $record = (object) ['name' => $name, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)];

        $this->db->insertObject('#__mcpask_state', $record);
    }
}
