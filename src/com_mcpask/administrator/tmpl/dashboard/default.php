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
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/** @var \Gss\Component\Mcpask\Administrator\View\Dashboard\HtmlView $this */

$wa = Factory::getApplication()->getDocument()->getWebAssetManager();
$wa->registerAndUseScript('com_mcpask.dashboard', 'com_mcpask/dashboard.js', [], ['defer' => true]);

foreach (['SYNC_PROGRESS', 'SYNC_DONE', 'SYNC_FAILED', 'CLEAR_CONFIRM', 'TEST_OK', 'TEST_FAILED', 'WORKING', 'PEERS_PROGRESS', 'PEERS_DONE', 'WEBSITES_STATE_DONE', 'WEBSITES_STATE_RUNNING', 'WEBSITES_STATE_PENDING', 'WEBSITES_STATE_ERROR', 'WEBSITES_STATE_NEW', 'WEBSITES_MODE_FEED', 'WEBSITES_MODE_SITEMAP', 'WEBSITES_MODE_CRAWL', 'WEBSITES_PROGRESS_LINE', 'WEBSITES_STORED', 'WEBSITES_LAST', 'WEBSITES_LEFT', 'WEBSITES_SKIPPED', 'WEBSITES_SKIP_NO_TEXT', 'WEBSITES_SKIP_NO_CONNECTION', 'WEBSITES_SKIP_ROBOTS', 'WEBSITES_BUSY', 'WEBSITES_READING', 'WEBSITES_STOPPED', 'WEBSITES_FINISHED'] as $key) {
    Text::script('COM_MCPASK_' . $key);
}

$icons = ['ok' => 'icon-check text-success', 'warning' => 'icon-warning text-warning', 'error' => 'icon-times text-danger'];
$e     = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<div id="mcpask-dashboard"
	data-url="<?php echo $e(Route::_('index.php?option=com_mcpask&format=json', false)); ?>"
	data-token="<?php echo Session::getFormToken(); ?>">

	<div class="row">
		<div class="col-lg-7">
			<div class="card mb-3">
				<h2 class="card-header"><?php echo Text::_('COM_MCPASK_INDEX'); ?></h2>
				<div class="card-body">
					<p><?php echo Text::sprintf('COM_MCPASK_INDEX_DESC_' . strtoupper($this->source), $this->pages); ?>
						<?php if ($this->blogPosts !== null) : ?>
							<?php echo Text::sprintf('COM_MCPASK_INDEX_DESC_EASYBLOG', $this->blogPosts); ?>
						<?php endif; ?>
					</p>
					<dl class="row mb-3">
						<dt class="col-sm-5"><?php echo Text::_('COM_MCPASK_INDEX_PAGES'); ?></dt>
						<dd class="col-sm-7"><?php echo (int) $this->stats['documents']; ?> / <?php echo $this->pages; ?></dd>
						<dt class="col-sm-5"><?php echo Text::_('COM_MCPASK_INDEX_PASSAGES'); ?></dt>
						<dd class="col-sm-7"><?php echo (int) $this->stats['chunks']; ?></dd>
						<dt class="col-sm-5"><?php echo Text::_('COM_MCPASK_INDEX_PENDING'); ?></dt>
						<dd class="col-sm-7"><?php echo (int) $this->stats['pending']; ?></dd>
						<dt class="col-sm-5"><?php echo Text::_('COM_MCPASK_INDEX_CHECKED'); ?></dt>
						<dd class="col-sm-7"><?php echo $this->stats['checked'] ? HTMLHelper::_('date', $this->stats['checked'], Text::_('DATE_FORMAT_LC6')) : '—'; ?></dd>
					</dl>
					<?php if ($this->stats['message'] !== '') : ?>
						<div class="alert alert-warning"><?php echo $e($this->stats['message']); ?></div>
					<?php endif; ?>
					<?php if ($this->canManage) : ?>
						<div class="d-flex flex-wrap gap-2">
							<button type="button" class="btn btn-primary" data-mcpask="sync">
								<span class="icon-refresh" aria-hidden="true"></span> <?php echo Text::_('COM_MCPASK_SYNC'); ?>
							</button>
							<button type="button" class="btn btn-secondary" data-mcpask="test">
								<span class="icon-plug" aria-hidden="true"></span> <?php echo Text::_('COM_MCPASK_TEST'); ?>
							</button>
							<?php if ($this->canClear) : ?>
								<button type="button" class="btn btn-outline-danger ms-auto" data-mcpask="clear">
									<span class="icon-trash" aria-hidden="true"></span> <?php echo Text::_('COM_MCPASK_CLEAR'); ?>
								</button>
							<?php endif; ?>
						</div>
						<div class="progress mt-3" hidden>
							<div class="progress-bar" role="progressbar" style="width: 0%"></div>
						</div>
						<p class="mt-2 mb-0" id="mcpask-message" role="status"></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="card mb-3">
				<h2 class="card-header"><?php echo Text::_('COM_MCPASK_PEERS'); ?></h2>
				<div class="card-body">
					<p<?php echo $this->exports === 'SHORT' ? ' class="alert alert-danger"' : ''; ?>><?php echo Text::_('COM_MCPASK_PEERS_EXPORT_' . $this->exports); ?></p>
					<?php if (!$this->peers) : ?>
						<p class="mb-0"><?php echo Text::_('COM_MCPASK_PEERS_NONE'); ?></p>
					<?php else : ?>
						<table class="table table-sm">
							<thead>
								<tr>
									<th scope="col"><?php echo Text::_('COM_MCPASK_PEERS_SITE'); ?></th>
									<th scope="col" class="text-end"><?php echo Text::_('COM_MCPASK_INDEX_PAGES'); ?></th>
									<th scope="col"><?php echo Text::_('COM_MCPASK_PEERS_SYNCED'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($this->peers as $peer) : ?>
									<tr>
										<td>
											<?php echo $e($peer['name']); ?>
											<div class="small text-muted"><?php echo $e($peer['url']); ?></div>
											<?php if ($peer['error'] !== '') : ?>
												<div class="small text-danger"><?php echo $e($peer['error']); ?></div>
											<?php endif; ?>
										</td>
										<td class="text-end"><?php echo (int) $peer['stored']; ?></td>
										<td class="text-nowrap"><?php echo $peer['synced'] ? HTMLHelper::_('date', $peer['synced'], Text::_('DATE_FORMAT_LC6')) : '—'; ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<?php if ($this->canManage) : ?>
							<button type="button" class="btn btn-primary" data-mcpask="peers">
								<span class="icon-refresh" aria-hidden="true"></span> <?php echo Text::_('COM_MCPASK_PEERS_SYNC'); ?>
							</button>
							<p class="mt-2 mb-0" id="mcpask-peers-message" role="status"></p>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>

			<div class="card mb-3">
				<h2 class="card-header"><?php echo Text::_('COM_MCPASK_WEBSITES'); ?></h2>
				<div class="card-body">
					<?php if (!$this->websites) : ?>
						<p class="mb-0"><?php echo Text::_('COM_MCPASK_WEBSITES_NONE'); ?></p>
					<?php else : ?>
						<?php // Drawn by dashboard.js from the stored progress, and kept up to date while the page is open ?>
						<div id="mcpask-websites" aria-live="polite" data-status="<?php echo $e(json_encode($this->websiteStatus, JSON_UNESCAPED_UNICODE)); ?>"></div>
						<?php if ($this->canManage) : ?>
							<div class="d-flex flex-wrap gap-2 mt-3">
								<button type="button" class="btn btn-primary" data-mcpask-websites="start">
									<span class="icon-refresh" aria-hidden="true"></span> <?php echo Text::_('COM_MCPASK_WEBSITES_SYNC'); ?>
								</button>
								<button type="button" class="btn btn-outline-secondary" data-mcpask-websites="stop" hidden>
									<span class="icon-pause" aria-hidden="true"></span> <?php echo Text::_('COM_MCPASK_WEBSITES_STOP'); ?>
								</button>
							</div>
							<p class="mt-2 mb-0" id="mcpask-websites-message" role="status"></p>
						<?php endif; ?>
					<?php endif; ?>
					<p class="small text-muted mt-2 mb-0"><?php echo Text::_('COM_MCPASK_WEBSITES_NOTE'); ?></p>
				</div>
			</div>

			<div class="card mb-3">
				<h2 class="card-header"><?php echo Text::sprintf('COM_MCPASK_QUESTIONS', $this->today); ?></h2>
				<div class="card-body">
					<nav class="mb-2 d-flex flex-wrap gap-1" aria-label="<?php echo $e(Text::_('COM_MCPASK_FLAGS_FILTER')); ?>">
						<?php foreach (['' => 'COM_MCPASK_FLAGS_ALL', 'injection_suspect' => 'COM_MCPASK_FLAG_INJECTION_SUSPECT', 'html_payload' => 'COM_MCPASK_FLAG_HTML_PAYLOAD', 'output_filtered' => 'COM_MCPASK_FLAG_OUTPUT_FILTERED'] as $value => $label) : ?>
							<a class="btn btn-sm <?php echo $this->flag === $value ? 'btn-primary' : 'btn-outline-secondary'; ?>"
								href="<?php echo $e(Route::_('index.php?option=com_mcpask' . ($value !== '' ? '&flag=' . $value : ''), false)); ?>"<?php echo $this->flag === $value ? ' aria-current="true"' : ''; ?>><?php echo Text::_($label); ?></a>
						<?php endforeach; ?>
					</nav>
					<?php echo LayoutHelper::render('questions', ['rows' => $this->recent], JPATH_ADMINISTRATOR . '/components/com_mcpask/layouts'); ?>
				</div>
			</div>
		</div>

		<div class="col-lg-5">
			<div class="card mb-3">
				<h2 class="card-header"><?php echo Text::_('COM_MCPASK_CHECKS'); ?></h2>
				<ul class="list-group list-group-flush">
					<?php foreach ($this->checks as $check) : ?>
						<li class="list-group-item d-flex gap-2">
							<span class="<?php echo $icons[$check['status']]; ?> mt-1" aria-hidden="true"></span>
							<span><?php echo $e($check['text']); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
	</div>
</div>
