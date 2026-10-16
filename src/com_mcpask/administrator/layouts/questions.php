<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Language\Text;

/**
 * The latest questions. Used by the dashboard and by the module "MCP - Questions Asked".
 *
 * @var array  $displayData  rows: what UsageLog::recent() returned
 */

$rows = $displayData['rows'];
// Who may change the options may shut an address out, with one click
$user = Factory::getApplication()->getIdentity();
$ban  = $user->authorise('core.admin', 'com_mcpask') || $user->authorise('core.options', 'com_mcpask');
$e    = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<?php if (!$rows) : ?>
	<p class="mb-0"><?php echo Text::_('COM_MCPASK_QUESTIONS_NONE'); ?></p>
<?php else : ?>
	<div class="table-responsive">
		<table class="table table-sm mb-0">
			<thead>
				<tr>
					<th scope="col"><?php echo Text::_('JDATE'); ?></th>
					<th scope="col"><?php echo Text::_('COM_MCPASK_ASKED_BY'); ?></th>
					<th scope="col"><?php echo Text::_('COM_MCPASK_QUESTION'); ?></th>
					<th scope="col"><?php echo Text::_('JSTATUS'); ?></th>
					<th scope="col" class="text-end"><?php echo Text::_('COM_MCPASK_TOKENS'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($rows as $row) : ?>
					<tr>
						<td class="text-nowrap"><?php echo HTMLHelper::_('date', $row->created, Text::_('DATE_FORMAT_LC6')); ?></td>
						<td>
							<?php if ((int) $row->user_id > 0 && !$ban) : ?>
								<?php // Who asked, and from where, only for those who may see personal data (and ban) ?>
								<?php echo Text::_('COM_MCPASK_ASKED_BY_MEMBER'); ?>
							<?php elseif ((int) $row->user_id > 0) : ?>
								<?php echo $e($row->name ?: Text::sprintf('COM_MCPASK_ASKED_BY_GONE', (int) $row->user_id)); ?>
								<?php if ($row->username) : ?>
									<span class="text-muted">(<?php echo $e($row->username); ?>)</span>
								<?php endif; ?>
							<?php else : ?>
								<?php echo Text::_('COM_MCPASK_ASKED_BY_GUEST'); ?>
							<?php endif; ?>
							<?php if ($row->ip !== '' && $ban) : ?>
								<div class="small text-muted font-monospace"><?php echo $e($row->ip); ?></div>
								<?php if ($ban) : ?>
									<form method="post" action="<?php echo Route::_('index.php?option=com_mcpask&task=index.ban'); ?>">
										<input type="hidden" name="ip" value="<?php echo $e($row->ip); ?>">
										<input type="hidden" name="<?php echo Session::getFormToken(); ?>" value="1">
										<button type="submit" class="btn btn-link btn-sm p-0 text-danger"><?php echo Text::_('COM_MCPASK_BAN'); ?></button>
									</form>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td><?php echo $row->question === null ? '—' : $e($row->question); ?></td>
						<td>
							<?php echo $row->status === 'blocked' ? '<span class="badge bg-danger">' . Text::_('COM_MCPASK_STATUS_BLOCKED') . '</span>' : $e($row->status); ?>
							<?php if ($row->message !== '') : ?>
								<div class="small text-danger"><?php echo $e($row->message); ?></div>
							<?php endif; ?>
							<?php if ((int) ($row->rating ?? 0) !== 0) : ?>
								<div title="<?php echo $e(Text::_((int) $row->rating > 0 ? 'COM_MCPASK_RATED_GOOD' : 'COM_MCPASK_RATED_BAD')); ?>"><?php echo (int) $row->rating > 0 ? '👍' : '👎'; ?>
									<?php if (($row->feedback ?? '') !== '') : ?><span class="small fst-italic">「<?php echo $e($row->feedback); ?>」</span><?php endif; ?>
								</div>
							<?php endif; ?>
							<?php foreach (array_filter(explode(',', (string) ($row->flags ?? ''))) as $flag) : ?>
								<span class="badge <?php echo $flag === 'output_filtered' ? 'bg-warning text-dark' : 'bg-danger'; ?>"><?php echo Text::_('COM_MCPASK_FLAG_' . strtoupper(preg_replace('/[^a-z_]/', '', $flag))); ?></span>
							<?php endforeach; ?>
							<?php if (($row->answer ?? null) !== null && $row->answer !== '') : ?>
								<details class="small mt-1">
									<summary><?php echo Text::_('COM_MCPASK_ANSWER_SHOWN'); ?></summary>
									<div class="border rounded p-2 mt-1" style="white-space: pre-wrap; max-height: 240px; overflow: auto"><?php echo $e($row->answer); ?></div>
									<?php if (($row->answer_raw ?? null) !== null && $row->answer_raw !== '') : ?>
										<div class="fw-bold mt-2"><?php echo Text::_('COM_MCPASK_ANSWER_RAW'); ?></div>
										<div class="border border-warning rounded p-2 mt-1" style="white-space: pre-wrap; max-height: 240px; overflow: auto"><?php echo $e($row->answer_raw); ?></div>
									<?php endif; ?>
								</details>
							<?php endif; ?>
							<?php if ((int) ($row->hint_id ?? 0) > 0) : ?>
								<span class="badge bg-info text-dark"><?php echo Text::_('COM_MCPASK_RULE_APPLIED'); ?></span>
							<?php endif; ?>
						</td>
						<td class="text-end text-nowrap"><?php echo (int) $row->tokens_in; ?> + <?php echo (int) $row->tokens_out; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
