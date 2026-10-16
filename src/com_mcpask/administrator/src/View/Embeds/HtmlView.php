<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\View\Embeds;

use Gss\Component\Mcpask\Administrator\Embed\Embeds;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Embed keys: Ask the Site on other websites, with how much each was asked today and in 30 days.
 *
 * @since  0.11.0
 */
class HtmlView extends BaseHtmlView
{
    /** @var object[] */
    public array $embeds = [];

    /** @var array<int, array{today: int, month: int}> */
    public array $usage = [];

    public function display($tpl = null): void
    {
        $user = Factory::getApplication()->getIdentity();

        if (!$user->authorise('core.admin', 'com_mcpask') && !$user->authorise('core.options', 'com_mcpask')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $embeds       = new Embeds(Factory::getContainer()->get(DatabaseInterface::class));
        $this->embeds = $embeds->all();
        $this->usage  = $embeds->usage();

        ToolbarHelper::title(Text::_('COM_MCPASK_EMBEDS'), 'code');
        ToolbarHelper::link('index.php?option=com_mcpask&view=embed', Text::_('COM_MCPASK_EMBED_NEW'), 'new');
        ToolbarHelper::link('index.php?option=com_mcpask', Text::_('COM_MCPASK_SUBMENU_DASHBOARD'), 'home');

        parent::display($tpl);
    }
}
