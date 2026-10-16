<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Agent;

\defined('_JEXEC') or die;

/**
 * What an agent answers a turn with: cards to show, and whether it is done.
 *
 * @since  0.8.0
 */
final class Reply
{
    /** @var array<int, array<string, mixed>> */
    public array $cards = [];

    /**
     * @param  array<int, array<string, mixed>>  $cards  Made with Card
     */
    public function __construct(array $cards = [], public bool $done = false)
    {
        $this->cards = array_values(array_filter($cards));
    }

    public function add(?array $card): self
    {
        if ($card) {
            $this->cards[] = $card;
        }

        return $this;
    }
}
