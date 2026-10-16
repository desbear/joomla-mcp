<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Controller;

use Gss\Component\Mcpask\Administrator\Agent\Agents;
use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Gss\Component\Mcpask\Administrator\View\Agent\HtmlView as AgentView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Agents: save one, publish or unpublish it, delete it. Setting up agents is a matter of the
 * component's options.
 *
 * @since  0.8.0
 */
class AgentsController extends BaseController
{
    public function __construct($config = [], $factory = null, $app = null, $input = null)
    {
        parent::__construct($config, $factory, $app, $input);

        // "Save" stays on the page; "Save & Close" goes back to the list
        $this->registerTask('apply', 'save');
    }

    public function cancel(): void
    {
        $this->setRedirect(Route::_('index.php?option=com_mcpask&view=agents', false));
    }

    public function save(): void
    {
        $this->guard();

        $db     = Factory::getContainer()->get(DatabaseInterface::class);
        $agents = new Agents($db);
        $input  = $this->app->getInput();
        $id     = $input->getInt('id');
        $type   = $id ? ($agents->get($id)->type ?? '') : $input->getCmd('type');
        $kind   = Agents::types(false)[$type] ?? null;

        if (!$kind) {
            $this->back(0, Text::_('COM_MCPASK_AGENT_NO_TYPE'), 'error');
        }

        $form = AgentView::form($kind);
        $data = $input->post->get('jform', [], 'array');

        // Checked and filtered by the form, as Joomla's own forms are
        $data  = $form->filter($data);
        $valid = $form->validate($data);

        if ($valid !== true) {
            foreach ($form->getErrors() as $error) {
                $this->app->enqueueMessage($error instanceof \Throwable ? $error->getMessage() : (string) $error, 'warning');
            }

            $this->app->setUserState('com_mcpask.agent.data', $data);
            $this->back($id, '', '', $type);
        }

        try {
            $id = $agents->save(['id' => $id, 'type' => $type] + $data, Settings::load());
        } catch (\Throwable $e) {
            $this->app->setUserState('com_mcpask.agent.data', $data);
            $this->back($id, $e->getMessage(), 'error', $type);
        }

        $this->app->setUserState('com_mcpask.agent.data', null);

        if ($this->getTask() !== 'apply') {
            $this->setRedirect(Route::_('index.php?option=com_mcpask&view=agents', false), Text::_('COM_MCPASK_AGENT_SAVED'));

            return;
        }

        $this->back($id, Text::_('COM_MCPASK_AGENT_SAVED'));
    }

    public function publish(): void
    {
        $this->state(true);
    }

    public function unpublish(): void
    {
        $this->state(false);
    }

    public function delete(): void
    {
        $this->guard();

        $agents = new Agents(Factory::getContainer()->get(DatabaseInterface::class));

        foreach ((array) $this->app->getInput()->get('cid', [], 'int') as $id) {
            $agents->delete((int) $id);
        }

        $this->setRedirect(Route::_('index.php?option=com_mcpask&view=agents', false), Text::_('COM_MCPASK_AGENT_DELETED'));
    }

    private function state(bool $published): void
    {
        $this->guard();

        $agents = new Agents(Factory::getContainer()->get(DatabaseInterface::class));

        foreach ((array) $this->app->getInput()->get('cid', [], 'int') as $id) {
            $agents->publish((int) $id, $published);
        }

        $this->setRedirect(Route::_('index.php?option=com_mcpask&view=agents', false));
    }

    private function guard(): void
    {
        $user = $this->app->getIdentity();

        if (!Session::checkToken() || !($user->authorise('core.admin', 'com_mcpask') || $user->authorise('core.options', 'com_mcpask'))) {
            $this->setRedirect(Route::_('index.php?option=com_mcpask&view=agents', false), Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            $this->redirect();
        }
    }

    private function back(int $id, string $message, string $type = 'message', string $kind = ''): never
    {
        $this->setRedirect(
            Route::_('index.php?option=com_mcpask&view=agent' . ($id ? '&id=' . $id : '&type=' . $kind), false),
            $message !== '' ? $message : null,
            $type
        );
        $this->redirect();

        // redirect() ends the request
        $this->app->close();
    }
}
