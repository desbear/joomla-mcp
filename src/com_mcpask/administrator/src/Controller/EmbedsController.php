<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Controller;

use Gss\Component\Mcpask\Administrator\Embed\Embeds;
use Gss\Component\Mcpask\Administrator\View\Embed\HtmlView as EmbedView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Saving, publishing and deleting embed keys, and giving one a new secret. For those who may
 * change the component's options.
 *
 * @since  0.11.0
 */
class EmbedsController extends BaseController
{
    public function __construct($config = [], $factory = null, $app = null, $input = null)
    {
        parent::__construct($config, $factory, $app, $input);

        // "Save" stays on the page; "Save & Close" goes back to the list
        $this->registerTask('apply', 'save');
    }

    public function cancel(): void
    {
        $this->setRedirect(Route::_('index.php?option=com_mcpask&view=embeds', false));
    }

    public function save(): void
    {
        $this->guard();

        $embeds = new Embeds(Factory::getContainer()->get(DatabaseInterface::class));
        $input  = $this->app->getInput();
        $id     = $input->getInt('id');
        $form   = EmbedView::form();
        $data   = $form->filter($input->post->get('jform', [], 'array'));
        $valid  = $form->validate($data);

        if ($valid === true && !Embeds::origins((string) ($data['domains'] ?? ''))) {
            $form->setError(Text::_('COM_MCPASK_EMBED_NO_DOMAINS'));
            $valid = false;
        }

        if ($valid !== true) {
            foreach ($form->getErrors() as $error) {
                $this->app->enqueueMessage($error instanceof \Throwable ? $error->getMessage() : (string) $error, 'warning');
            }

            $this->app->setUserState('com_mcpask.embed.data', $data);
            $this->back($id);
        }

        $id = $embeds->save(['id' => $id] + $data);
        $this->app->setUserState('com_mcpask.embed.data', null);

        if ($this->getTask() !== 'apply') {
            $this->setRedirect(Route::_('index.php?option=com_mcpask&view=embeds', false), Text::_('COM_MCPASK_EMBED_SAVED'));

            return;
        }

        $this->back($id, Text::_('COM_MCPASK_EMBED_SAVED'));
    }

    /**
     * A new secret for signing visitors' tokens: the other website's server needs it from now on.
     */
    public function secret(): void
    {
        $this->guard();

        $id = $this->app->getInput()->getInt('id');
        (new Embeds(Factory::getContainer()->get(DatabaseInterface::class)))->newSecret($id);

        $this->back($id, Text::_('COM_MCPASK_EMBED_SECRET_NEW'));
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

        $embeds = new Embeds(Factory::getContainer()->get(DatabaseInterface::class));

        foreach ((array) $this->app->getInput()->get('cid', [], 'int') as $id) {
            $embeds->delete((int) $id);
        }

        $this->setRedirect(Route::_('index.php?option=com_mcpask&view=embeds', false), Text::_('COM_MCPASK_EMBED_DELETED'));
    }

    private function state(bool $published): void
    {
        $this->guard();

        $embeds = new Embeds(Factory::getContainer()->get(DatabaseInterface::class));

        foreach ((array) $this->app->getInput()->get('cid', [], 'int') as $id) {
            $embeds->publish((int) $id, $published);
        }

        $this->setRedirect(Route::_('index.php?option=com_mcpask&view=embeds', false));
    }

    private function guard(): void
    {
        $user = $this->app->getIdentity();

        if (!Session::checkToken() || !($user->authorise('core.admin', 'com_mcpask') || $user->authorise('core.options', 'com_mcpask'))) {
            $this->setRedirect(Route::_('index.php?option=com_mcpask&view=embeds', false), Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            $this->redirect();
        }
    }

    private function back(int $id, string $message = ''): never
    {
        $this->setRedirect(Route::_('index.php?option=com_mcpask&view=embed' . ($id ? '&id=' . $id : ''), false), $message !== '' ? $message : null);
        $this->redirect();
    }
}
