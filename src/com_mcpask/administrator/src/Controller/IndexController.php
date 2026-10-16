<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Controller;

use Gss\Component\Mcpask\Administrator\Ask\Answerer;
use Gss\Component\Mcpask\Administrator\Ask\Hints;
use Gss\Component\Mcpask\Administrator\Ask\Indexer;
use Gss\Component\Mcpask\Administrator\Ask\Retriever;
use Gss\Component\Mcpask\Administrator\Ask\PeerSync;
use Gss\Component\Mcpask\Administrator\Ask\WebSync;
use Gss\Component\Mcpask\Administrator\Ask\ServiceException;
use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Gss\Component\Mcpask\Administrator\Ask\State;
use Gss\Component\Mcpask\Administrator\Ask\Theme;
use Gss\Component\Mcpask\Administrator\Ask\Source\Sources;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Table\Extension;
use Joomla\Registry\Registry;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * What the buttons of the dashboard call. Every task answers with JSON.
 *
 * @since  0.1.0
 */
class IndexController extends BaseController
{
    /** Pages indexed per request, small enough for any execution time limit. */
    private const BATCH = 5;

    /** Seconds one request spends reading other sites. */
    private const PEER_SECONDS = 15;

    /**
     * Index the next pages after the given ID. The dashboard repeats this until "done".
     */
    public function sync(): void
    {
        $this->guard();

        $settings = Settings::load();

        if ($settings->apiKey() === '') {
            $this->send(new \RuntimeException(Text::_('COM_MCPASK_CHECK_KEY_MISSING')));
        }

        $db      = Factory::getContainer()->get(DatabaseInterface::class);
        $own     = Sources::own($db, $settings);
        $sources = array_values($own);
        $input   = $this->app->getInput()->post;
        $after   = $input->getInt('after', 0);
        // The site's sources are worked through one after the other
        $stage   = min(max(0, $input->getInt('stage', 0)), \count($sources) - 1);

        try {
            $indexer = new Indexer($db, $settings);
            $result  = $indexer->sync($sources[$stage], $after, self::BATCH);

            if ($after === 0 && $stage === 0) {
                $result['total'] = array_sum(array_map(static fn ($source) => \count($source->ids()), $sources));
            }

            $result['stage'] = $stage;

            if ($result['done'] && $stage < \count($sources) - 1) {
                $result = ['stage' => $stage + 1, 'last' => 0, 'done' => false] + $result;
            } elseif ($result['done']) {
                $result['removed'] = $indexer->prune($own);
            }

            // Passages stored by an earlier version get what the faster search needs along the way
            $uncoded = $indexer->encode(1000);

            if ($result['done'] && $uncoded > 0) {
                $result['done'] = false;
            }

            $this->send($result);
        } catch (\Throwable $e) {
            $this->send($e);
        }
    }

    /**
     * Read the other sites for a while. The dashboard repeats this until nothing remains.
     */
    public function peers(): void
    {
        $this->guard();

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $this->send((new PeerSync($db, Settings::load()))->run(self::PEER_SECONDS));
        } catch (\Throwable $e) {
            $this->send($e);
        }
    }

    /**
     * Read the listed websites for a while. The dashboard repeats this until nothing remains, and
     * remembers that it was asked to, so a reload carries on.
     */
    public function websites(): void
    {
        $this->guard();

        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $sync  = new WebSync($db, Settings::load());
            $state = new State($db);

            $state->set(WebSync::WANTED, ['since' => Factory::getDate()->toSql()]);

            $report = $sync->run(self::PEER_SECONDS);
            $status = $sync->status();

            // Finished when no site has anything left that it could read
            if ($report && !array_filter($status, static fn ($site) => $site['error'] === '' && $site['remaining'] > 0)) {
                $state->set(WebSync::WANTED, []);
            }

            $this->send($this->websiteStatus($db, $sync, $report === []));
        } catch (\Throwable $e) {
            $this->send($e);
        }
    }

    /**
     * How far the reading of each website has got, for the dashboard to show while it waits.
     */
    public function websitesStatus(): void
    {
        $this->guard();

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $this->send($this->websiteStatus($db, new WebSync($db, Settings::load()), false));
    }

    /**
     * Stop reading from the dashboard; the scheduled task carries on in its own time.
     */
    public function websitesStop(): void
    {
        $this->guard();

        (new State(Factory::getContainer()->get(DatabaseInterface::class)))->set(WebSync::WANTED, []);

        $this->send(['stopped' => true]);
    }

    /**
     * @return  array{sites: array, running: bool, busy: bool, wanted: bool}
     */
    private function websiteStatus(DatabaseInterface $db, WebSync $sync, bool $busy): array
    {
        return [
            'sites'   => array_values($sync->status()),
            // Someone else's reading (the scheduled task, another window) is under way
            'busy'    => $busy,
            'running' => WebSync::running($db),
            'wanted'  => (bool) (new State($db))->get(WebSync::WANTED),
        ];
    }

    /**
     * Indexed pages whose title holds the given words, to point a rule to.
     */
    public function lookup(): void
    {
        $this->guard();

        $db       = Factory::getContainer()->get(DatabaseInterface::class);
        $settings = Settings::load();
        $words    = mb_substr(trim($this->app->getInput()->post->getString('words', '')), 0, 100);

        $this->send($words === '' ? [] : (new Retriever($db, $settings, Sources::current($db, $settings)))->lookup($words));
    }

    public function ruleSave(): void
    {
        $this->guard('core.edit');

        $input = $this->app->getInput()->post;

        try {
            $id = (new Hints(Factory::getContainer()->get(DatabaseInterface::class), Settings::load()))->save([
                'id'        => $input->getInt('id', 0),
                'question'  => $input->getString('question', ''),
                'variants'  => $input->getString('variants', ''),
                'documents' => (array) json_decode($input->getString('documents', '[]'), true),
                'note'      => $input->getString('note', ''),
                'language'  => $input->getString('language', '*'),
                'state'     => $input->getInt('state', 1),
            ], (int) $this->app->getIdentity()->id);

            $this->send(['id' => $id]);
        } catch (\InvalidArgumentException) {
            $this->send(new \RuntimeException(Text::_('COM_MCPASK_RULE_NEEDS')));
        } catch (\Throwable $e) {
            $this->send($e);
        }
    }

    public function ruleDelete(): void
    {
        $this->guard('core.delete');

        (new Hints(Factory::getContainer()->get(DatabaseInterface::class), Settings::load()))->delete($this->app->getInput()->post->getInt('id', 0));

        $this->send(['deleted' => true]);
    }

    /**
     * Ask a question the way a visitor would, with the rules as they are saved, and show the
     * whole answer with its sources and the rules that applied. Nothing is logged.
     */
    public function ruleTry(): void
    {
        $this->guard('core.edit');

        $settings = Settings::load();
        $question = mb_substr(trim($this->app->getInput()->post->getString('question', '')), 0, 500);
        $language = $this->app->getInput()->post->getString('language', '*');

        if ($question === '' || !$settings->ready()) {
            $this->send(new \RuntimeException(Text::_($question === '' ? 'COM_MCPASK_RULE_NEEDS' : 'COM_MCPASK_CHECK_KEY_MISSING')));
        }

        // Answered in the rule's language, or the site's own
        if (!preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $language)) {
            $language = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        }

        try {
            $db      = Factory::getContainer()->get(DatabaseInterface::class);
            $source  = Sources::current($db, $settings);
            $text    = '';
            $sources = [];
            $answer  = new Answerer($settings, new Retriever($db, $settings, $source), $source, (string) $this->app->get('sitename'), [
                'searching' => '', 'found' => '%d', 'nothing' => Text::_('COM_MCPASK_RULE_TRY_NOTHING'), 'again' => '%s', 'reading' => '%s',
            ]);
            $result  = $answer->answer($question, [], $language, static function (string $event, array $data) use (&$text, &$sources): bool {
                if ($event === 'delta') {
                    $text .= (string) $data['text'];
                } elseif ($event === 'sources') {
                    $sources = $data['items'];
                }

                return true;
            });

            $hints = new Hints($db, $settings);

            $this->send([
                'text'    => $text,
                'sources' => $sources,
                'hints'   => array_values(array_filter(array_map(static fn ($id) => $hints->get($id)?->question, $result['hints'] ?? []))),
                'tokens'  => $result['input'] + $result['output'],
            ]);
        } catch (\Throwable $e) {
            $this->send($e);
        }
    }

    public function clear(): void
    {
        $this->guard('core.options');

        (new Indexer(Factory::getContainer()->get(DatabaseInterface::class), Settings::load()))->clear();

        $this->send(['cleared' => true]);
    }

    /**
     * Try both calls the component makes, with the saved options.
     */
    public function test(): void
    {
        $this->guard();

        $settings = Settings::load();

        try {
            $client = $settings->client();
            $vector = $client->embeddings($settings->embeddingModel(), ['ping'], $settings->embeddingDimensions())[0];
            $reply  = '';

            $client->stream(
                $settings->apiStyle(),
                $settings->chatModel(),
                'Reply with the single word OK.',
                [['role' => 'user', 'content' => 'ping']],
                100,
                $settings->reasoningEffort(),
                static function (string $text) use (&$reply): bool {
                    $reply .= $text;

                    return true;
                }
            );

            $this->send(['dimensions' => \count($vector), 'reply' => mb_substr(trim($reply), 0, 100)]);
        } catch (ServiceException $e) {
            $this->send($e);
        }
    }

    /**
     * Add the address a question came from to the addresses that may not ask. Not an AJAX task:
     * it is a button next to the address, and leads back to the dashboard.
     */
    public function ban(): void
    {
        $this->checkToken();

        $user   = $this->app->getIdentity();
        $ip     = trim($this->app->getInput()->post->getString('ip', ''));
        $return = Route::_('index.php?option=com_mcpask', false);

        if (!$user->authorise('core.admin', 'com_mcpask') && !$user->authorise('core.options', 'com_mcpask')) {
            $this->setRedirect($return, Text::_('JERROR_ALERTNOAUTHOR'), 'error');

            return;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->setRedirect($return, Text::_('COM_MCPASK_BAN_INVALID'), 'error');

            return;
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $table = new Extension($db);

        $table->load(['type' => 'component', 'element' => 'com_mcpask']);

        $params = new Registry($table->params);
        $listed = preg_split('/\R/u', (string) $params->get('blocked_ips', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (!\in_array($ip, array_map('trim', $listed), true)) {
            $listed[] = $ip;
            $params->set('blocked_ips', implode("\n", array_map('trim', $listed)));
            $table->params = (string) $params;
            $table->store();

            // The options are cached with the list of extensions
            foreach (['_system', 'com_plugins'] as $group) {
                try {
                    Factory::getCache($group, '')->clean();
                } catch (\Throwable) {
                    // The change is saved; it takes effect when the cache expires
                }
            }
        }

        $this->setRedirect($return, Text::sprintf('COM_MCPASK_BAN_DONE', $ip));
    }

    /**
     * Every task needs the form token and access to the component; some need more:
     * "core.edit" to change what visitors are told (source rules), "core.delete" to delete
     * rules, "core.options" (or "core.admin") to empty the index.
     */
    /**
     * Colours for a description of the look, proposed by the AI service (Appearance page).
     */
    public function themeGenerate(): void
    {
        $this->guard('core.options');

        $current = json_decode($this->app->getInput()->post->getRaw('current', '{}'), true);

        try {
            $this->send(Theme::generate(Settings::load(), $this->app->getInput()->post->getString('description', ''), \is_array($current) ? $current : []));
        } catch (\Throwable $e) {
            $this->send(new \RuntimeException($e->getMessage()));
        }
    }

    /**
     * Keep the colours of the Appearance page; none (an empty object) goes back to the module's own.
     */
    public function themeSave(): void
    {
        $this->guard('core.options');

        $colours = json_decode($this->app->getInput()->post->getRaw('colours', '{}'), true);
        $colours = \is_array($colours) && $colours ? Theme::clean($colours) : [];
        $db      = Factory::getContainer()->get(DatabaseInterface::class);
        $table   = new Extension($db);

        $table->load(['type' => 'component', 'element' => 'com_mcpask']);

        $params = new Registry($table->params);
        $params->set('theme', $colours ? json_encode($colours) : '');
        $table->params = (string) $params;
        $table->store();

        foreach (['_system', 'com_plugins'] as $group) {
            try {
                Factory::getCache($group, '')->clean();
            } catch (\Throwable) {
                // Saved; it shows when the cache expires
            }
        }

        $this->send(['colours' => $colours]);
    }

    private function guard(string $action = ''): void
    {
        if (!Session::checkToken('post')) {
            $this->send(new \RuntimeException(Text::_('JINVALID_TOKEN')));
        }

        $user    = $this->app->getIdentity();
        $allowed = $user->authorise('core.manage', 'com_mcpask') && match ($action) {
            ''             => true,
            'core.options' => $user->authorise('core.admin', 'com_mcpask') || $user->authorise('core.options', 'com_mcpask'),
            default        => $user->authorise($action, 'com_mcpask'),
        };

        if (!$allowed) {
            $this->send(new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR')));
        }
    }

    private function send(mixed $data): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        echo new JsonResponse($data);

        $this->app->close();
    }
}
