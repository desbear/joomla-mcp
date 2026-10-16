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
 * A kind of agent, offered by a plugin of the "mcpask" group: a helper that takes over the
 * conversation to get one thing done, such as filling in a form. The site may set up several
 * agents of one kind (see docs/agents-spec.md).
 *
 * What an agent shows is cards (see Card), never HTML of its own; what it keeps between turns is
 * $context->state, which stays on the server.
 *
 * @since  0.8.0
 */
interface AgentInterface
{
    /**
     * Short code of the kind, such as "rsform"; letters only.
     */
    public function type(): string;

    /**
     * Name of the kind, for the back end.
     */
    public function title(): string;

    /**
     * What the kind does, for the back end.
     */
    public function description(): string;

    /**
     * Whether it can work on this site (the extension it works with is installed and enabled).
     */
    public function available(): bool;

    /**
     * The XML file of its own settings (fields of the group "type"), or empty for none.
     */
    public function form(): string;

    /**
     * The first turn: what the visitor sees when the agent takes over.
     */
    public function start(Context $context): Reply;

    /**
     * One turn: the visitor wrote something ($input['text']), filled in fields ($input['fields']) or
     * pressed a button ($input['action'], with $input['value'] perhaps).
     *
     * @param  array{text?: string, fields?: array<string, mixed>, action?: string, value?: string}  $input
     */
    public function handle(Context $context, array $input): Reply;
}
