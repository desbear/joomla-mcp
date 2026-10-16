<?php

/**
 * @package     Ask the Site
 * @subpackage  plg_mcpask_rsform
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcpask\Rsform\Field;

use Gss\Plugin\Mcpask\Rsform\Agent\RsformForm;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The published RSForm! Pro forms.
 *
 * @since  1.0.0
 */
final class RsformformsField extends ListField
{
    protected $type = 'Rsformforms';

    protected function getOptions()
    {
        $options = [HTMLHelper::_('select.option', '', '- ' . \Joomla\CMS\Language\Text::_('JSELECT') . ' -')];

        if (RsformForm::available()) {
            foreach (RsformForm::forms(Factory::getContainer()->get(DatabaseInterface::class)) as $id => $title) {
                $options[] = HTMLHelper::_('select.option', (string) $id, $title . ' (#' . $id . ')');
            }
        }

        return array_merge(parent::getOptions(), $options);
    }
}
