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

/** @var \Gss\Component\Mcp\Administrator\View\Connections\HtmlView $this */

$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<form action="<?php echo Route::_('index.php?option=com_mcp&view=connections'); ?>" method="post" name="adminForm" id="adminForm">
	<p><?php echo Text::_('COM_MCP_CONNECTIONS_DESC'); ?></p>
	<?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

	<?php if (!$this->items) : ?>
		<div class="alert alert-info">
			<span class="icon-info-circle" aria-hidden="true"></span> <?php echo Text::_('COM_MCP_CONNECTIONS_EMPTY'); ?>
		</div>
	<?php else : ?>
		<table class="table" id="connectionList">
			<caption class="visually-hidden"><?php echo Text::_('COM_MCP_SUBMENU_CONNECTIONS'); ?></caption>
			<thead>
				<tr>
					<td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
					<th scope="col"><?php echo Text::_('COM_MCP_CONNECTIONS_APP'); ?></th>
					<th scope="col"><?php echo Text::_('JGLOBAL_USERNAME'); ?></th>
					<th scope="col"><?php echo Text::_('COM_MCP_CONNECTIONS_SCOPES'); ?></th>
					<th scope="col"><?php echo Text::_('COM_MCP_CONNECTIONS_CREATED'); ?></th>
					<th scope="col"><?php echo Text::_('COM_MCP_CONNECTIONS_LAST_USED'); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($this->items as $i => $item) : ?>
				<?php $redirects = json_decode((string) $item->redirect_uris, true) ?: []; ?>
				<tr>
					<td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, $item->family, false, 'cid', 'cb', $item->client_name); ?></td>
					<td>
						<strong><?php echo $e($item->client_name ?: $item->client_id); ?></strong>
						<div class="small text-muted"><?php echo $e(implode(', ', array_unique(array_map(fn ($r) => parse_url($r, PHP_URL_HOST) ?: $r, $redirects)))); ?></div>
					</td>
					<td><?php echo $e($item->user_name); ?> <span class="small text-muted">(<?php echo $e($item->username); ?>)</span></td>
					<td>
						<?php foreach (array_filter(explode(' ', $item->scopes)) as $scope) : ?>
							<span class="badge bg-secondary"><?php echo $e($scope); ?></span>
						<?php endforeach; ?>
					</td>
					<td class="small"><?php echo HTMLHelper::_('date', $item->connected ?: $item->created, Text::_('DATE_FORMAT_LC5')); ?></td>
					<td class="small"><?php echo $item->last_used ? HTMLHelper::_('date', $item->last_used, Text::_('DATE_FORMAT_LC5')) : '—'; ?></td>
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
