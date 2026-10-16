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
use Gss\Component\Mcpask\Administrator\Ask\Answerer;
use Gss\Component\Mcpask\Administrator\Ask\Guard;
use Gss\Component\Mcpask\Administrator\Ask\Hints;
use Gss\Component\Mcpask\Administrator\Ask\Retriever;
use Gss\Component\Mcpask\Administrator\Ask\ServiceException;
use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Gss\Component\Mcpask\Administrator\Ask\Source\Sources;
use Gss\Component\Mcpask\Administrator\Ask\UsageLog;
use Gss\Component\Mcpask\Administrator\Embed\Embeds;
use Gss\Component\Mcpask\Administrator\Embed\Request as EmbedRequest;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The endpoint the module posts a question to. The answer is written as server-sent events
 * while it is made:
 *
 *   status  {text}                              what is happening, before the answer starts
 *   sources {items: [{title, url, section}]}    the pages the answer is written from
 *   delta   {text}                              the next piece of the answer
 *   error   {message}                           the answer could not be given
 *   done    {}
 *
 * Everything is answered from what a visitor who is not signed in may read, whoever asks.
 *
 * @since  0.1.0
 */
class AskController extends BaseController
{
    private const MAX_BODY_BYTES = 32768;

    private const MAX_QUESTION = 500;

    /** Earlier turns of the conversation that are sent along, and how much of each. */
    private const MAX_HISTORY = 6;

    private const MAX_TURN = 800;

    private const MAX_PAGE_TITLE = 300;

    public function stream(): void
    {
        $started  = microtime(true);
        $settings = Settings::load();
        $input    = $this->app->getInput();

        $this->open();

        if (strtoupper($input->getMethod()) !== 'POST' || !$this->sameOrigin()) {
            $this->fail(Text::_('COM_MCPASK_ERROR_REQUEST'));
        }

        // Embedded on another website: its key, and the visitor its token stands for
        $embed = EmbedRequest::apply($this->app);
        $user  = $this->app->getIdentity();

        // This task answers before Joomla renders a page, which is where a site that is offline
        // would turn visitors away. The same holds for who may ask at all.
        $offline = $this->app->get('offline') && !($user && $user->authorise('core.login.offline'));
        $allowed = $user && \in_array($settings->accessLevel(), array_map('intval', $user->getAuthorisedViewLevels()), true);

        if (!$settings->ready() || $offline || !$allowed) {
            $this->fail(Text::_('COM_MCPASK_ERROR_UNAVAILABLE'));
        }

        // Who may ask is set in the component's permissions
        if (!$settings->mayAsk($user)) {
            $this->fail(Text::_($user->guest ? 'COM_MCPASK_ERROR_LOGIN' : 'COM_MCPASK_ERROR_NOT_ALLOWED'));
        }

        $body = json_decode((string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES), true);
        [$question, $history, $page] = $this->read(\is_array($body) ? $body : []);

        if ($question === '') {
            $this->fail(Text::_('COM_MCPASK_ERROR_REQUEST'));
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $ip    = $settings->visitorIp();
        $log   = new UsageLog($db, $settings, (string) $this->app->get('secret'));
        $guard = new Guard($settings);

        // An address the administrator has shut out gets no answer and leaves no trace
        if ($guard->banned($ip)) {
            $this->fail(Text::_('COM_MCPASK_ERROR_UNAVAILABLE'));
        }

        // A key has its own day's share of questions
        if ($embed && (new Embeds($db))->spent($embed)) {
            $this->fail(Text::_('COM_MCPASK_ERROR_LIMIT_DAY'));
        }

        ['id' => $id, 'limit' => $limit] = $log->start($ip, $question, (int) $user->id, $settings->limitsFor($user));

        if ($embed && $id) {
            $db->setQuery($db->getQuery(true)->update($db->quoteName('#__mcpask_log'))->set('embed_id = ' . (int) $embed->id)->where('id = ' . (int) $id))->execute();
        }

        if ($limit !== null) {
            $this->fail(Text::_(['minute' => 'COM_MCPASK_ERROR_LIMIT_MINUTE', 'blocked' => 'COM_MCPASK_ERROR_BLOCKED'][$limit] ?? 'COM_MCPASK_ERROR_LIMIT_DAY'));
        }

        // What is a probe rather than a question is recorded and turned away before anything is paid for
        // Marks for the log: an attempt to steer the answer, or markup, is recorded whatever happens next
        $flags = $guard->flags($question);

        // The earlier turns and the page title come from the visitor's browser too
        $caught = $guard->refuses($question);

        foreach ([...array_column($history, 'text'), $page['title']] as $text) {
            $caught = $caught ?: ($text !== '' ? $guard->refuses($text) : null);
        }

        if ($caught) {
            $log->finish($id, ['status' => 'blocked', 'message' => $caught, 'flags' => $flags], $started);
            $this->fail(Text::_('COM_MCPASK_ERROR_NOT_A_QUESTION'));
        }

        $source = Sources::current($db, $settings);
        $answer = new Answerer($settings, new Retriever($db, $settings, $source), $source, (string) $this->app->get('sitename'), [
            'searching' => Text::_('COM_MCPASK_STATUS_SEARCHING'),
            'found'     => Text::_('COM_MCPASK_STATUS_FOUND'),
            'nothing'   => Text::_('COM_MCPASK_NOTHING_FOUND'),
            'again'     => Text::_('COM_MCPASK_STATUS_AGAIN'),
            'reading'   => Text::_('COM_MCPASK_STATUS_READING'),
        ]);

        // Helpers that may be offered below the answer
        try {
            if ($embed && !$embed->params->get('agents', 1)) {
                throw new \RuntimeException('No agents on this embed');
            }

            $agents = new Agents($db);
            $answer->offerAgents($agents->active($this->app->getLanguage()->getTag(), array_map('intval', $user->getAuthorisedViewLevels())));
        } catch (\Throwable) {
            $agents = null;
        }

        try {
            $result = $answer->answer(
                $question,
                $history,
                $this->app->getLanguage()->getTag(),
                fn ($event, $data) => $this->emit($event, $data),
                $settings->pageContext() ? $page : []
            );

            if (connection_aborted()) {
                $result['status'] = 'aborted';
            }

            try {
                (new Hints($db, $settings))->hit($result['hints'] ?? []);
            } catch (\Throwable) {
                // Counting is not worth failing an answer for
            }

            // A helper suited to the question, offered below the answer
            if (($result['status'] ?? '') === 'ok' && $agents && ($offer = $answer->agentOffer())) {
                $this->emit('agent', $offer);
                $agents->record($offer['id'], 'offered');
            }

            // With what the visitor's browser rates the answer by
            $this->emit('done', $id ? ['ref' => $log->reference($id)] : []);
        } catch (ServiceException $e) {
            // What the service said is for the administrator; the visitor gets a plain apology
            $result = ['status' => 'error', 'message' => $e->getMessage()];
            $this->emit('error', ['message' => Text::_('COM_MCPASK_ERROR_SERVICE')]);
        } catch (\Throwable $e) {
            $result = ['status' => 'error', 'message' => \get_class($e) . ': ' . $e->getMessage()];
            $this->emit('error', ['message' => Text::_('COM_MCPASK_ERROR_SERVICE')]);
        }

        // When the backup service had to step in, the administrator should know why
        if (($reason = $settings->client()->usedFallback()) !== '' && ($result['message'] ?? '') === '') {
            $result['message'] = mb_substr('Answered by the backup service. The main service: ' . $reason, 0, 250);
        }

        $result['flags'] = array_values(array_unique([...$flags, ...($result['flags'] ?? [])]));

        $log->finish($id, $result, $started);
        $this->app->close();
    }

    /**
     * How the visitor rated an answer: {ref, rating: 1 or -1, feedback?}. Answers with JSON.
     */
    public function feedback(): void
    {
        $input = $this->app->getInput();
        $ok    = false;

        $settings = Settings::load();
        $user     = $this->app->getIdentity();
        $offline  = $this->app->get('offline') && !($user && $user->authorise('core.login.offline'));

        // Rated only where asking is possible, and never from an address that is shut out
        if (strtoupper($input->getMethod()) === 'POST' && $this->sameOrigin() && $settings->enabled() && !$offline && !(new Guard($settings))->banned($settings->visitorIp())) {
            $body = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true);

            if (\is_array($body) && \is_string($body['ref'] ?? null)) {
                $db  = Factory::getContainer()->get(DatabaseInterface::class);
                $log = new UsageLog($db, $settings, (string) $this->app->get('secret'));
                $ok  = $log->rate($body['ref'], (int) ($body['rating'] ?? 0), \is_string($body['feedback'] ?? null) ? $body['feedback'] : '');
            }
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => $ok]);

        $this->app->close();
    }

    /**
     * @return  array{0: string, 1: array<int, array{role: string, text: string}>, 2: array{item: int, title: string}}
     */
    private function read(array $body): array
    {
        $question = \is_string($body['question'] ?? null) ? trim($body['question']) : '';
        $history  = [];

        if (mb_strlen($question) > self::MAX_QUESTION) {
            $question = '';
        }

        foreach (\array_slice((array) ($body['history'] ?? []), -self::MAX_HISTORY) as $turn) {
            if (\is_array($turn) && \is_string($turn['text'] ?? null) && trim($turn['text']) !== '') {
                $history[] = [
                    'role' => ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                    'text' => mb_substr(trim($turn['text']), 0, self::MAX_TURN),
                ];
            }
        }

        // Where the question is asked from: the content item the module was shown with, and the page title
        $page = \is_array($body['page'] ?? null) ? $body['page'] : [];
        $page = [
            'item'  => max(0, (int) ($page['item'] ?? 0)),
            'title' => mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', \is_string($page['title'] ?? null) ? $page['title'] : '')), 0, self::MAX_PAGE_TITLE),
            // Embedded on another website: the address of the page there, when it is one of the pages read
            'url'   => \is_string($page['url'] ?? null) && preg_match('#^https?://[^\s]{1,490}$#i', $page['url']) ? $page['url'] : '',
        ];

        return [$question, $history, $page];
    }

    /**
     * Pages are cached, so a form token would go stale. A JSON body from this site's own pages is
     * required instead: another site cannot send one without this server's permission.
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

    /**
     * Start the event stream: nothing may hold the output back, neither PHP nor the web server.
     */
    private function open(): void
    {
        // The request keeps running after the visitor leaves, long enough to stop the AI service and write the log
        ignore_user_abort(true);
        @set_time_limit(240);
        @ini_set('zlib.output_compression', '0');

        // Another request of the same visitor must not wait for this one
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-store, no-transform');
            header('X-Accel-Buffering: no');
            header('Content-Encoding: none');
            header('X-Content-Type-Options: nosniff');
            header('X-Robots-Tag: noindex');
        }
    }

    /**
     * @return  bool  False when the visitor has gone
     */
    private function emit(string $event, array $data): bool
    {
        echo 'event: ', $event, "\n", 'data: ', json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n\n";

        flush();

        return !connection_aborted();
    }

    private function fail(string $message): never
    {
        $this->emit('error', ['message' => $message]);
        $this->app->close();
    }
}
