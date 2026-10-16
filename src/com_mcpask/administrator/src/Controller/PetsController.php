<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Controller;

use Gss\Component\Mcpask\Administrator\Pet\Pets;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Saving a pet with the pictures uploaded for it, deleting pets, and making one from the module's
 * own. For those who may change the component's options.
 *
 * @since  0.12.0
 */
class PetsController extends BaseController
{
    public function __construct($config = [], $factory = null, $app = null, $input = null)
    {
        parent::__construct($config, $factory, $app, $input);

        // "Save" stays on the page; "Save & Close" goes back to the list
        $this->registerTask('apply', 'save');
    }

    public function cancel(): void
    {
        $this->setRedirect(Route::_('index.php?option=com_mcpask&view=pets', false));
    }

    public function save(): void
    {
        $this->guard();

        $pets  = new Pets(Factory::getContainer()->get(DatabaseInterface::class));
        $input = $this->app->getInput();
        $id    = $input->getInt('id');
        $form  = Form::getInstance('com_mcpask.pet', JPATH_ADMINISTRATOR . '/components/com_mcpask/forms/pet.xml', ['control' => 'jform']);
        $data  = $form->filter($input->post->get('jform', [], 'array'));

        if ($form->validate($data) !== true) {
            foreach ($form->getErrors() as $error) {
                $this->app->enqueueMessage($error instanceof \Throwable ? $error->getMessage() : (string) $error, 'warning');
            }

            $this->back($id);
        }

        // What it had, with this form's settings over it
        $params = array_merge($id && ($pet = $pets->get($id)) ? $pet->params->toArray() : [], (array) ($data['params'] ?? []));
        $id     = $pets->save(['id' => $id, 'title' => $data['title'] ?? '', 'params' => $params]);

        // How long the moments that play once last
        foreach ((array) $input->post->get('seconds', [], 'array') as $name => $value) {
            if (isset(Pets::ACTIONS[$name]) && Pets::ACTIONS[$name] > 0) {
                $params[$name . '_seconds'] = round(max(0.3, min(10.0, (float) $value)), 2);
            }
        }

        // Pictures taken away, then those uploaded
        foreach ((array) $input->post->get('remove', [], 'array') as $field => $on) {
            if ($on && isset(Pets::pictures()[$field])) {
                $params[Pets::pictures()[$field]] = '';
            }
        }

        foreach ((array) $input->files->get('pictures', [], 'raw') as $field => $file) {
            if (!\is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            try {
                if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
                    throw new \RuntimeException(Text::sprintf('COM_MCPASK_PET_ERROR_SIZE', (string) ($file['name'] ?? '')));
                }

                if (!isset(Pets::pictures()[$field])) {
                    continue;
                }

                $params[Pets::pictures()[$field]] = Pets::store($id, (string) $field, (string) $file['tmp_name'], (string) $file['name']);
            } catch (\RuntimeException $e) {
                $this->app->enqueueMessage($e->getMessage(), 'warning');
            }
        }

        $pets->save(['id' => $id, 'title' => $data['title'] ?? '', 'params' => $params]);

        if (!$pets->display((string) $id)) {
            $this->app->enqueueMessage(Text::_('COM_MCPASK_PET_NEEDS_IDLE'), 'warning');
        }

        if ($this->getTask() !== 'apply') {
            $this->setRedirect(Route::_('index.php?option=com_mcpask&view=pets', false), Text::_('COM_MCPASK_PET_SAVED'));

            return;
        }

        $this->back($id, Text::_('COM_MCPASK_PET_SAVED'));
    }

    /**
     * A pet of the library from the one that comes with the module, to change as wished.
     */
    public function copy(): void
    {
        $this->guard();

        try {
            $id = (new Pets(Factory::getContainer()->get(DatabaseInterface::class)))->copyBuiltin(Text::_('COM_MCPASK_PET_BUILTIN_COPY'));
        } catch (\RuntimeException $e) {
            $this->setRedirect(Route::_('index.php?option=com_mcpask&view=pets', false), $e->getMessage(), 'error');

            return;
        }

        $this->back($id, Text::_('COM_MCPASK_PET_SAVED'));
    }

    public function delete(): void
    {
        $this->guard();

        $pets = new Pets(Factory::getContainer()->get(DatabaseInterface::class));

        foreach ((array) $this->app->getInput()->get('cid', [], 'int') as $id) {
            $pets->delete((int) $id);
        }

        $this->setRedirect(Route::_('index.php?option=com_mcpask&view=pets', false), Text::_('COM_MCPASK_PET_DELETED'));
    }

    private function guard(): void
    {
        $user = $this->app->getIdentity();

        if (!Session::checkToken() || !($user->authorise('core.admin', 'com_mcpask') || $user->authorise('core.options', 'com_mcpask'))) {
            $this->setRedirect(Route::_('index.php?option=com_mcpask&view=pets', false), Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            $this->redirect();
        }
    }

    private function back(int $id, string $message = ''): never
    {
        $this->setRedirect(Route::_('index.php?option=com_mcpask&view=pet' . ($id ? '&id=' . $id : ''), false), $message !== '' ? $message : null);
        $this->redirect();
    }
}
