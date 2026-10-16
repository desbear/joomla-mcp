<?php

/**
 * @package     Guides
 * @subpackage  mod_guides_review
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Module\GuidesReview\Administrator\Dispatcher;

use Gss\Component\Guides\Administrator\Guide\Review;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Guides waiting for review and guides to check, on the back-end home page of those who review.
 */
class Dispatcher extends AbstractModuleDispatcher
{
    public function dispatch()
    {
        $user = $this->getApplication()->getIdentity();

        if (ComponentHelper::isEnabled('com_guides') && $user && $user->authorise('core.manage', 'com_guides') && Review::mayReview($user)) {
            parent::dispatch();
        }
    }

    protected function getLayoutData(): array
    {
        $data    = parent::getLayoutData();
        $pending = Review::create(Factory::getContainer()->get(DatabaseInterface::class))->pending();
        $count   = max(1, min(20, (int) $data['params']->get('count', 5)));

        $data['review'] = \array_slice($pending['review'], 0, $count);
        $data['stale']  = \array_slice($pending['stale'], 0, $count);
        $data['counts'] = ['review' => \count($pending['review']), 'stale' => \count($pending['stale'])];

        return $data;
    }
}
