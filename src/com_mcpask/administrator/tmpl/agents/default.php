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

/** @var \Gss\Component\Mcpask\Administrator\View\Agents\HtmlView $this */

$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<form action="<?php echo Route::_('index.php?option=com_mcpask&view=agents'); ?>" method="post" name="adminForm" id="adminForm">
	<div class="card mb-3">
		<div class="card-body">
			<p class="text-muted mb-3"><?php echo Text::_('COM_MCPASK_AGENTS_DESC'); ?></p>
			<?php if (!$this->types) : ?>
				<div class="alert alert-info mb-0"><?php echo Text::_('COM_MCPASK_AGENTS_NO_TYPES'); ?></div>
			<?php else : ?>
				<div class="d-flex flex-wrap gap-2">
					<?php foreach ($this->types as $type) : ?>
						<a class="btn btn-primary" href="<?php echo Route::_('index.php?option=com_mcpask&view=agent&type=' . $type->type()); ?>" title="<?php echo $e($type->description()); ?>">
							<span class="icon-plus" aria-hidden="true"></span> <?php echo Text::sprintf('COM_MCPASK_AGENT_ADD', $e($type->title())); ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php if ($this->agents) : ?>
		<table class="table align-middle">
			<thead>
				<tr>
					<th class="w-1"><?php echo HTMLHelper::_('grid.checkall'); ?></th>
					<th><?php echo Text::_('COM_MCPASK_AGENT_TITLE'); ?></th>
					<th><?php echo Text::_('COM_MCPASK_AGENT_TYPE'); ?></th>
					<th><?php echo Text::_('COM_MCPASK_AGENT_TRIGGERS'); ?></th>
					<th class="text-center"><?php echo Text::_('COM_MCPASK_AGENT_STATS'); ?></th>
					<th class="text-center"><?php echo Text::_('JSTATUS'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($this->agents as $i => $agent) : ?>
					<?php
					$kind     = $this->known[$agent->type] ?? null;
					$stat     = $this->stats[(int) $agent->id] ?? [];
					$triggers = array_filter([
						$agent->params->get('suggest', 1) ? Text::_('COM_MCPASK_AGENT_SUGGEST') : '',
						trim((string) $agent->params->get('phrases', '')) !== '' ? Text::_('COM_MCPASK_AGENT_PHRASES') : '',
						$agent->params->get('ai_offer', 0) ? Text::_('COM_MCPASK_AGENT_AI_OFFER') : '',
					]);
					?>
					<tr>
						<td><?php echo HTMLHelper::_('grid.id', $i, (int) $agent->id); ?></td>
						<td>
							<a href="<?php echo Route::_('index.php?option=com_mcpask&view=agent&id=' . (int) $agent->id); ?>" class="fw-bold"><?php echo $e($agent->title); ?></a>
						</td>
						<td>
							<?php echo $kind ? $e($kind->title()) : '<span class="text-danger">' . $e($agent->type) . '</span>'; ?>
							<?php if ($kind && !$kind->available()) : ?>
								<div class="small text-danger"><?php echo Text::_('COM_MCPASK_AGENT_UNAVAILABLE_HERE'); ?></div>
							<?php endif; ?>
						</td>
						<td class="small"><?php echo $e(implode('、', $triggers)) ?: '—'; ?></td>
						<td class="text-center small text-nowrap">
							<?php echo Text::sprintf('COM_MCPASK_AGENT_STATS_LINE', (int) ($stat['offered'] ?? 0), (int) ($stat['started'] ?? 0), (int) ($stat['completed'] ?? 0)); ?>
						</td>
						<td class="text-center"><?php echo HTMLHelper::_('jgrid.published', (int) $agent->published, $i, 'agents.', true); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<div class="d-flex gap-2">
			<button type="submit" class="btn btn-outline-danger btn-sm" onclick="if (!document.adminForm.boxchecked.value || !confirm('<?php echo $e(Text::_('COM_MCPASK_AGENT_DELETE_CONFIRM')); ?>')) return false; document.adminForm.task.value='agents.delete';">
				<span class="icon-trash" aria-hidden="true"></span> <?php echo Text::_('JACTION_DELETE'); ?>
			</button>
		</div>
	<?php endif; ?>

	<input type="hidden" name="task" value="">
	<input type="hidden" name="boxchecked" value="0">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
