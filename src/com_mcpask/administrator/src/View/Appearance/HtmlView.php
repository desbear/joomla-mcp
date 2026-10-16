<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\View\Appearance;

use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Gss\Component\Mcpask\Administrator\Ask\Theme;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

\defined('_JEXEC') or die;

/**
 * Appearance: the colours of the module, described in words to the AI service or chosen one by
 * one, seen at once in a preview of the module itself.
 *
 * @since  0.7.3
 */
class HtmlView extends BaseHtmlView
{
    /** @var array<string, string>  The colours saved, empty for the module's own */
    public array $saved = [];

    public bool $aiReady = false;

    public function display($tpl = null): void
    {
        $user = Factory::getApplication()->getIdentity();

        if (!$user->authorise('core.admin', 'com_mcpask') && !$user->authorise('core.options', 'com_mcpask')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $settings      = Settings::load();
        $this->saved   = $settings->theme();
        $this->aiReady = $settings->ready();

        ToolbarHelper::title(Text::_('COM_MCPASK_APPEARANCE'), 'palette');
        ToolbarHelper::link('index.php?option=com_mcpask', Text::_('COM_MCPASK_SUBMENU_DASHBOARD'), 'home');
        ToolbarHelper::preferences('com_mcpask');

        parent::display($tpl);
    }

    /**
     * @return  array<string, array{variable: string, default: string, label: string}>
     */
    public function colours(): array
    {
        $colours = [];

        foreach (Theme::COLOURS as $name => [$variable, $default]) {
            $colours[$name] = ['variable' => $variable, 'default' => $default, 'label' => Text::_('COM_MCPASK_THEME_' . strtoupper($name))];
        }

        return $colours;
    }
}
