<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\View\Pet;

use Gss\Component\Mcpask\Administrator\Pet\Pets;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * One pet: its name, sizes, words and sleep; a picture for each moment (uploaded here, as APNG,
 * animated AVIF, WebP or GIF), how long the moments that play once last, and a still picture.
 *
 * @since  0.12.0
 */
class HtmlView extends BaseHtmlView
{
    public ?object $pet = null;

    public ?Form $form = null;

    /** @var array|null  What the button would show of it */
    public ?array $shown = null;

    /** @var array<string, int>  Bytes of each picture */
    public array $sizes = [];

    public function display($tpl = null): void
    {
        $app  = Factory::getApplication();
        $user = $app->getIdentity();

        if (!$user->authorise('core.admin', 'com_mcpask') && !$user->authorise('core.options', 'com_mcpask')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $id        = $app->getInput()->getInt('id');
        $pets      = new Pets(Factory::getContainer()->get(DatabaseInterface::class));
        $this->pet = $id ? $pets->get($id) : null;

        if ($id && !$this->pet) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $this->form = Form::getInstance('com_mcpask.pet', JPATH_ADMINISTRATOR . '/components/com_mcpask/forms/pet.xml', ['control' => 'jform']);

        if ($this->pet) {
            $this->form->bind(['title' => $this->pet->title, 'params' => $this->pet->params->toArray()]);
            $this->shown = $pets->display((string) $this->pet->id);
            $this->sizes = Pets::sizes($this->pet->params);
        }

        ToolbarHelper::title(Text::_($this->pet ? 'COM_MCPASK_PET_EDIT' : 'COM_MCPASK_PET_NEW'), 'image');

        // The page is left by its own buttons only
        $app->getInput()->set('hidemainmenu', true);
        ToolbarHelper::apply('pets.apply');
        ToolbarHelper::save('pets.save');
        ToolbarHelper::cancel('pets.cancel', 'JTOOLBAR_CLOSE');

        parent::display($tpl);
    }
}
