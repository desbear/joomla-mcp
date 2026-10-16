<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Site\Controller;

use Joomla\CMS\MVC\Controller\BaseController;

\defined('_JEXEC') or die;

/**
 * The site part only serves the OAuth sign-in page (see OauthController).
 *
 * @since  0.2.0
 */
class DisplayController extends BaseController
{
    public function display($cachable = false, $urlparams = [])
    {
        throw new \Exception('Not found', 404);
    }
}
