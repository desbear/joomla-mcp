<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Where visitors of an embedded Ask the Site sign in: this site's login page, or a published
 * server of External Login (such as an account centre over CAS), when that is installed.
 *
 * @since  0.11.0
 */
class McpaskloginField extends ListField
{
    protected $type = 'Mcpasklogin';

    protected function getOptions()
    {
        $options = [HTMLHelper::_('select.option', '0', Text::_('COM_MCPASK_EMBED_LOGIN_SITE'))];
        $db      = Factory::getContainer()->get(DatabaseInterface::class);

        try {
            if (\in_array($db->replacePrefix('#__externallogin_servers'), $db->getTableList(), true)) {
                foreach ($db->setQuery(
                    $db->getQuery(true)->select('id, title')->from($db->quoteName('#__externallogin_servers'))->where('published = 1')->order('ordering')
                )->loadObjectList() as $server) {
                    $options[] = HTMLHelper::_('select.option', (string) $server->id, $server->title);
                }
            }
        } catch (\Throwable) {
            // This site's login page only
        }

        return array_merge(parent::getOptions(), $options);
    }
}
