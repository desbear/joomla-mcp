<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\View\Pets;

use Gss\Component\Mcpask\Administrator\Pet\Pets;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The pet library: pets the floating button can be, the module's own among them.
 *
 * @since  0.12.0
 */
class HtmlView extends BaseHtmlView
{
    /** @var object[] */
    public array $pets = [];

    /** @var array<string, array>  What the button would show of each, by choice */
    public array $shown = [];

    public function display($tpl = null): void
    {
        $user = Factory::getApplication()->getIdentity();

        if (!$user->authorise('core.admin', 'com_mcpask') && !$user->authorise('core.options', 'com_mcpask')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $pets       = new Pets(Factory::getContainer()->get(DatabaseInterface::class));
        $this->pets = $pets->all();

        foreach (['default', ...array_map(static fn ($pet) => (string) $pet->id, $this->pets)] as $choice) {
            $this->shown[$choice] = $pets->display($choice) ?? [];
        }

        ToolbarHelper::title(Text::_('COM_MCPASK_PETS'), 'image');
        ToolbarHelper::link('index.php?option=com_mcpask&view=pet', Text::_('COM_MCPASK_PET_NEW'), 'new');
        ToolbarHelper::link('index.php?option=com_mcpask', Text::_('COM_MCPASK_SUBMENU_DASHBOARD'), 'home');

        parent::display($tpl);
    }
}
