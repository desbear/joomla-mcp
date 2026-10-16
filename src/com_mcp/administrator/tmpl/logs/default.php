<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

/** @var \Gss\Component\Mcp\Administrator\View\Logs\HtmlView $this */

$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<form action="<?php echo Route::_('index.php?option=com_mcp&view=logs'); ?>" method="post" name="adminForm" id="adminForm">
	<?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

	<?php if (!$this->items) : ?>
		<div class="alert alert-info">
			<span class="icon-info-circle" aria-hidden="true"></span> <?php echo Text::_('COM_MCP_RECENT_EMPTY'); ?>
		</div>
	<?php else : ?>
		<table class="table" id="logList">
			<caption class="visually-hidden"><?php echo Text::_('COM_MCP_SUBMENU_LOGS'); ?></caption>
			<thead>
				<tr>
					<th scope="col" class="w-15"><?php echo Text::_('JDATE'); ?></th>
					<th scope="col"><?php echo Text::_('COM_MCP_TOOL_NAME'); ?></th>
					<th scope="col"><?php echo Text::_('JGLOBAL_USERNAME'); ?></th>
					<th scope="col"><?php echo Text::_('JSTATUS'); ?></th>
					<th scope="col" class="d-none d-md-table-cell"><?php echo Text::_('COM_MCP_LOG_DURATION'); ?></th>
					<th scope="col" class="d-none d-md-table-cell"><?php echo Text::_('COM_MCP_LOG_CLIENT'); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($this->items as $item) : ?>
				<tr>
					<td class="small text-nowrap"><?php echo HTMLHelper::_('date', $item->created, Text::_('DATE_FORMAT_LC6')); ?></td>
					<td>
						<code><?php echo $e($item->tool); ?></code>
						<?php if ($item->arguments || $item->message) : ?>
							<details class="small mt-1">
								<summary><?php echo Text::_('COM_MCP_LOG_DETAILS'); ?></summary>
								<?php if ($item->arguments) : ?>
									<pre class="bg-light p-2 mb-1" style="white-space: pre-wrap; max-height: 20rem; overflow: auto;"><?php echo $e(json_encode(json_decode($item->arguments), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
								<?php endif; ?>
								<?php if ($item->message) : ?>
									<div class="text-danger"><?php echo $e($item->message); ?></div>
								<?php endif; ?>
							</details>
						<?php endif; ?>
					</td>
					<td class="small"><?php echo $e($item->user_name ?: '#' . $item->user_id); ?></td>
					<td><span class="badge bg-<?php echo $item->status === 'success' ? 'success' : 'danger'; ?>"><?php echo $e($item->status); ?></span></td>
					<td class="d-none d-md-table-cell small"><?php echo (int) $item->duration_ms; ?> ms</td>
					<td class="d-none d-md-table-cell small text-muted"><?php echo $e($item->ip); ?><br><?php echo $e($item->user_agent); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo $this->pagination->getListFooter(); ?>
	<?php endif; ?>

	<input type="hidden" name="task" value="">
	<input type="hidden" name="boxchecked" value="0">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
