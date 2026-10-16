<?php

/**
 * @package     Guides
 * @subpackage  com_guides
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/** @var \Gss\Component\Guides\Administrator\View\Guide\HtmlView $this */

$document = Factory::getApplication()->getDocument();
$wa       = $document->getWebAssetManager();
$wa->useScript('showon');
$wa->registerAndUseStyle('com_guides.admin', 'com_guides/admin.css');
$wa->registerAndUseScript('com_guides.editor', 'com_guides/editor.js', [], ['defer' => true]);

foreach ([
    'WORKING', 'SAVED', 'SUBMITTED_DONE', 'APPROVED', 'PUBLISHED_AT', 'STEP', 'STEP_TITLE', 'STEP_BODY', 'STEP_IMAGE', 'STEP_IMAGE_HINT',
    'STEP_NOTE', 'STEP_SOURCES', 'STEP_REMOVE', 'STEP_UP', 'STEP_DOWN', 'STEP_SHARED', 'STEP_DETACH', 'STEP_HTML', 'STEP_VISUAL',
    'ADD_AS_STEP', 'ATTACH_TO', 'ATTACH_NONE', 'USE_STEP', 'COPY_STEP', 'NO_RESULTS', 'NO_SECTIONS', 'INTRO_SECTION', 'USED_N', 'USED_GUIDES_N',
    'NO_STEPS', 'TITLE_NEEDED', 'SUGGEST_NONE', 'SUGGEST_CATEGORY_NEW', 'SUGGEST_SCORE', 'SUGGEST_VOTES', 'CHOOSE', 'ADD_TAG',
    'LEAVE_UNSAVED', 'SOURCE_REMOVE', 'APPROVE_CONFIRM', 'PREVIEW_SAVE_FIRST', 'STATE_DRAFT', 'STATE_REVIEW', 'STATE_PUBLISHED', 'STATE_STALE', 'STATE_ARCHIVED',
] as $key) {
    Text::script('COM_GUIDES_' . $key);
}

$e     = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$guide = $this->guide;

$document->addScriptOptions('com_guides.editor', [
    'id'        => (int) $guide->id,
    'state'     => (string) $guide->state,
    'mode'      => $this->mode,
    'canReview' => $this->canReview,
    'steps'     => array_map(static fn ($step) => [
        'id'      => (int) $step->id,
        'title'   => (string) $step->title,
        'body'    => (string) $step->body,
        'image'   => (string) $step->image,
        'note'    => (string) $step->note,
        'used'    => (int) ($step->used ?? 1),
        'sources' => array_map(static fn ($source) => [
            'source_type' => (string) $source->source_type,
            'item_id'     => (int) $source->item_id,
            'section'     => (string) $source->section,
            'title'       => (string) $source->title,
            'hash'        => (string) $source->hash,
            'changed'     => (string) ($source->changed ?? ''),
        ], $step->sources ?? []),
    ], $guide->steps),
    'gaps'      => array_values((array) $guide->gaps),
]);
?>
<div id="guides-editor"
	data-url="<?php echo $e(Route::_('index.php?option=com_guides&format=json', false)); ?>"
	data-edit="<?php echo $e(Route::_('index.php?option=com_guides&view=guide&id=', false)); ?>"
	data-token="<?php echo Session::getFormToken(); ?>">

	<?php if ($guide->state === 'review') : ?>
		<div class="alert alert-warning"><?php echo Text::_('COM_GUIDES_IN_REVIEW'); ?></div>
	<?php elseif ($guide->state === 'stale') : ?>
		<div class="alert alert-danger"><?php echo Text::_('COM_GUIDES_IS_STALE'); ?></div>
	<?php elseif ($guide->state === 'published') : ?>
		<div class="alert alert-success"><?php echo Text::_('COM_GUIDES_IS_PUBLISHED'); ?></div>
	<?php endif; ?>

	<?php if ($guide->state === 'draft' && trim((string) $guide->review_note) !== '') : ?>
		<div class="alert alert-info">
			<strong><?php echo Text::_('COM_GUIDES_RETURNED'); ?></strong>
			<div class="guides-note"><?php echo nl2br($e($guide->review_note), false); ?></div>
		</div>
	<?php endif; ?>

	<?php if ($guide->gaps) : ?>
		<div class="alert alert-warning">
			<strong><?php echo Text::_('COM_GUIDES_GAPS_LIST'); ?></strong>
			<ul class="mb-0">
				<?php foreach ($guide->gaps as $gap) : ?>
					<li><?php echo $e(\is_array($gap) ? implode(' ', $gap) : $gap); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<div id="guides-message" aria-live="polite"></div>

	<div class="row g-3">
		<div class="col-xl-8">
			<div class="card mb-3">
				<div class="card-body" id="guides-meta">
					<?php echo $this->form->renderFieldset('guide'); ?>
				</div>
			</div>

			<div class="card mb-3">
				<h2 class="card-header d-flex justify-content-between align-items-center">
					<span><?php echo Text::_('COM_GUIDES_STEPS'); ?> <span class="badge bg-secondary" id="guides-step-count">0</span></span>
					<button type="button" class="btn btn-sm btn-outline-primary" id="guides-add-step">
						<span class="icon-plus" aria-hidden="true"></span> <?php echo Text::_('COM_GUIDES_ADD_STEP'); ?>
					</button>
				</h2>
				<div class="card-body">
					<p class="text-muted small"><?php echo Text::_('COM_GUIDES_STEPS_DESC'); ?></p>
					<ol class="guides-steps" id="guides-steps"></ol>
				</div>
			</div>
		</div>

		<div class="col-xl-4">
			<div class="guides-side">
				<div class="card mb-3">
					<h2 class="card-header"><?php echo Text::_('COM_GUIDES_FROM_ARTICLES'); ?></h2>
					<div class="card-body">
						<div class="input-group mb-1">
							<input type="search" class="form-control" id="guides-search" autocomplete="off" placeholder="<?php echo $e(Text::_('COM_GUIDES_SEARCH_ARTICLES')); ?>">
							<button type="button" class="btn btn-outline-secondary" id="guides-search-go" aria-label="<?php echo $e(Text::_('JSEARCH_FILTER_SUBMIT')); ?>"><span class="icon-search" aria-hidden="true"></span></button>
						</div>
						<div class="form-check small mb-2">
							<input class="form-check-input" type="checkbox" id="guides-search-text">
							<label class="form-check-label" for="guides-search-text"><?php echo Text::_('COM_GUIDES_SEARCH_IN_TEXT'); ?></label>
						</div>
						<div class="list-group guides-results" id="guides-results"></div>
						<div id="guides-sections" class="mt-2"></div>
					</div>
				</div>

				<div class="card mb-3">
					<h2 class="card-header"><?php echo Text::_('COM_GUIDES_LIBRARY'); ?></h2>
					<div class="card-body">
						<p class="text-muted small"><?php echo Text::_('COM_GUIDES_LIBRARY_DESC'); ?></p>
						<input type="search" class="form-control mb-2" id="guides-library" autocomplete="off" placeholder="<?php echo $e(Text::_('COM_GUIDES_SEARCH_STEPS')); ?>">
						<div class="list-group guides-results" id="guides-library-results"></div>
					</div>
				</div>

				<div class="card mb-3">
					<h2 class="card-header"><?php echo Text::_('COM_GUIDES_PLACEMENT'); ?></h2>
					<div class="card-body" id="guides-placement">
						<p class="text-muted small"><?php echo Text::_($this->mode === 'topic' ? 'COM_GUIDES_PLACEMENT_TOPIC_DESC' : 'COM_GUIDES_PLACEMENT_TYPE_TAGS_DESC'); ?></p>
						<?php echo $this->form->renderFieldset('placement'); ?>
						<button type="button" class="btn btn-outline-primary btn-sm" id="guides-suggest">
							<span class="icon-lightbulb" aria-hidden="true"></span> <?php echo Text::_('COM_GUIDES_SUGGEST'); ?>
						</button>
						<div id="guides-suggestions" class="mt-2"></div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="guides-bar">
		<button type="button" class="btn btn-primary" id="guides-save"><span class="icon-save" aria-hidden="true"></span> <?php echo Text::_('JSAVE'); ?></button>
		<button type="button" class="btn btn-outline-primary" id="guides-submit"><span class="icon-share" aria-hidden="true"></span> <?php echo Text::_('COM_GUIDES_SAVE_SUBMIT'); ?></button>
		<button type="button" class="btn btn-outline-secondary" id="guides-preview-button"><span class="icon-eye" aria-hidden="true"></span> <?php echo Text::_('COM_GUIDES_PREVIEW'); ?></button>
		<?php if ($this->canReview) : ?>
			<button type="button" class="btn btn-success<?php echo \in_array($guide->state, ['review', 'stale'], true) ? '' : ' d-none'; ?>" id="guides-approve"><span class="icon-check" aria-hidden="true"></span> <?php echo Text::_('COM_GUIDES_APPROVE'); ?></button>
		<?php endif; ?>
		<span class="guides-bar__state badge bg-secondary" id="guides-state"><?php echo Text::_('COM_GUIDES_STATE_' . strtoupper($guide->state)); ?></span>
	</div>

	<dialog id="guides-preview" class="guides-dialog">
		<div class="guides-dialog__head">
			<h2 class="h5 m-0"><?php echo Text::_('COM_GUIDES_PREVIEW'); ?></h2>
			<button type="button" class="btn-close" data-guides-close aria-label="<?php echo $e(Text::_('JCLOSE')); ?>"></button>
		</div>
		<div class="guides-dialog__body" id="guides-preview-body"></div>
	</dialog>
</div>
