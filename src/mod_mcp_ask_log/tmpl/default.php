<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  mod_mcp_ask_log
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

/**
 * @var array    $rows   The latest questions
 * @var integer  $today  Questions in the last 24 hours
 */
?>
<div class="p-3">
	<p>
		<?php echo Text::sprintf('MOD_MCP_ASK_LOG_TODAY', (int) $today); ?>
		<a class="ms-2" href="<?php echo Route::_('index.php?option=com_mcpask'); ?>"><?php echo Text::_('MOD_MCP_ASK_LOG_OPEN'); ?></a>
	</p>
	<?php echo LayoutHelper::render('questions', ['rows' => $rows], JPATH_ADMINISTRATOR . '/components/com_mcpask/layouts'); ?>
</div>
