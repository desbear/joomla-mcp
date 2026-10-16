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

/** @var \Gss\Component\Mcpask\Administrator\View\Handoffs\HtmlView $this */

$e       = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$counts  = $this->counts;
$badges  = ['requested' => 'bg-secondary', 'failed' => 'bg-danger', 'opened' => 'bg-info', 'message' => 'bg-warning text-dark', 'chatting' => 'bg-success'];
$option  = static fn ($value, $label, $current) => '<option value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"' . ((string) $value === (string) $current ? ' selected' : '') . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
?>
<form action="<?php echo Route::_('index.php?option=com_mcpask&view=handoffs'); ?>" method="get" name="adminForm" id="adminForm">
	<input type="hidden" name="option" value="com_mcpask">
	<input type="hidden" name="view" value="handoffs">

	<p class="text-muted"><?php echo Text::_('COM_MCPASK_HANDOFFS_DESC'); ?></p>

	<div class="row g-3 mb-3">
		<?php foreach ([
			[Text::_('COM_MCPASK_HANDOFFS_TOTAL'), $counts['total']],
			[Text::_('COM_MCPASK_HANDOFF_OUTCOME_CHATTING'), $counts['outcome']['chatting']],
			[Text::_('COM_MCPASK_HANDOFF_OUTCOME_MESSAGE'), $counts['outcome']['message']],
			[Text::_('COM_MCPASK_HANDOFFS_ONLINE'), $counts['availability']['online']],
			[Text::_('COM_MCPASK_HANDOFFS_IN_HOURS'), $counts['hours']],
		] as [$label, $value]) : ?>
			<div class="col-6 col-md">
				<div class="card h-100"><div class="card-body">
					<div class="text-muted small"><?php echo $e($label); ?></div>
					<div class="fs-3 fw-bold"><?php echo (int) $value; ?></div>
				</div></div>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="d-flex flex-wrap gap-2 mb-3">
		<select name="days" class="form-select w-auto" onchange="this.form.submit()" aria-label="<?php echo $e(Text::_('COM_MCPASK_HANDOFFS_DAYS')); ?>">
			<?php foreach ([1, 7, 30, 90, 365] as $days) : ?>
				<?php echo $option($days, Text::plural('COM_MCPASK_HANDOFFS_LAST_N_DAYS', $days), $this->filter['days']); ?>
			<?php endforeach; ?>
		</select>
		<?php if (count($this->agents) > 1) : ?>
			<select name="agent" class="form-select w-auto" onchange="this.form.submit()" aria-label="<?php echo $e(Text::_('COM_MCPASK_AGENT_TITLE')); ?>">
				<?php echo $option('', Text::_('COM_MCPASK_HANDOFFS_ALL_AGENTS'), $this->filter['agent'] ?: ''); ?>
				<?php foreach ($this->agents as $id => $title) : ?>
					<?php echo $option($id, $title, $this->filter['agent']); ?>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>
		<select name="outcome" class="form-select w-auto" onchange="this.form.submit()" aria-label="<?php echo $e(Text::_('COM_MCPASK_HANDOFFS_OUTCOME')); ?>">
			<?php echo $option('', Text::_('COM_MCPASK_HANDOFFS_ALL_OUTCOMES'), $this->filter['outcome']); ?>
			<?php foreach (array_keys($badges) as $outcome) : ?>
				<?php echo $option($outcome, Text::_('COM_MCPASK_HANDOFF_OUTCOME_' . strtoupper($outcome)), $this->filter['outcome']); ?>
			<?php endforeach; ?>
		</select>
		<select name="availability" class="form-select w-auto" onchange="this.form.submit()" aria-label="<?php echo $e(Text::_('COM_MCPASK_HANDOFFS_AVAILABILITY')); ?>">
			<?php echo $option('', Text::_('COM_MCPASK_HANDOFFS_ALL_AVAILABILITY'), $this->filter['availability']); ?>
			<?php echo $option('online', Text::_('COM_MCPASK_HANDOFF_ONLINE'), $this->filter['availability']); ?>
			<?php echo $option('offline', Text::_('COM_MCPASK_HANDOFF_OFFLINE'), $this->filter['availability']); ?>
		</select>
	</div>

	<?php if (!$this->rows) : ?>
		<div class="alert alert-info"><?php echo Text::_('COM_MCPASK_HANDOFFS_NONE'); ?></div>
	<?php else : ?>
		<table class="table align-middle">
			<thead>
				<tr>
					<th><?php echo Text::_('COM_MCPASK_HANDOFFS_WHEN'); ?></th>
					<th><?php echo Text::_('COM_MCPASK_HANDOFFS_QUESTION'); ?></th>
					<th><?php echo Text::_('COM_MCPASK_HANDOFFS_SOURCE'); ?></th>
					<th><?php echo Text::_('COM_MCPASK_HANDOFFS_AVAILABILITY'); ?></th>
					<th><?php echo Text::_('COM_MCPASK_HANDOFFS_OUTCOME'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($this->rows as $row) : ?>
					<tr>
						<td class="text-nowrap">
							<?php echo HTMLHelper::_('date', $row->created, Text::_('DATE_FORMAT_LC5')); ?>
							<?php if (count($this->agents) > 1 && $row->agent) : ?><div class="small text-muted"><?php echo $e($row->agent); ?></div><?php endif; ?>
						</td>
						<td>
							<?php echo $row->question !== '' ? $e($row->question) : '<span class="text-muted">—</span>'; ?>
							<?php if ($row->page !== '') : ?>
								<div class="small text-truncate" style="max-width: 36rem"><a href="<?php echo $e($row->page); ?>" target="_blank" rel="noopener noreferrer"><?php echo $e($row->page); ?></a></div>
							<?php endif; ?>
							<?php if ((int) $row->report) : ?><span class="badge bg-light text-dark"><?php echo Text::_('COM_MCPASK_HANDOFFS_WITH_REPORT'); ?></span><?php endif; ?>
						</td>
						<td><?php echo Text::_('COM_MCPASK_HANDOFF_SOURCE_' . strtoupper($row->source)); ?></td>
						<td>
							<?php echo $row->availability !== '' ? Text::_('COM_MCPASK_HANDOFF_' . strtoupper($row->availability)) : '—'; ?>
							<div class="small text-muted"><?php echo Text::_((int) $row->hours ? 'COM_MCPASK_HANDOFF_IN_HOURS' : 'COM_MCPASK_HANDOFF_OUT_OF_HOURS'); ?></div>
						</td>
						<td><span class="badge <?php echo $badges[$row->outcome] ?? 'bg-secondary'; ?>"><?php echo Text::_('COM_MCPASK_HANDOFF_OUTCOME_' . strtoupper($row->outcome)); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo $this->pagination->getListFooter(); ?>
	<?php endif; ?>
</form>
