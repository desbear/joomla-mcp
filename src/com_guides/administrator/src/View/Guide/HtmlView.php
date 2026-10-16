<?php

/**
 * @package     Guides
 * @subpackage  com_guides
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Guides\Administrator\View\Guide;

use Gss\Component\Guides\Administrator\Guide\Flexicontent;
use Gss\Component\Guides\Administrator\Guide\Review;
use Gss\Component\Guides\Administrator\Guide\Settings;
use Gss\Component\Guides\Administrator\Guide\Store;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The guide editor: what the guide is, its steps (taken from articles, from the step library or
 * written), and where it goes.
 *
 * @since  0.1.0
 */
class HtmlView extends BaseHtmlView
{
    public ?object $guide = null;

    public ?Form $form = null;

    public string $mode = 'type_tags';

    public bool $hasFlexicontent = false;

    public bool $canReview = false;

    public function display($tpl = null): void
    {
        $app      = Factory::getApplication();
        $db       = Factory::getContainer()->get(DatabaseInterface::class);
        $user     = $app->getIdentity();
        $settings = Settings::load();
        $id       = $app->getInput()->getInt('id');
        $guide    = $id ? (new Store($db))->guide($id) : null;

        if ($id && !$guide) {
            throw new \RuntimeException(Text::_('COM_GUIDES_NOT_FOUND'), 404);
        }

        if (!Review::mayEdit($user, $guide)) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $this->guide = $guide ?? (object) [
            'id'            => 0,
            'title'         => '',
            'summary'       => '',
            'prerequisites' => '',
            'verify'        => '',
            'language'      => '*',
            'product'       => '',
            'state'         => 'draft',
            'target_type'   => $settings->targetType(),
            'target_id'     => 0,
            'fc_type'       => $settings->fcType(),
            'catid'         => $settings->placementMode() === 'type_tags' ? $settings->guideCategory() : 0,
            'tags'          => [],
            'next_guide_id' => 0,
            'gaps'          => [],
            'review_note'   => '',
            'steps'         => [],
        ];
        $this->mode            = $settings->placementMode();
        $this->hasFlexicontent = Flexicontent::isAvailable();
        $this->canReview       = Review::mayReview($user);
        $this->form            = Form::getInstance('com_guides.guide', JPATH_ADMINISTRATOR . '/components/com_guides/forms/guide.xml', ['control' => 'guide']);

        if (!$this->hasFlexicontent) {
            $this->form->removeField('target_type');
            $this->form->removeField('fc_type');
        }

        // A guide cannot be followed by itself
        if ($this->guide->id) {
            $this->form->setFieldAttribute('next_guide_id', 'query', 'SELECT id AS value, title AS text FROM #__guides_guides WHERE id <> ' . (int) $this->guide->id . ' ORDER BY title');
        }

        $this->form->bind((array) $this->guide);

        ToolbarHelper::title(Text::_($this->guide->id ? 'COM_GUIDES_EDIT' : 'COM_GUIDES_NEW'), 'list-ol');
        ToolbarHelper::link('index.php?option=com_guides', Text::_('COM_GUIDES_BACK'), 'arrow-left');

        parent::display($tpl);
    }
}
