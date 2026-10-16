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
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/** @var \Gss\Component\Mcpask\Administrator\View\Quality\HtmlView $this */

$wa = Factory::getApplication()->getDocument()->getWebAssetManager();
$wa->registerAndUseScript('com_mcpask.quality', 'com_mcpask/quality.js', [], ['defer' => true]);

foreach (['RULE_SAVED', 'RULE_DELETE_CONFIRM', 'RULE_NEEDS', 'RULE_TRYING', 'RULE_TRY_HINTS', 'RULE_TRY_NO_HINT', 'RULE_NO_PAGES', 'RULE_ADD', 'RULE_REMOVE', 'RULE_SEARCH_NONE', 'WORKING'] as $key) {
    Text::script('COM_MCPASK_' . $key);
}

$e     = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$total = $this->ratings['good'] + $this->ratings['bad'];
?>
<div id="mcpask-quality"
	data-url="<?php echo $e(Route::_('index.php?option=com_mcpask&format=json', false)); ?>"
	data-token="<?php echo Session::getFormToken(); ?>">

	<div class="row g-3 mb-3">
		<?php foreach ([['good', 'thumbs-up', 'text-success'], ['bad', 'thumbs-down', 'text-danger'], ['answered', 'comments', 'text-primary']] as [$key, $icon, $class]) : ?>
			<div class="col-sm-4">
				<div class="card h-100">
					<div class="card-body d-flex align-items-center gap-3">
						<span class="icon-<?php echo $icon; ?> fs-2 <?php echo $class; ?>" aria-hidden="true"></span>
						<div>
							<div class="fs-3 fw-bold"><?php echo (int) $this->ratings[$key]; ?></div>
							<div class="text-muted small"><?php echo Text::sprintf('COM_MCPASK_QUALITY_' . strtoupper($key), $this::DAYS); ?></div>
						</div>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php if ($total) : ?>
		<p class="text-muted"><?php echo Text::sprintf('COM_MCPASK_QUALITY_SHARE', round($this->ratings['good'] / $total * 100), $total); ?></p>
	<?php endif; ?>

	<div class="row g-3">
		<div class="col-xl-6">
			<div class="card mb-3">
				<h2 class="card-header"><?php echo Text::sprintf('COM_MCPASK_QUALITY_PENDING', $this::DAYS); ?></h2>
				<div class="card-body">
					<p class="text-muted small"><?php echo Text::_('COM_MCPASK_QUALITY_PENDING_DESC'); ?></p>
					<?php if (!$this->pending) : ?>
						<p class="mb-0"><?php echo Text::_('COM_MCPASK_QUALITY_PENDING_NONE'); ?></p>
					<?php else : ?>
						<ul class="list-group list-group-flush">
							<?php foreach ($this->pending as $item) : ?>
								<li class="list-group-item px-0">
									<div class="d-flex justify-content-between align-items-start gap-2">
										<div>
											<div class="fw-bold"><?php echo $e($item->question); ?></div>
											<div class="small text-muted">
												<?php echo Text::sprintf('COM_MCPASK_QUALITY_ASKED', (int) $item->asked); ?>
												<?php if ($item->bad) : ?> · <span class="text-danger">👎 <?php echo (int) $item->bad; ?></span><?php endif; ?>
												<?php if ($item->empty) : ?> · <?php echo Text::sprintf('COM_MCPASK_QUALITY_EMPTY', (int) $item->empty); ?><?php endif; ?>
												<?php if ($item->errors) : ?> · <?php echo Text::sprintf('COM_MCPASK_QUALITY_ERRORS', (int) $item->errors); ?><?php endif; ?>
												· <?php echo HTMLHelper::_('date', $item->last, Text::_('DATE_FORMAT_LC5')); ?>
											</div>
											<?php foreach ($item->reasons as $reason) : ?>
												<div class="small fst-italic">「<?php echo $e($reason); ?>」</div>
											<?php endforeach; ?>
										</div>
										<?php if ($this->canManage) : ?>
											<button type="button" class="btn btn-sm btn-outline-primary text-nowrap" data-mcpask-rule-for="<?php echo $e($item->question); ?>">
												<?php echo Text::_('COM_MCPASK_RULE_FOR'); ?>
											</button>
										<?php endif; ?>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="col-xl-6">
			<?php if ($this->canManage) : ?>
				<form class="card mb-3" id="mcpask-rule" novalidate>
					<h2 class="card-header"><?php echo Text::_('COM_MCPASK_RULE_EDITOR'); ?></h2>
					<div class="card-body">
						<p class="text-muted small"><?php echo Text::_('COM_MCPASK_RULE_EDITOR_DESC'); ?></p>
						<input type="hidden" name="id" value="0">
						<div class="mb-3">
							<label class="form-label" for="mcpask-rule-question"><?php echo Text::_('COM_MCPASK_RULE_QUESTION'); ?></label>
							<input class="form-control" id="mcpask-rule-question" name="question" maxlength="500" required>
						</div>
						<div class="mb-3">
							<label class="form-label" for="mcpask-rule-variants"><?php echo Text::_('COM_MCPASK_RULE_VARIANTS'); ?></label>
							<textarea class="form-control" id="mcpask-rule-variants" name="variants" rows="2" placeholder="<?php echo $e(Text::_('COM_MCPASK_RULE_VARIANTS_HINT')); ?>"></textarea>
						</div>
						<div class="mb-3">
							<label class="form-label" for="mcpask-rule-search"><?php echo Text::_('COM_MCPASK_RULE_PAGES'); ?></label>
							<ol class="list-group list-group-numbered mb-2" id="mcpask-rule-pages"></ol>
							<input class="form-control" id="mcpask-rule-search" type="search" autocomplete="off" placeholder="<?php echo $e(Text::_('COM_MCPASK_RULE_SEARCH')); ?>">
							<div class="list-group mt-1" id="mcpask-rule-results"></div>
						</div>
						<div class="mb-3">
							<label class="form-label" for="mcpask-rule-note"><?php echo Text::_('COM_MCPASK_RULE_NOTE'); ?></label>
							<textarea class="form-control" id="mcpask-rule-note" name="note" rows="3" maxlength="1000" placeholder="<?php echo $e(Text::_('COM_MCPASK_RULE_NOTE_HINT')); ?>"></textarea>
						</div>
						<div class="row g-2 mb-3">
							<div class="col-sm-6">
								<label class="form-label" for="mcpask-rule-language"><?php echo Text::_('JFIELD_LANGUAGE_LABEL'); ?></label>
								<select class="form-select" id="mcpask-rule-language" name="language">
									<option value="*"><?php echo Text::_('JALL_LANGUAGE'); ?></option>
									<?php foreach ($this->languages as $tag => $title) : ?>
										<option value="<?php echo $e($tag); ?>"><?php echo $e($title); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-sm-6 d-flex align-items-end">
								<div class="form-check form-switch mb-2">
									<input class="form-check-input" type="checkbox" role="switch" id="mcpask-rule-state" name="state" checked>
									<label class="form-check-label" for="mcpask-rule-state"><?php echo Text::_('JENABLED'); ?></label>
								</div>
							</div>
						</div>
						<div class="d-flex flex-wrap gap-2">
							<button type="submit" class="btn btn-primary"><span class="icon-save" aria-hidden="true"></span> <?php echo Text::_('COM_MCPASK_RULE_SAVE'); ?></button>
							<button type="button" class="btn btn-outline-secondary" data-mcpask-rule="try"><span class="icon-comments" aria-hidden="true"></span> <?php echo Text::_('COM_MCPASK_RULE_TRY'); ?></button>
							<button type="button" class="btn btn-link" data-mcpask-rule="reset"><?php echo Text::_('JCLEAR'); ?></button>
						</div>
						<p class="mt-2 mb-0" id="mcpask-rule-message" role="status"></p>
						<div class="border rounded p-3 mt-3" id="mcpask-rule-answer" hidden>
							<div class="small text-muted mb-2" id="mcpask-rule-answer-meta"></div>
							<div id="mcpask-rule-answer-text" style="white-space: pre-wrap"></div>
							<ol class="small mt-2 mb-0" id="mcpask-rule-answer-sources"></ol>
						</div>
					</div>
				</form>
			<?php endif; ?>
		</div>
	</div>

	<div class="card mb-3">
		<h2 class="card-header"><?php echo Text::sprintf('COM_MCPASK_RULES', \count($this->hints)); ?></h2>
		<div class="card-body">
			<?php if (!$this->hints) : ?>
				<p class="mb-0"><?php echo Text::_('COM_MCPASK_RULES_NONE'); ?></p>
			<?php else : ?>
				<div class="table-responsive">
					<table class="table">
						<thead>
							<tr>
								<th scope="col"><?php echo Text::_('COM_MCPASK_RULE_QUESTION'); ?></th>
								<th scope="col"><?php echo Text::_('COM_MCPASK_RULE_PAGES'); ?></th>
								<th scope="col" class="text-end"><?php echo Text::_('COM_MCPASK_RULE_HITS'); ?></th>
								<th scope="col"></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($this->hints as $rule) : ?>
								<tr<?php echo $rule->state ? '' : ' class="text-muted"'; ?>>
									<td>
										<div class="fw-bold"><?php echo $e($rule->question); ?></div>
										<?php foreach (array_filter(preg_split('/\R/u', (string) $rule->variants) ?: []) as $variant) : ?>
											<div class="small text-muted">＝ <?php echo $e($variant); ?></div>
										<?php endforeach; ?>
										<?php if (!$rule->state) : ?><span class="badge bg-secondary"><?php echo Text::_('JDISABLED'); ?></span><?php endif; ?>
										<?php if ($rule->language !== '*') : ?><span class="badge bg-light text-dark border"><?php echo $e($this->languages[$rule->language] ?? $rule->language); ?></span><?php endif; ?>
										<?php if ($rule->note !== '') : ?><div class="small mt-1"><?php echo $e($rule->note); ?></div><?php endif; ?>
									</td>
									<td>
										<ol class="small mb-0 ps-3">
											<?php foreach ($rule->pages as $page) : ?>
												<li>
													<?php if ($page['url'] !== '') : ?>
														<a href="<?php echo $e($page['url']); ?>" target="_blank" rel="noopener"><?php echo $e($page['title']); ?></a>
													<?php else : ?>
														<?php echo $e($page['source'] . ' #' . $page['item_id']); ?>
													<?php endif; ?>
													<?php if ($page['site'] !== '') : ?><span class="text-muted">（<?php echo $e($page['site']); ?>）</span><?php endif; ?>
													<?php if ($page['missing']) : ?><span class="badge bg-warning text-dark"><?php echo Text::_('COM_MCPASK_RULE_PAGE_MISSING'); ?></span><?php endif; ?>
												</li>
											<?php endforeach; ?>
										</ol>
									</td>
									<td class="text-end text-nowrap">
										<?php echo (int) $rule->hits; ?>
										<?php if ($rule->last_hit) : ?><div class="small text-muted"><?php echo HTMLHelper::_('date', $rule->last_hit, Text::_('DATE_FORMAT_LC5')); ?></div><?php endif; ?>
									</td>
									<td class="text-end text-nowrap">
										<?php if ($this->canManage) : ?>
											<button type="button" class="btn btn-sm btn-outline-primary" data-mcpask-rule-edit="<?php echo $e(json_encode(['id' => (int) $rule->id, 'question' => $rule->question, 'variants' => (string) $rule->variants, 'note' => $rule->note, 'language' => $rule->language, 'state' => (int) $rule->state, 'pages' => $rule->pages], JSON_UNESCAPED_UNICODE)); ?>">
												<span class="icon-edit" aria-hidden="true"></span> <?php echo Text::_('COM_MCPASK_RULE_EDIT'); ?>
											</button>
											<?php if ($this->canDelete) : ?>
											<button type="button" class="btn btn-sm btn-outline-danger" data-mcpask-rule-delete="<?php echo (int) $rule->id; ?>">
												<span class="icon-trash" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('COM_MCPASK_RULE_DELETE'); ?></span>
											</button>
											<?php endif; ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
