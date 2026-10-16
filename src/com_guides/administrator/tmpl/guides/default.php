<?php

/**
 * @package     Guides
 * @subpackage  com_guides
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Gss\Component\Guides\Administrator\Guide\Store;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/** @var \Gss\Component\Guides\Administrator\View\Guides\HtmlView $this */

$wa = Factory::getApplication()->getDocument()->getWebAssetManager();
$wa->registerAndUseStyle('com_guides.admin', 'com_guides/admin.css');
$wa->registerAndUseScript('com_guides.guides', 'com_guides/guides.js', [], ['defer' => true]);

foreach (['WORKING', 'REJECT_NOTE_NEEDED', 'APPROVED', 'REJECTED', 'DELETE_CONFIRM', 'APPROVE_CONFIRM', 'ORIGIN_MISSING', 'ORIGIN_CHANGED', 'NO_ORIGIN', 'APPROVE', 'REJECT', 'APPROVE_NOTE_LABEL', 'REJECT_NOTE_LABEL', 'PUBLISHED_AT'] as $key) {
    Text::script('COM_GUIDES_' . $key);
}

$e      = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$edit   = fn ($id) => Route::_('index.php?option=com_guides&view=guide&id=' . (int) $id);
$badges = ['draft' => 'secondary', 'review' => 'warning', 'published' => 'success', 'stale' => 'danger', 'archived' => 'dark'];
?>
<div id="guides-list"
	data-url="<?php echo $e(Route::_('index.php?option=com_guides&format=json', false)); ?>"
	data-token="<?php echo Session::getFormToken(); ?>">

	<?php if ($this->canReview) : ?>
		<div class="card mb-3 guides-pending">
			<h2 class="card-header d-flex align-items-center gap-2">
				<span class="icon-clock" aria-hidden="true"></span>
				<?php echo Text::_('COM_GUIDES_PENDING'); ?>
				<span class="badge bg-<?php echo $this->pending['review'] ? 'warning text-dark' : 'secondary'; ?>"><?php echo \count($this->pending['review']); ?></span>
			</h2>
			<div class="card-body">
				<?php if (!$this->pending['review']) : ?>
					<p class="mb-0 text-muted"><?php echo Text::_('COM_GUIDES_PENDING_NONE'); ?></p>
				<?php else : ?>
					<div class="table-responsive">
						<table class="table align-middle mb-0">
							<thead>
								<tr>
									<th><?php echo Text::_('COM_GUIDES_TITLE'); ?></th>
									<th><?php echo Text::_('COM_GUIDES_PRODUCT_TAGS'); ?></th>
									<th><?php echo Text::_('COM_GUIDES_SUBMITTED'); ?></th>
									<th class="text-center"><?php echo Text::_('COM_GUIDES_WAITING'); ?></th>
									<th class="text-center"><?php echo Text::_('COM_GUIDES_GAPS'); ?></th>
									<th class="text-center"><?php echo Text::_('COM_GUIDES_UNSOURCED'); ?></th>
									<th class="text-end"><?php echo Text::_('COM_GUIDES_ACTIONS'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($this->pending['review'] as $guide) : ?>
									<tr data-guide="<?php echo (int) $guide->id; ?>">
										<td>
											<a href="<?php echo $edit($guide->id); ?>" class="fw-bold"><?php echo $e($guide->title); ?></a>
											<div class="small text-muted"><?php echo Text::sprintf('COM_GUIDES_N_STEPS', (int) $guide->steps); ?></div>
										</td>
										<td class="small">
											<?php echo $e($guide->product); ?>
											<?php foreach ($guide->tags as $tag) : ?>
												<span class="badge bg-light text-dark border"><?php echo $e($tag); ?></span>
											<?php endforeach; ?>
										</td>
										<td class="small">
											<?php echo $e($guide->submitted_name); ?>
											<?php if ($guide->submitted) : ?><div class="text-muted"><?php echo HTMLHelper::_('date', $guide->submitted, Text::_('DATE_FORMAT_LC5')); ?></div><?php endif; ?>
										</td>
										<td class="text-center">
											<span class="badge bg-<?php echo $guide->late ? 'danger' : 'light text-dark border'; ?>"><?php echo Text::plural('COM_GUIDES_N_DAYS', (int) $guide->days); ?></span>
										</td>
										<td class="text-center"><?php echo $guide->gaps ? '<span class="badge bg-warning text-dark" title="' . $e(implode("\n", array_map('strval', $guide->gaps))) . '">' . \count($guide->gaps) . '</span>' : '0'; ?></td>
										<td class="text-center"><?php echo $guide->unsourced ? '<span class="badge bg-warning text-dark">' . (int) $guide->unsourced . '</span>' : '0'; ?></td>
										<td class="text-end text-nowrap">
											<button type="button" class="btn btn-sm btn-outline-secondary" data-guides-preview="<?php echo (int) $guide->id; ?>"><?php echo Text::_('COM_GUIDES_PREVIEW'); ?></button>
											<button type="button" class="btn btn-sm btn-success" data-guides-approve="<?php echo (int) $guide->id; ?>"><?php echo Text::_('COM_GUIDES_APPROVE'); ?></button>
											<button type="button" class="btn btn-sm btn-outline-danger" data-guides-reject="<?php echo (int) $guide->id; ?>"><?php echo Text::_('COM_GUIDES_REJECT'); ?></button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<?php if ($this->pending['stale']) : ?>
					<h3 class="h6 mt-4"><span class="icon-warning text-danger" aria-hidden="true"></span> <?php echo Text::_('COM_GUIDES_STALE'); ?></h3>
					<ul class="list-group list-group-flush">
						<?php foreach ($this->pending['stale'] as $guide) : ?>
							<li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
								<a href="<?php echo $edit($guide->id); ?>"><?php echo $e($guide->title); ?></a>
								<button type="button" class="btn btn-sm btn-outline-secondary" data-guides-preview="<?php echo (int) $guide->id; ?>"><?php echo Text::_('COM_GUIDES_COMPARE'); ?></button>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

	<form method="get" action="<?php echo $e(Route::_('index.php')); ?>" class="d-flex flex-wrap gap-2 mb-3">
		<input type="hidden" name="option" value="com_guides">
		<input type="search" class="form-control w-auto" name="filter_search" value="<?php echo $e($this->filters['search']); ?>" placeholder="<?php echo $e(Text::_('JSEARCH_FILTER')); ?>">
		<select name="filter_state" class="form-select w-auto" onchange="this.form.submit()">
			<option value=""><?php echo Text::_('COM_GUIDES_ALL_STATES'); ?></option>
			<?php foreach (Store::STATES as $state) : ?>
				<option value="<?php echo $state; ?>"<?php echo $this->filters['state'] === $state ? ' selected' : ''; ?>><?php echo Text::_('COM_GUIDES_STATE_' . strtoupper($state)); ?></option>
			<?php endforeach; ?>
		</select>
		<button class="btn btn-primary"><?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?></button>
	</form>

	<?php if (!$this->guides) : ?>
		<div class="alert alert-info">
			<?php echo Text::_($this->filters['state'] || $this->filters['search'] ? 'COM_GUIDES_NONE_FOUND' : 'COM_GUIDES_NONE_YET'); ?>
		</div>
	<?php else : ?>
		<table class="table table-striped align-middle">
			<thead>
				<tr>
					<th><?php echo Text::_('COM_GUIDES_TITLE'); ?></th>
					<th><?php echo Text::_('COM_GUIDES_STATE'); ?></th>
					<th class="text-center"><?php echo Text::_('COM_GUIDES_STEPS'); ?></th>
					<th><?php echo Text::_('COM_GUIDES_PUBLISHED_AS'); ?></th>
					<th><?php echo Text::_('COM_GUIDES_MODIFIED'); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($this->guides as $guide) : ?>
					<tr data-guide="<?php echo (int) $guide->id; ?>">
						<td>
							<a href="<?php echo $edit($guide->id); ?>" class="fw-bold"><?php echo $e($guide->title); ?></a>
							<div class="small text-muted"><?php echo $e($guide->product); ?><?php echo $guide->language !== '*' ? ' · ' . $e($guide->language) : ''; ?></div>
						</td>
						<td><span class="badge bg-<?php echo $badges[$guide->state] ?? 'secondary'; ?>"><?php echo Text::_('COM_GUIDES_STATE_' . strtoupper($guide->state)); ?></span></td>
						<td class="text-center"><?php echo (int) $guide->steps; ?></td>
						<td class="small">
							<?php if ((int) $guide->target_id) : ?>
								<?php echo Text::_($guide->target_type === 'flexicontent' ? 'COM_GUIDES_TARGET_FLEXICONTENT' : 'COM_GUIDES_TARGET_CONTENT'); ?> #<?php echo (int) $guide->target_id; ?>
								<div class="text-muted"><?php echo $e($guide->category); ?></div>
							<?php else : ?>
								<span class="text-muted">—</span>
							<?php endif; ?>
						</td>
						<td class="small"><?php echo HTMLHelper::_('date', $guide->modified, Text::_('DATE_FORMAT_LC5')); ?></td>
						<td class="text-end text-nowrap">
							<button type="button" class="btn btn-sm btn-outline-secondary" data-guides-preview="<?php echo (int) $guide->id; ?>"><?php echo Text::_('COM_GUIDES_PREVIEW'); ?></button>
							<?php if ($this->canDelete) : ?>
								<button type="button" class="btn btn-sm btn-outline-danger" data-guides-delete="<?php echo (int) $guide->id; ?>" aria-label="<?php echo $e(Text::_('JACTION_DELETE')); ?>"><span class="icon-trash" aria-hidden="true"></span></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<dialog id="guides-preview" class="guides-dialog">
		<div class="guides-dialog__head">
			<h2 class="h5 m-0" id="guides-preview-title"></h2>
			<button type="button" class="btn-close" data-guides-close aria-label="<?php echo $e(Text::_('JCLOSE')); ?>"></button>
		</div>
		<div class="guides-dialog__body">
			<div class="guides-preview">
				<article class="guides-preview__article" id="guides-preview-article"></article>
				<aside class="guides-preview__origins" id="guides-preview-origins"></aside>
			</div>
		</div>
	</dialog>

	<dialog id="guides-decide" class="guides-dialog guides-dialog--small">
		<form method="dialog" id="guides-decide-form">
			<div class="guides-dialog__head">
				<h2 class="h5 m-0" id="guides-decide-title"></h2>
				<button type="button" class="btn-close" data-guides-close aria-label="<?php echo $e(Text::_('JCLOSE')); ?>"></button>
			</div>
			<div class="guides-dialog__body">
				<label class="form-label" for="guides-decide-note" id="guides-decide-label"></label>
				<textarea class="form-control" id="guides-decide-note" rows="4" maxlength="2000"></textarea>
				<div class="alert alert-danger mt-2 d-none" id="guides-decide-error"></div>
			</div>
			<div class="guides-dialog__foot">
				<button type="button" class="btn btn-secondary" data-guides-close><?php echo Text::_('JCANCEL'); ?></button>
				<button type="submit" class="btn btn-primary" id="guides-decide-go" value="go"></button>
			</div>
		</form>
	</dialog>
</div>
