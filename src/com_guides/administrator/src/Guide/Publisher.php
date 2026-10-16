<?php

/**
 * @package     Guides
 * @subpackage  com_guides
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Guides\Administrator\Guide;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Turns a guide into an article of the site: a Joomla article or a FLEXIcontent item in an
 * existing category, written in the structure every reader of the site understands (see
 * docs/guides-spec.md, section 7): "步驟 N：" headings with "step-N" anchors, a summary,
 * prerequisites, a way to check, a link to the next guide and to the articles each step rests on.
 *
 * @since  0.1.0
 */
final class Publisher
{
    public function __construct(private DatabaseInterface $db, private Settings $settings)
    {
    }

    /**
     * The article text of a guide.
     */
    public function render(object $guide): string
    {
        $english = str_starts_with((string) $guide->language, 'en');
        $label   = static fn (string $zh, string $en) => $english ? $en : $zh;
        $e       = static fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        $html    = ['<div class="guide" data-guide="' . (int) $guide->id . '">'];

        if (trim((string) $guide->summary) !== '') {
            $html[] = '<p class="guide-summary">' . nl2br($e($guide->summary), false) . '</p>';
        }

        $prerequisites = array_filter(array_map('trim', preg_split('/\R/u', (string) $guide->prerequisites) ?: []));

        if ($prerequisites) {
            $html[] = '<h2 id="prerequisites">' . $label('前置條件', 'Before you start') . '</h2>';
            $html[] = '<ul>' . implode('', array_map(static fn ($line) => '<li>' . $e($line) . '</li>', $prerequisites)) . '</ul>';
        }

        foreach (array_values($guide->steps) as $index => $step) {
            $number = $index + 1;
            $html[] = '<h2 id="step-' . $number . '">' . $label('步驟 ' . $number . '：', 'Step ' . $number . ': ') . $e($step->title) . '</h2>';
            $html[] = (string) $step->body;

            if ($step->image !== '') {
                $html[] = '<p class="guide-image"><img src="' . $e($step->image) . '" alt="' . $e($step->title) . '" loading="lazy"></p>';
            }

            if (trim((string) $step->note) !== '') {
                $html[] = '<p class="guide-note"><strong>' . $label('注意：', 'Note: ') . '</strong>' . $e($step->note) . '</p>';
            }

            $links = $this->sourceLinks($step->sources ?? []);

            if ($links) {
                $html[] = '<p class="guide-source">' . $label('參考：', 'See also: ') . implode($label('、', ', '), $links) . '</p>';
            }
        }

        if (trim((string) $guide->verify) !== '') {
            $html[] = '<h2 id="verify">' . $label('完成後確認', 'Check that it worked') . '</h2>';
            $html[] = '<p>' . nl2br($e($guide->verify), false) . '</p>';
        }

        if ($next = $this->next((int) $guide->next_guide_id)) {
            $html[] = '<p class="guide-next">' . $label('下一份：', 'Next: ') . '<a href="' . $e($next['url']) . '">' . $e($next['title']) . '</a></p>';
        }

        $html[] = '</div>';

        return implode("\n", $html);
    }

    /**
     * Publish a guide: create its article, or update the one it already has.
     *
     * @return  array{type: string, id: int, url: string}
     */
    public function publish(object $guide, User $user): array
    {
        $type  = $guide->target_type === 'flexicontent' ? 'flexicontent' : 'content';
        $catid = (int) $guide->catid;

        if ($catid < 1) {
            throw new \RuntimeException('Choose the category the guide is published in.');
        }

        if ($type === 'flexicontent' && !Flexicontent::isAvailable()) {
            throw new \RuntimeException('FLEXIcontent is not installed: publish the guide as a Joomla article.');
        }

        // The article may have been deleted since: then it is created again
        $existing = (int) $guide->target_id > 0 && $this->db->setQuery(
            $this->db->getQuery(true)->select('id')->from($this->db->quoteName('#__content'))->where('id = ' . (int) $guide->target_id)
        )->loadResult() ? (int) $guide->target_id : 0;

        $data = [
            'id'       => $existing,
            'title'    => $guide->title,
            'catid'    => $catid,
            'state'    => $this->settings->publishState(),
            'access'   => $this->settings->publishAccess(),
            'language' => $guide->language ?: '*',
            'text'     => $this->render($guide),
            'tags'     => (array) $guide->tags,
            'metadesc' => mb_substr(trim((string) $guide->summary), 0, 160),
        ];

        if ($type === 'flexicontent') {
            $data['type_id'] = (int) $guide->fc_type ?: $this->settings->fcType();

            if ($data['type_id'] < 1) {
                throw new \RuntimeException('Choose the FLEXIcontent content type guides are published as (in the options).');
            }

            $id = Flexicontent::save($data, $user);
        } else {
            $id = $this->saveArticle($data, $user);
        }

        $row = $this->db->setQuery($this->db->getQuery(true)->select('alias, catid, language')->from($this->db->quoteName('#__content'))->where('id = ' . $id))->loadObject();

        return ['type' => $type, 'id' => $id, 'url' => Content::url($type, $id, (string) ($row->alias ?? ''), (int) ($row->catid ?? $catid), (string) ($row->language ?? '*'))];
    }

    /**
     * Through Joomla's own article model, so assets, tags, versions and plugins are handled as
     * when an editor saves.
     */
    private function saveArticle(array $data, User $user): int
    {
        $id = (int) $data['id'];

        if ($id ? !$user->authorise('core.edit', 'com_content.article.' . $id) : !$user->authorise('core.create', 'com_content.category.' . (int) $data['catid'])) {
            throw new \RuntimeException($id ? 'Not allowed to update article ' . $id . '.' : 'Not allowed to create articles in this category.');
        }

        $app = Factory::getApplication();
        $app->getLanguage()->load('com_content', JPATH_ADMINISTRATOR);

        // In this component's requests JPATH_COMPONENT is com_guides: point the form loader at com_content's forms
        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_content/forms');
        Form::addFieldPath(JPATH_ADMINISTRATOR . '/components/com_content/src/Field');

        $model = $app->bootComponent('com_content')->getMVCFactory()->createModel('Article', 'Administrator', ['ignore_request' => true]);
        $save  = [
            'id'          => $id,
            'title'       => $data['title'],
            'catid'       => $data['catid'],
            'state'       => $data['state'],
            'access'      => $data['access'],
            'language'    => $data['language'],
            'articletext' => $data['text'],
            'metadesc'    => $data['metadesc'],
            'tags'        => array_map('strval', $data['tags']),
        ];

        if (!$id) {
            $save['alias'] = $this->alias((string) $data['title'], (int) $data['catid'], (string) $data['language']);
        }

        // The request's "id" is the guide's; the article form reads it as the article's
        $input    = $app->getInput();
        $previous = $input->get('id');
        $input->set('id', $id);

        try {
            $saved = $model->save($save);
        } finally {
            $input->set('id', $previous);
        }

        if (!$saved) {
            throw new \RuntimeException('Joomla refused to save the article: ' . ((string) $model->getError() ?: 'unknown error'));
        }

        return $id ?: (int) $model->getState('article.id');
    }

    /**
     * An alias no other article of the category has: Joomla makes one unique only when its own
     * article form saves.
     */
    private function alias(string $title, int $catid, string $language): string
    {
        $base  = ApplicationHelper::stringURLSafe($title, $language) ?: Factory::getDate()->format('Y-m-d-H-i-s');
        $alias = $base;

        for ($n = 2; $n < 100; $n++) {
            $taken = $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($this->db->quoteName('#__content'))
                    ->where('catid = ' . $catid)
                    ->where($this->db->quoteName('alias') . ' = :alias')
                    ->bind(':alias', $alias)
            )->loadResult();

            if (!$taken) {
                break;
            }

            $alias = $base . '-' . $n;
        }

        return $alias;
    }

    /**
     * Links to the articles a step rests on, each once.
     *
     * @param   object[]  $sources
     *
     * @return  string[]
     */
    private function sourceLinks(array $sources): array
    {
        $links = [];

        foreach ($sources as $source) {
            $key = $source->source_type . ':' . $source->item_id;

            if (isset($links[$key])) {
                continue;
            }

            $row = $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select('id, title, alias, catid, language, state')
                    ->from($this->db->quoteName('#__content'))
                    ->where('id = ' . (int) $source->item_id)
            )->loadObject();

            // Only to what visitors can open
            if ($row && (int) $row->state === 1) {
                $links[$key] = '<a href="' . htmlspecialchars(Content::url($source->source_type, (int) $row->id, (string) $row->alias, (int) $row->catid, (string) $row->language), ENT_QUOTES, 'UTF-8') . '">'
                    . htmlspecialchars((string) $row->title, ENT_QUOTES, 'UTF-8') . '</a>';
            }
        }

        return array_values($links);
    }

    /**
     * @return  array{title: string, url: string}|null  The next guide, when it is published
     */
    private function next(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $db  = $this->db;
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select('g.title, g.target_type, a.id, a.alias, a.catid, a.language')
                ->from($db->quoteName('#__guides_guides', 'g'))
                ->join('INNER', $db->quoteName('#__content', 'a'), 'a.id = g.target_id AND a.state = 1')
                ->where('g.id = ' . $id)
        )->loadObject();

        return $row ? ['title' => (string) $row->title, 'url' => Content::url((string) $row->target_type, (int) $row->id, (string) $row->alias, (int) $row->catid, (string) $row->language)] : null;
    }
}
