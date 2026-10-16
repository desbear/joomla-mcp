<?php

/**
 * @package     Guides
 * @subpackage  com_guides
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Guides\Administrator\Controller;

use Gss\Component\Guides\Administrator\Guide\Content;
use Gss\Component\Guides\Administrator\Guide\Placement;
use Gss\Component\Guides\Administrator\Guide\Publisher;
use Gss\Component\Guides\Administrator\Guide\Review;
use Gss\Component\Guides\Administrator\Guide\Settings;
use Gss\Component\Guides\Administrator\Guide\Store;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * What the guide editor and the review block call. Every task answers with JSON.
 *
 * @since  0.1.0
 */
class GuideController extends BaseController
{
    private ?DatabaseInterface $db = null;

    public function load(): void
    {
        $this->guard();

        $guide = $this->store()->guide($this->input->post->getInt('id'));

        if (!$guide) {
            $this->send(new \InvalidArgumentException(Text::_('COM_GUIDES_NOT_FOUND')));
        }

        $guide->tag_titles = Review::create($this->db())->tagTitles($guide->tags);

        foreach ($guide->steps as $step) {
            $step->guides = $this->store()->usage((int) $step->id);
        }

        $this->send($guide);
    }

    public function save(): void
    {
        $this->guard();

        $data  = json_decode($this->input->post->getRaw('guide', ''), true);
        $user  = $this->app->getIdentity();
        $store = $this->store();

        if (!\is_array($data)) {
            $this->send(new \InvalidArgumentException('No guide was sent.'));
        }

        $current = (int) ($data['id'] ?? 0) ? $store->guide((int) $data['id']) : null;

        if (((int) ($data['id'] ?? 0) && !$current) || !Review::mayEdit($user, $current)) {
            $this->send(new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR')));
        }

        try {
            $id = $store->save($data, (int) $user->id);
        } catch (\InvalidArgumentException $e) {
            $this->send($e);
        }

        $this->send(['id' => $id, 'state' => $store->guide($id)->state]);
    }

    /**
     * Articles to take steps from.
     */
    public function search(): void
    {
        $this->guard();

        $this->send((new Content($this->db()))->search(
            mb_substr($this->input->post->getString('words', ''), 0, 200),
            $this->input->post->getBool('text', false)
        ));
    }

    /**
     * The sections of one article, each of which can become a step.
     */
    public function sections(): void
    {
        $this->guard();

        $content = new Content($this->db());
        $type    = $this->input->post->getCmd('source_type', 'content');
        $id      = $this->input->post->getInt('item_id');
        $item    = $content->item($type, $id);

        if (!$item) {
            $this->send(new \InvalidArgumentException(Text::_('COM_GUIDES_SOURCE_NOT_FOUND')));
        }

        unset($item['html']);
        $item['sections'] = $content->sections($type, $id);

        $this->send($item);
    }

    /**
     * Steps of the library, or one of them to take into a guide.
     */
    public function library(): void
    {
        $this->guard();

        $id = $this->input->post->getInt('step_id');

        if ($id) {
            $step = $this->store()->step($id);

            if ($step) {
                $step->guides = $this->store()->usage($id);
            }

            $this->send($step ?: new \InvalidArgumentException(Text::_('COM_GUIDES_NOT_FOUND')));
        }

        $this->send($this->store()->library(mb_substr($this->input->post->getString('words', ''), 0, 200)));
    }

    public function placement(): void
    {
        $this->guard();

        $sources = json_decode($this->input->post->getRaw('sources', '[]'), true);

        $this->send((new Placement($this->db(), Settings::load()))->suggest(
            \is_array($sources) ? \array_slice($sources, 0, 200) : [],
            $this->input->post->getString('title', ''),
            $this->input->post->getString('summary', '')
        ));
    }

    /**
     * The article a guide becomes, as it is now (saved).
     */
    public function preview(): void
    {
        $this->guard();

        $guide = $this->store()->guide($this->input->post->getInt('id'));

        if (!$guide) {
            $this->send(new \InvalidArgumentException(Text::_('COM_GUIDES_NOT_FOUND')));
        }

        $content = new Content($this->db());
        $steps   = [];

        // Next to each step, the text it was taken from, for the reviewer
        foreach ($guide->steps as $step) {
            $origins = [];

            foreach ($step->sources as $source) {
                $sections = $content->sections((string) $source->source_type, (int) $source->item_id);
                $current  = array_values(array_filter($sections, static fn ($part) => $part['section'] === $source->section))[0] ?? null;

                $origins[] = [
                    'title'   => (string) $source->title,
                    'section' => (string) $source->section,
                    'html'    => $current['html'] ?? '',
                    'missing' => $current === null,
                    'changed' => $current !== null && $source->hash !== '' && $current['hash'] !== $source->hash,
                ];
            }

            $steps[] = ['id' => (int) $step->id, 'title' => (string) $step->title, 'origins' => $origins];
        }

        $this->send(['html' => (new Publisher($this->db(), Settings::load()))->render($guide), 'steps' => $steps]);
    }

    public function submit(): void
    {
        $this->guard();
        $this->reviewTask(fn (Review $review) => $review->submit($this->input->post->getInt('id'), $this->app->getIdentity()));
    }

    public function approve(): void
    {
        $this->guard();

        $changes = ['note' => $this->input->post->getString('note', '')];

        if ($this->input->post->getInt('catid')) {
            $changes['catid'] = $this->input->post->getInt('catid');
        }

        if ($this->input->post->get('tags', null, 'raw') !== null) {
            $changes['tags'] = array_map('intval', (array) json_decode($this->input->post->getRaw('tags', '[]'), true));
        }

        $this->reviewTask(fn (Review $review) => $review->approve($this->input->post->getInt('id'), $this->app->getIdentity(), $changes));
    }

    public function reject(): void
    {
        $this->guard();
        $this->reviewTask(fn (Review $review) => $review->reject($this->input->post->getInt('id'), $this->app->getIdentity(), $this->input->post->getString('note', '')));
    }

    /**
     * Delete a guide. Its published article stays: it is the site's, to unpublish there.
     */
    public function delete(): void
    {
        $this->guard('core.delete');

        $id = $this->input->post->getInt('id');

        if (!$this->store()->guide($id)) {
            $this->send(new \InvalidArgumentException(Text::_('COM_GUIDES_NOT_FOUND')));
        }

        $this->store()->delete($id);
        $this->send(['deleted' => $id]);
    }

    private function reviewTask(callable $task): never
    {
        try {
            $this->send($task(Review::create($this->db())));
        } catch (\Throwable $e) {
            $this->send(new \RuntimeException($e->getMessage()));
        }
    }

    private function guard(string $action = ''): void
    {
        if (!Session::checkToken('post')) {
            $this->send(new \RuntimeException(Text::_('JINVALID_TOKEN')));
        }

        $user = $this->app->getIdentity();

        if (!$user->authorise('core.manage', 'com_guides') || ($action !== '' && !$user->authorise($action, 'com_guides'))) {
            $this->send(new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR')));
        }
    }

    private function db(): DatabaseInterface
    {
        return $this->db ??= Factory::getContainer()->get(DatabaseInterface::class);
    }

    private function store(): Store
    {
        return new Store($this->db());
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
