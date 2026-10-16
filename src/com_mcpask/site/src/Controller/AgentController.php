<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Site\Controller;

use Gss\Component\Mcpask\Administrator\Agent\Agents;
use Gss\Component\Mcpask\Administrator\Agent\Card;
use Gss\Component\Mcpask\Administrator\Agent\Context;
use Gss\Component\Mcpask\Administrator\Agent\Handoffs;
use Gss\Component\Mcpask\Administrator\Agent\Reply;
use Gss\Component\Mcpask\Administrator\Agent\Report;
use Gss\Component\Mcpask\Administrator\Agent\Sessions;
use Gss\Component\Mcpask\Administrator\Ask\Guard;
use Gss\Component\Mcpask\Administrator\Ask\ServiceException;
use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Gss\Component\Mcpask\Administrator\Embed\Embeds;
use Gss\Component\Mcpask\Administrator\Embed\Request as EmbedRequest;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The turns of an agent conversation, for the module: start one ({agent}) and take the next turn
 * ({session, text | fields | action, value}). Answers with JSON: the agent, the cards to show,
 * whether it is done. The same checks as asking a question apply.
 *
 * @since  0.8.0
 */
class AgentController extends BaseController
{
    private const MAX_BODY_BYTES = 32768;

    private const MAX_TEXT = 2000;

    public function start(): void
    {
        [$settings, $db, $visitor, $body] = $this->check();

        $agents = new Agents($db);
        $agent  = $this->find($agents, (int) ($body['agent'] ?? 0));

        if ((new Sessions($db, $this->secret()))->startedToday((int) $agent->id, $visitor) >= max(1, (int) $agent->params->get('per_day', 5))) {
            $this->send(['error' => Text::_('COM_MCPASK_ERROR_LIMIT_DAY')]);
        }

        $context = new Context($agent, ['entry' => $this->entry($body)], $this->app->getLanguage()->getTag(), $settings, $db, $visitor);
        $reply   = $this->run(fn () => Agents::types()[$agent->type]->start($context));
        $sessions = new Sessions($db, $this->secret());
        $session  = $sessions->create((int) $agent->id, $visitor, $context->state);

        $agents->record((int) $agent->id, 'started');

        // An agent may be done in its first turn (passing the visitor on, say)
        if ($reply->done) {
            $sessions->save($session, [], 'done');
            $agents->record((int) $agent->id, 'completed');
        }
        $this->answer($agent, $session, $reply);
    }

    public function step(): void
    {
        [$settings, $db, $visitor, $body] = $this->check();

        $sessions = new Sessions($db, $this->secret());
        $key      = \is_string($body['session'] ?? null) ? $body['session'] : '';
        $open     = $sessions->open($key, $visitor);

        if (!$open) {
            $this->send(['error' => Text::_('COM_MCPASK_AGENT_EXPIRED'), 'expired' => true]);
        }

        $agents = new Agents($db);
        $agent  = $this->find($agents, $open['agent_id']);
        $input  = $this->input($body);

        if (($input['action'] ?? '') === 'leave') {
            $sessions->save($key, [], 'left');
            $agents->record((int) $agent->id, 'left');
            $this->answer($agent, $key, new Reply([], true));
        }

        // What the visitor writes is checked as a question is
        $guard = new Guard($settings);

        $values  = array_map('strval', array_merge(...array_map(static fn ($value) => (array) $value, array_values($input['fields'] ?? []))));
        $refused = ($input['text'] ?? '') !== '' ? $guard->refuses($input['text']) : null;

        foreach ($values as $value) {
            $refused = $refused ?: ($value !== '' ? $guard->refusesValue($value) : null);
        }

        if ($refused) {
            $this->answer($agent, $key, new Reply([Card::error(Text::_('COM_MCPASK_ERROR_NOT_A_QUESTION'))]));
        }

        $context = new Context($agent, $open['state'], $this->app->getLanguage()->getTag(), $settings, $db, $visitor);
        $reply   = $this->run(fn () => Agents::types()[$agent->type]->handle($context, $input));

        $sessions->save($key, $context->state, $reply->done ? 'done' : 'active');

        if ($reply->done) {
            $agents->record((int) $agent->id, 'completed');
        }

        $this->answer($agent, $key, $reply);
    }

    /**
     * Write a report and tell it as it goes, as an event stream ({key}). A report that is written
     * already is told at once.
     */
    public function report(): void
    {
        [$settings, $db, , $body] = $this->check();

        $key     = \is_string($body['key'] ?? null) ? $body['key'] : '';
        $reports = new Report($db, $settings, $this->secret());
        $report  = $reports->find($key);
        $agent   = $report ? (new Agents($db))->get((int) $report->agent_id) : null;

        if (!$report || !$agent) {
            $this->send(['error' => Text::_('COM_MCPASK_REPORT_GONE')]);
        }

        // The answer is written as it comes: nothing may hold it back
        ignore_user_abort(true);
        @set_time_limit(600);
        @ini_set('zlib.output_compression', '0');

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-store, no-transform');
        header('X-Accel-Buffering: no');
        header('Content-Encoding: none');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');

        $reports->write($key, $agent, static function (string $event, array $data): bool {
            echo 'event: ', $event, "\n", 'data: ', json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n\n";
            flush();

            // The report is written to the end even when the visitor has gone: it waits for them by e-mail
            return true;
        });

        $this->app->close();
    }

    /**
     * What happened in a live chat window the visitor was passed on to ({key, event, availability}),
     * for the log of handoffs.
     */
    public function handoff(): void
    {
        [, $db, $visitor, $body] = $this->check();

        $logged = (new Handoffs($db))->event(
            \is_string($body['key'] ?? null) ? $body['key'] : '',
            $visitor,
            \is_string($body['event'] ?? null) ? $body['event'] : '',
            \is_string($body['availability'] ?? null) ? $body['availability'] : ''
        );

        $this->send(['ok' => $logged]);
    }

    /**
     * What the page says about how an agent was started: from where, the question and answer
     * before it, the page, a report's address. Only addresses of this site are taken, and, when
     * embedded, pages of the websites the key is embedded in.
     *
     * @return  array{conversation: array<int, array{role: string, text: string}>, source: string, question: string, answer: string, page: string, report: string}
     */
    private function entry(array $body): array
    {
        $entry = \is_array($body['entry'] ?? null) ? $body['entry'] : [];
        $text  = static fn ($value, int $length) => \is_string($value) ? mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? ''), 0, $length) : '';
        $embed = EmbedRequest::apply($this->app);
        $here  = static function ($value, bool $elsewhere = false) use ($text, $embed): string {
            $url   = $text($value, 500);
            $parts = parse_url($url);

            if (!preg_match('#^https?://#i', $url) || !\is_string($parts['host'] ?? null)) {
                return '';
            }

            // Embedded: the page is on the website the key is embedded in
            $origin = strtolower($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));

            return strcasecmp($parts['host'], Uri::getInstance()->getHost()) === 0 || ($elsewhere && $embed && Embeds::allows($embed, $origin)) ? $url : '';
        };

        // The conversation before it: turns of the visitor and of the answers, newest last
        $conversation = [];
        $room         = 8000;

        foreach (array_reverse(\array_slice(\is_array($entry['conversation'] ?? null) ? $entry['conversation'] : [], -20)) as $turn) {
            $said = \is_array($turn) ? $text($turn['text'] ?? null, 1500) : '';

            if ($said === '' || !\in_array($turn['role'] ?? '', ['user', 'assistant'], true) || ($room -= mb_strlen($said)) < 0) {
                continue;
            }

            array_unshift($conversation, ['role' => $turn['role'], 'text' => $said]);
        }

        return [
            'conversation' => $conversation,
            'source'   => \in_array($entry['source'] ?? '', Handoffs::SOURCES, true) ? $entry['source'] : 'list',
            'question' => $text($entry['question'] ?? null, 500),
            'answer'   => $text($entry['answer'] ?? null, 1500),
            'page'     => $here($entry['page'] ?? null, true),
            'report'   => $here($entry['report'] ?? null),
        ];
    }

    /**
     * The checks every turn passes: a JSON request from this site, the service on, the visitor
     * allowed to ask and not shut out.
     *
     * @return  array{0: Settings, 1: DatabaseInterface, 2: string, 3: array}
     */
    private function check(): array
    {
        $settings = Settings::load();
        $input    = $this->app->getInput();

        if (strtoupper($input->getMethod()) !== 'POST' || !$this->sameOrigin()) {
            $this->send(['error' => Text::_('COM_MCPASK_ERROR_REQUEST')]);
        }

        // Embedded on another website: its key, and the visitor its token stands for
        $embed = EmbedRequest::apply($this->app);
        $user  = $this->app->getIdentity();

        if ($embed && !$embed->params->get('agents', 1)) {
            $this->send(['error' => Text::_('COM_MCPASK_AGENT_UNAVAILABLE')]);
        }

        $offline = $this->app->get('offline') && !($user && $user->authorise('core.login.offline'));
        $allowed = $user && \in_array($settings->accessLevel(), array_map('intval', $user->getAuthorisedViewLevels()), true);

        if (!$settings->ready() || $offline || !$allowed || !$settings->mayAsk($user) || (new Guard($settings))->banned($settings->visitorIp())) {
            $this->send(['error' => Text::_('COM_MCPASK_ERROR_UNAVAILABLE')]);
        }

        $body = json_decode((string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES), true);

        return [
            $settings,
            Factory::getContainer()->get(DatabaseInterface::class),
            hash_hmac('sha256', $settings->visitorIp() . '|' . (int) $user->id, 'mcpask-visitor|' . $this->secret()),
            \is_array($body) ? $body : [],
        ];
    }

    /**
     * An agent this visitor may meet.
     */
    private function find(Agents $agents, int $id): object
    {
        $user   = $this->app->getIdentity();
        $levels = array_map('intval', $user->getAuthorisedViewLevels());

        foreach ($agents->active($this->app->getLanguage()->getTag(), $levels) as $agent) {
            if ((int) $agent->id === $id) {
                return $agent;
            }
        }

        $this->send(['error' => Text::_('COM_MCPASK_AGENT_UNAVAILABLE')]);
    }

    /**
     * What the visitor sent this turn, cut down to what an agent may get.
     *
     * @return  array{text?: string, fields?: array<string, mixed>, action?: string, value?: string}
     */
    private function input(array $body): array
    {
        $input = [];

        if (\is_string($body['text'] ?? null) && trim($body['text']) !== '') {
            $input['text'] = mb_substr(trim($body['text']), 0, self::MAX_TEXT);
        }

        if (\is_array($body['fields'] ?? null)) {
            foreach (\array_slice($body['fields'], 0, 100, true) as $name => $value) {
                if (!\is_string($name) || !preg_match('/^[A-Za-z0-9_\-]{1,100}$/', $name)) {
                    continue;
                }

                $input['fields'][$name] = \is_array($value)
                    ? array_values(array_map(static fn ($item) => mb_substr((string) $item, 0, 500), \array_slice(array_filter($value, 'is_scalar'), 0, 50)))
                    : mb_substr((string) (\is_scalar($value) ? $value : ''), 0, self::MAX_TEXT);
            }
        }

        if (\is_string($body['action'] ?? null) && preg_match('/^[a-z_]{1,30}$/', $body['action'])) {
            $input['action'] = $body['action'];
            $input['value']  = \is_scalar($body['value'] ?? null) ? mb_substr((string) $body['value'], 0, 100) : '';
        }

        return $input;
    }

    /**
     * A turn of the agent; what goes wrong is told to the visitor plainly.
     */
    private function run(callable $turn): Reply
    {
        try {
            return $turn();
        } catch (ServiceException) {
            return new Reply([Card::error(Text::_('COM_MCPASK_ERROR_SERVICE'))]);
        } catch (\Throwable $e) {
            return new Reply([Card::error(Text::_('COM_MCPASK_ERROR_SERVICE') . (JDEBUG ? ' ' . $e->getMessage() : ''))]);
        }
    }

    private function answer(object $agent, string $session, Reply $reply): never
    {
        $this->send([
            'agent'   => ['id' => (int) $agent->id, 'title' => (string) $agent->title],
            'session' => $reply->done ? '' : $session,
            'cards'   => $reply->cards,
            'done'    => $reply->done,
        ]);
    }

    private function secret(): string
    {
        return (string) $this->app->get('secret');
    }

    /**
     * As for questions: a JSON body from this site's own pages.
     */
    private function sameOrigin(): bool
    {
        $server = $this->app->getInput()->server;
        $origin = $server->getString('HTTP_ORIGIN', '') ?: $server->getString('HTTP_REFERER', '');
        $host   = parse_url($origin, PHP_URL_HOST);

        return \is_string($host)
            && strcasecmp($host, Uri::getInstance()->getHost()) === 0
            && str_starts_with(strtolower($server->getString('CONTENT_TYPE', '')), 'application/json');
    }

    private function send(array $data): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $this->app->close();
    }
}
