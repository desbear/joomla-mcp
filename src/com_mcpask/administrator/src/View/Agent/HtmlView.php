<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\View\Agent;

use Gss\Component\Mcpask\Administrator\Agent\AgentInterface;
use Gss\Component\Mcpask\Administrator\Agent\Agents;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * One agent: what it says, what starts it, where it works, and the settings of its kind.
 *
 * @since  0.8.0
 */
class HtmlView extends BaseHtmlView
{
    public ?object $agent = null;

    public ?AgentInterface $kind = null;

    public ?Form $form = null;

    public function display($tpl = null): void
    {
        $app  = Factory::getApplication();
        $user = $app->getIdentity();

        if (!$user->authorise('core.admin', 'com_mcpask') && !$user->authorise('core.options', 'com_mcpask')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $id          = $app->getInput()->getInt('id');
        $this->agent = $id ? (new Agents(Factory::getContainer()->get(DatabaseInterface::class)))->get($id) : null;
        $type        = $this->agent ? $this->agent->type : $app->getInput()->getCmd('type');
        $this->kind  = Agents::types(false)[$type] ?? null;

        if ($id && !$this->agent || !$this->kind) {
            throw new \RuntimeException(Text::_('COM_MCPASK_AGENT_NO_TYPE'), 404);
        }

        $this->form = self::form($this->kind);
        $data       = $app->getUserState('com_mcpask.agent.data');

        if (!$data && $this->agent) {
            $params = $this->agent->params->toArray();
            $data   = ['title' => $this->agent->title, 'published' => $this->agent->published, 'own' => $params['type'] ?? []];
            unset($params['type']);
            $data['params'] = $params;
        }

        if ($data) {
            $this->form->bind($data);
        }

        ToolbarHelper::title(Text::_($this->agent ? 'COM_MCPASK_AGENT_EDIT' : 'COM_MCPASK_AGENT_NEW') . ': ' . $this->kind->title(), 'users');

        // The page is left by its own buttons only
        $app->getInput()->set('hidemainmenu', true);
        ToolbarHelper::apply('agents.apply');
        ToolbarHelper::save('agents.save');
        ToolbarHelper::cancel('agents.cancel', 'JTOOLBAR_CLOSE');

        parent::display($tpl);
    }

    /**
     * The form of an agent of a kind: what every agent has, and what the kind adds.
     */
    public static function form(AgentInterface $kind): Form
    {
        $form = Form::getInstance('com_mcpask.agent.' . $kind->type(), JPATH_ADMINISTRATOR . '/components/com_mcpask/forms/agent.xml', ['control' => 'jform']);

        if ($kind->form() !== '' && is_file($kind->form())) {
            $form->loadFile($kind->form(), false);
        }

        return $form;
    }
}
