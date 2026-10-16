<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Agent;

use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Gss\Component\Mcpask\Administrator\Ask\Vector;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Event;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * The kinds of agents the plugins of the "mcpask" group offer, and the agents set up from them.
 *
 * @since  0.8.0
 */
final class Agents
{
    /** Agent conversations kept, at most, after their last turn. */
    public const SESSION_HOURS = 24;

    /** @var array<string, AgentInterface>|null */
    private static ?array $types = null;

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * The kinds of agents of the installed and enabled plugins, by code.
     *
     * @return  array<string, AgentInterface>
     */
    public static function types(bool $availableOnly = true): array
    {
        if (self::$types === null) {
            self::$types = [];
            PluginHelper::importPlugin('mcpask');

            $event = new Event('onMcpaskAgentTypes', ['types' => []]);
            Factory::getApplication()->getDispatcher()->dispatch('onMcpaskAgentTypes', $event);

            foreach ((array) $event->getArgument('types', []) as $type) {
                if ($type instanceof AgentInterface && preg_match('/^[a-z]{2,30}$/', $type->type())) {
                    self::$types[$type->type()] = $type;
                }
            }
        }

        return $availableOnly ? array_filter(self::$types, static fn (AgentInterface $type) => $type->available()) : self::$types;
    }

    /**
     * @return  object[]  Every agent, with its params and own settings as Registry
     */
    public function all(): array
    {
        $db = $this->db;

        return array_map([$this, 'prepare'], $db->setQuery($db->getQuery(true)->select('*')->from($db->quoteName('#__mcpask_agents'))->order('ordering, id'))->loadObjectList());
    }

    public function get(int $id): ?object
    {
        $db  = $this->db;
        $row = $db->setQuery($db->getQuery(true)->select('*')->from($db->quoteName('#__mcpask_agents'))->where('id = ' . $id))->loadObject();

        return $row ? $this->prepare($row) : null;
    }

    /**
     * The agents a visitor may meet: published, of a kind that is there, for the page's language
     * and the visitor's access levels.
     *
     * @param   int[]  $levels
     *
     * @return  object[]
     */
    public function active(string $language, array $levels): array
    {
        $types = self::types();

        return array_values(array_filter($this->all(), static function ($agent) use ($types, $language, $levels) {
            $languages = array_filter((array) $agent->params->get('languages', []));

            return (int) $agent->published === 1
                && isset($types[$agent->type])
                && (!$languages || \in_array('*', $languages, true) || \in_array($language, $languages, true))
                && \in_array((int) $agent->params->get('access', 1), $levels, true);
        }));
    }

    /**
     * Keep an agent. Its trigger phrases are embedded again when they changed.
     *
     * @param   array<string, mixed>  $data  id, type, title, published, params (array), own (array)
     *
     * @return  int  Its ID
     */
    public function save(array $data, Settings $settings): int
    {
        $db      = $this->db;
        $id      = (int) ($data['id'] ?? 0);
        $current = $id ? $this->get($id) : null;
        $params  = (array) ($data['params'] ?? []);
        $params['type'] = (array) ($data['own'] ?? []);
        $phrases = self::phrases((string) ($params['phrases'] ?? ''));
        $now     = Factory::getDate()->toSql();

        $record = (object) [
            'type'      => $current ? $current->type : preg_replace('/[^a-z]/', '', (string) ($data['type'] ?? '')),
            'title'     => mb_substr(trim(strip_tags((string) ($data['title'] ?? ''))), 0, 255),
            'published' => (int) !empty($data['published']),
            'params'    => json_encode($params, JSON_UNESCAPED_UNICODE),
            'modified'  => $now,
        ];

        if ($record->title === '' || !isset(self::types(false)[$record->type])) {
            throw new \InvalidArgumentException('An agent needs a title and a kind.');
        }

        // Embedded again only when the phrases changed: each costs a little at the AI service
        $known = $current ? json_decode((string) $current->phrases, true) : null;

        if (!\is_array($known) || ($known['text'] ?? null) !== $phrases) {
            $vectors = $phrases ? $settings->client()->embeddings($settings->embeddingModel(), $phrases, $settings->embeddingDimensions()) : [];
            $known   = ['text' => $phrases, 'vectors' => array_map([Vector::class, 'pack'], $vectors), 'signature' => $settings->indexSignature()];
        }

        $record->phrases = json_encode($known);

        if ($current) {
            $record->id = $id;
            $db->updateObject('#__mcpask_agents', $record, 'id');
        } else {
            $record->created  = $now;
            $record->ordering = (int) $db->setQuery($db->getQuery(true)->select('MAX(ordering)')->from($db->quoteName('#__mcpask_agents')))->loadResult() + 1;
            $db->insertObject('#__mcpask_agents', $record, 'id');
            $id = (int) $record->id;
        }

        return $id;
    }

    public function publish(int $id, bool $published): void
    {
        $row = (object) ['id' => $id, 'published' => (int) $published];
        $this->db->updateObject('#__mcpask_agents', $row, 'id');
    }

    public function delete(int $id): void
    {
        $db = $this->db;

        foreach (['#__mcpask_agents' => 'id', '#__mcpask_agent_sessions' => 'agent_id', '#__mcpask_agent_events' => 'agent_id'] as $table => $column) {
            $db->setQuery($db->getQuery(true)->delete($db->quoteName($table))->where($db->quoteName($column) . ' = ' . $id))->execute();
        }
    }

    /**
     * Something that happened with an agent, for its numbers: offered, started, completed, left.
     */
    public function record(int $agentId, string $event): void
    {
        if (!\in_array($event, ['offered', 'started', 'completed', 'left'], true)) {
            return;
        }

        $row = (object) ['agent_id' => $agentId, 'event' => $event, 'created' => Factory::getDate()->toSql()];
        $this->db->insertObject('#__mcpask_agent_events', $row);
    }

    /**
     * @return  array<int, array<string, int>>  By agent, then event, over the last days
     */
    public function stats(int $days = 30): array
    {
        $db    = $this->db;
        $since = Factory::getDate('-' . $days . ' days')->toSql();
        $stats = [];

        foreach ($db->setQuery(
            $db->getQuery(true)
                ->select('agent_id, event, COUNT(*) AS n')
                ->from($db->quoteName('#__mcpask_agent_events'))
                ->where('created >= :since')
                ->bind(':since', $since)
                ->group('agent_id, event')
        )->loadObjectList() as $row) {
            $stats[(int) $row->agent_id][$row->event] = (int) $row->n;
        }

        return $stats;
    }

    /**
     * The agent whose trigger phrases are closest to a question, when close enough.
     *
     * @param   object[]  $agents     As active() gives them
     * @param   float[]   $embedding  Of the question
     *
     * @return  array{agent: object, score: float}|null
     */
    public static function closest(array $agents, array $embedding, Settings $settings): ?array
    {
        $question = Vector::unpack(Vector::pack($embedding));
        $best     = null;

        foreach ($agents as $agent) {
            $phrases = json_decode((string) $agent->phrases, true);

            // Phrases embedded with another model cannot be compared
            if (!\is_array($phrases) || ($phrases['signature'] ?? '') !== $settings->indexSignature()) {
                continue;
            }

            foreach ((array) ($phrases['vectors'] ?? []) as $packed) {
                $score = Vector::similarity($question, Vector::unpack((string) $packed));

                if ($score >= min(0.95, max(0.3, (float) $agent->params->get('phrase_score', 0.6))) && (!$best || $score > $best['score'])) {
                    $best = ['agent' => $agent, 'score' => $score];
                }
            }
        }

        return $best;
    }

    /**
     * @return  string[]  The trigger phrases: one per line, each once
     */
    public static function phrases(string $text): array
    {
        $lines = array_map(static fn ($line) => trim(mb_substr(strip_tags($line), 0, 300)), preg_split('/\R/u', $text) ?: []);

        return \array_slice(array_values(array_unique(array_filter($lines, 'strlen'))), 0, 20);
    }

    private function prepare(object $row): object
    {
        $params    = new Registry((string) $row->params);
        $row->params = $params;
        $row->own    = new Registry($params->get('type', new \stdClass()));

        return $row;
    }
}
