<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Agent;

use Gss\Component\Mcpask\Administrator\Ask\ServiceException;
use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * What an agent has during a turn: its settings, what it kept from earlier turns (state, which it
 * may change), the language of the page, and the AI service of Ask the Site.
 *
 * @since  0.8.0
 */
final class Context
{
    /**
     * @param  object                $agent     The agent's record: id, type, title, params (Registry), type (Registry of its own settings)
     * @param  array<string, mixed>  $state     Kept on the server between turns; on the first turn,
     *                                         "entry" is what the page said about how the agent was started
     * @param  string                $visitor   The visitor, as a hash (for logs bound to them)
     */
    public function __construct(
        public readonly object $agent,
        public array $state,
        public readonly string $language,
        public readonly Settings $settings,
        public readonly DatabaseInterface $db,
        public readonly string $visitor = ''
    ) {
    }

    public function params(): Registry
    {
        return $this->agent->params;
    }

    /**
     * The settings of the agent's own kind (the "type" group of its form).
     */
    public function own(): Registry
    {
        return $this->agent->own;
    }

    /**
     * One request to the AI service, without tools: the text it answers with.
     *
     * @throws  ServiceException
     */
    public function ask(string $instructions, string $message, int $maxTokens = 1500): string
    {
        $answer = '';

        $this->settings->client()->converse(
            $this->settings->apiStyle(),
            $this->settings->chatModel(),
            $instructions,
            [['role' => 'user', 'content' => $message]],
            [],
            0,
            $maxTokens,
            $this->settings->reasoningEffort(),
            static function (string $text) use (&$answer): bool {
                $answer .= $text;

                return mb_strlen($answer) < 60000;
            },
            static fn () => null
        );

        return $answer;
    }

    /**
     * Like ask(), for an answer that is a JSON object; null when it is not one.
     *
     * @throws  ServiceException
     */
    public function askJson(string $instructions, string $message, int $maxTokens = 1500): ?array
    {
        $answer = $this->ask($instructions . "\nReply with one JSON object only, no other text.", $message, $maxTokens);
        $json   = preg_match('/\{.*\}/s', $answer, $match) ? json_decode($match[0], true) : null;

        return \is_array($json) ? $json : null;
    }

    /**
     * Whether the page is in Chinese, for texts an agent writes itself.
     */
    public function chinese(): bool
    {
        return str_starts_with($this->language, 'zh');
    }
}
