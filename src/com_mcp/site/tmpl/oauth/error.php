<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * @var string  $message
 * @var string  $siteName
 * @var string  $logoutUrl
 */

$title = Text::_('COM_MCP_OAUTH_ERROR_TITLE');

include __DIR__ . '/_head.php';
?>
<h1><?php echo $e($title); ?></h1>
<p><?php echo $e($message); ?></p>
<?php if (!empty($logout)) : ?>
	<p><a href="<?php echo $e($logoutUrl); ?>"><?php echo Text::_('COM_MCP_OAUTH_SWITCH_ACCOUNT'); ?></a></p>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php';
