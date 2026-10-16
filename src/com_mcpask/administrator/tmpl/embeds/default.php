<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Gss\Component\Mcpask\Administrator\View\Embeds\HtmlView $this */

$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<form action="<?php echo Route::_('index.php?option=com_mcpask&view=embeds'); ?>" method="post" name="adminForm" id="adminForm">
	<p class="text-muted"><?php echo Text::_('COM_MCPASK_EMBEDS_DESC'); ?></p>

	<?php if (!$this->embeds) : ?>
		<div class="alert alert-info">
			<?php echo Text::_('COM_MCPASK_EMBEDS_NONE'); ?>
			<a class="btn btn-primary btn-sm ms-2" href="<?php echo Route::_('index.php?option=com_mcpask&view=embed'); ?>"><span class="icon-plus" aria-hidden="true"></span> <?php echo Text::_('COM_MCPASK_EMBED_NEW'); ?></a>
		</div>
	<?php else : ?>
		<table class="table align-middle">
			<thead>
				<tr>
					<th class="w-1"><?php echo HTMLHelper::_('grid.checkall'); ?></th>
					<th><?php echo Text::_('COM_MCPASK_EMBED_TITLE'); ?></th>
					<th><?php echo Text::_('COM_MCPASK_EMBED_DOMAINS'); ?></th>
					<th><?php echo Text::_('COM_MCPASK_EMBED_KEY'); ?></th>
					<th class="text-center"><?php echo Text::_('COM_MCPASK_EMBED_USAGE'); ?></th>
					<th class="text-center"><?php echo Text::_('JSTATUS'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($this->embeds as $i => $embed) : ?>
					<?php $use = $this->usage[(int) $embed->id] ?? ['today' => 0, 'month' => 0]; ?>
					<tr>
						<td><?php echo HTMLHelper::_('grid.id', $i, (int) $embed->id); ?></td>
						<td><a href="<?php echo Route::_('index.php?option=com_mcpask&view=embed&id=' . (int) $embed->id); ?>" class="fw-bold"><?php echo $e($embed->title); ?></a></td>
						<td class="small"><?php echo implode('<br>', array_map($e, $embed->origins)) ?: '—'; ?></td>
						<td><code><?php echo $e($embed->public_key); ?></code></td>
						<td class="text-center small text-nowrap">
							<?php echo Text::sprintf('COM_MCPASK_EMBED_USAGE_LINE', $use['today'], $use['month']); ?>
							<?php if ((int) $embed->params->get('per_day', 0) > 0) : ?>
								<div class="text-muted"><?php echo Text::sprintf('COM_MCPASK_EMBED_LIMIT_LINE', (int) $embed->params->get('per_day')); ?></div>
							<?php endif; ?>
						</td>
						<td class="text-center"><?php echo HTMLHelper::_('jgrid.published', (int) $embed->published, $i, 'embeds.', true); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<div class="d-flex gap-2">
			<button type="submit" class="btn btn-outline-danger btn-sm" onclick="if (!document.adminForm.boxchecked.value || !confirm('<?php echo $e(Text::_('COM_MCPASK_EMBED_DELETE_CONFIRM')); ?>')) return false; document.adminForm.task.value='embeds.delete';">
				<span class="icon-trash" aria-hidden="true"></span> <?php echo Text::_('JACTION_DELETE'); ?>
			</button>
		</div>
	<?php endif; ?>

	<input type="hidden" name="task" value="">
	<input type="hidden" name="boxchecked" value="0">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
