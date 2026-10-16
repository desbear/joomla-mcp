<?php

/**
 * @package     Guides
 * @subpackage  com_guides
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Guides\Administrator\View\Guides;

use Gss\Component\Guides\Administrator\Guide\Review;
use Gss\Component\Guides\Administrator\Guide\Store;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The guides, with what waits for a review on top for those who review.
 *
 * @since  0.1.0
 */
class HtmlView extends BaseHtmlView
{
    /** @var object[] */
    public array $guides = [];

    /** @var array{review: object[], stale: object[]} */
    public array $pending = ['review' => [], 'stale' => []];

    /** @var array{state: string, search: string} */
    public array $filters = ['state' => '', 'search' => ''];

    public bool $canReview = false;

    public bool $canCreate = false;

    public bool $canDelete = false;

    public function display($tpl = null): void
    {
        $app   = Factory::getApplication();
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $user  = $app->getIdentity();
        $input = $app->getInput();

        $this->filters = [
            'state'  => \in_array($input->getCmd('filter_state', ''), Store::STATES, true) ? $input->getCmd('filter_state') : '',
            'search' => mb_substr(trim($input->getString('filter_search', '')), 0, 100),
        ];
        $this->guides    = (new Store($db))->guides($this->filters);
        $this->canReview = Review::mayReview($user);
        $this->canCreate = $user->authorise('core.create', 'com_guides');
        $this->canDelete = $user->authorise('core.delete', 'com_guides');

        if ($this->canReview) {
            $this->pending = Review::create($db)->pending();
        }

        ToolbarHelper::title(Text::_('COM_GUIDES'), 'list-ol');

        if ($this->canCreate) {
            ToolbarHelper::link('index.php?option=com_guides&view=guide', Text::_('COM_GUIDES_NEW'), 'new');
        }

        if ($user->authorise('core.admin', 'com_guides') || $user->authorise('core.options', 'com_guides')) {
            ToolbarHelper::preferences('com_guides');
        }

        parent::display($tpl);
    }
}
