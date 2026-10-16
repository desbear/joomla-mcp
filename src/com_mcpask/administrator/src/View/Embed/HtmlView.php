<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\View\Embed;

use Gss\Component\Mcpask\Administrator\Embed\Embeds;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * One embed key: where it may be shown, how it looks, how much it may be asked, how visitors sign
 * in; and, once saved, the line of script to put in the other website's pages.
 *
 * @since  0.11.0
 */
class HtmlView extends BaseHtmlView
{
    public ?object $embed = null;

    public ?Form $form = null;

    public function display($tpl = null): void
    {
        $app  = Factory::getApplication();
        $user = $app->getIdentity();

        if (!$user->authorise('core.admin', 'com_mcpask') && !$user->authorise('core.options', 'com_mcpask')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $id          = $app->getInput()->getInt('id');
        $this->embed = $id ? (new Embeds(Factory::getContainer()->get(DatabaseInterface::class)))->get($id) : null;

        if ($id && !$this->embed) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $this->form = self::form();
        $data       = $app->getUserState('com_mcpask.embed.data');

        if (!$data && $this->embed) {
            $data = ['title' => $this->embed->title, 'domains' => $this->embed->domains, 'published' => $this->embed->published, 'params' => $this->embed->params->toArray()];
        }

        if ($data) {
            $this->form->bind($data);
        }

        ToolbarHelper::title(Text::_($this->embed ? 'COM_MCPASK_EMBED_EDIT' : 'COM_MCPASK_EMBED_NEW'), 'code');

        // The page is left by its own buttons only
        $app->getInput()->set('hidemainmenu', true);
        ToolbarHelper::apply('embeds.apply');
        ToolbarHelper::save('embeds.save');
        ToolbarHelper::cancel('embeds.cancel', 'JTOOLBAR_CLOSE');

        parent::display($tpl);
    }

    public static function form(): Form
    {
        return Form::getInstance('com_mcpask.embed', JPATH_ADMINISTRATOR . '/components/com_mcpask/forms/embed.xml', ['control' => 'jform']);
    }
}
