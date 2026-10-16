<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Site\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;

\defined('_JEXEC') or die;

/**
 * The component's only page is a report written for a visitor (found by its key); otherwise
 * visitors use the module, which talks to AskController and AgentController.
 *
 * @since  0.1.0
 */
class DisplayController extends BaseController
{
    public function display($cachable = false, $urlparams = [])
    {
        if ($this->input->getCmd('view') !== 'report') {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        // Each report is the visitor's own: never cached
        return parent::display(false, []);
    }
}
