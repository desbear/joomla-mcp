<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Gss\Component\Mcpask\Administrator\Ask\Theme;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

/** @var \Gss\Component\Mcpask\Administrator\View\Appearance\HtmlView $this */

$document = Factory::getApplication()->getDocument();
$wa       = $document->getWebAssetManager();
$wa->registerAndUseScript('com_mcpask.appearance', 'com_mcpask/appearance.js', [], ['defer' => true]);

foreach (['WORKING', 'THEME_SAVED', 'THEME_RESET_CONFIRM', 'THEME_ADJUSTED', 'THEME_UNSAVED'] as $key) {
    Text::script('COM_MCPASK_' . $key);
}

$e        = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$colours  = $this->colours();
$examples = array_filter(array_map('trim', explode('|', Text::_('COM_MCPASK_THEME_EXAMPLES'))));

$document->addScriptOptions('com_mcpask.appearance', [
    'url'      => Route::_('index.php?option=com_mcpask&format=json', false),
    'token'    => Session::getFormToken(),
    'colours'  => array_map(static fn ($colour) => ['variable' => $colour['variable'], 'default' => $colour['default']], $colours),
    'saved'    => $this->saved,
    'defaults' => Theme::defaults(),
]);
?>
<style>
  .mcpask-appearance { display: grid; grid-template-columns: minmax(320px, 400px) minmax(0, 1fr); gap: 24px; align-items: start; }
  .mcpask-appearance__preview { position: sticky; top: 16px; }
  .mcpask-appearance__frame { display: block; width: 100%; height: calc(100vh - 180px); min-height: 560px; border: 1px solid var(--template-bg-dark-10, #dee2e6); border-radius: 12px; background: #fff; }
  .mcpask-appearance__colours { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 12px; }
  .mcpask-appearance__colour { display: flex; align-items: center; gap: 8px; min-width: 0; font-size: 13px; }
  .mcpask-appearance__colour input[type="color"] { flex: none; width: 34px; height: 30px; padding: 2px; border: 1px solid #ced4da; border-radius: 6px; background: #fff; cursor: pointer; }
  .mcpask-appearance__colour span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .mcpask-appearance__examples { display: flex; flex-wrap: wrap; gap: 6px; }
  .mcpask-appearance__examples button { font-size: 13px; }
  @media (max-width: 991px) { .mcpask-appearance { grid-template-columns: 1fr; } .mcpask-appearance__preview { position: static; } }
</style>

<div class="mcpask-appearance" id="mcpask-appearance">
  <div>
    <div class="card mb-3">
      <h2 class="card-header h5"><?php echo Text::_('COM_MCPASK_THEME_DESCRIBE'); ?></h2>
      <div class="card-body">
        <?php if (!$this->aiReady) : ?>
          <div class="alert alert-warning"><?php echo Text::_('COM_MCPASK_THEME_NO_AI'); ?></div>
        <?php endif; ?>
        <form id="mcpask-theme-ask">
          <label class="form-label visually-hidden" for="mcpask-theme-description"><?php echo Text::_('COM_MCPASK_THEME_DESCRIBE'); ?></label>
          <textarea class="form-control mb-2" id="mcpask-theme-description" rows="3" maxlength="500" placeholder="<?php echo $e(Text::_('COM_MCPASK_THEME_DESCRIBE_HINT')); ?>"></textarea>
          <div class="d-flex align-items-center gap-2 mb-3">
            <button type="submit" class="btn btn-primary"<?php echo $this->aiReady ? '' : ' disabled'; ?>>
              <span class="icon-wand" aria-hidden="true"></span> <?php echo Text::_('COM_MCPASK_THEME_GENERATE'); ?>
            </button>
            <span class="small text-muted" id="mcpask-theme-status" aria-live="polite"></span>
          </div>
        </form>
        <div class="small text-muted mb-1"><?php echo Text::_('COM_MCPASK_THEME_EXAMPLES_LABEL'); ?></div>
        <div class="mcpask-appearance__examples">
          <?php foreach ($examples as $example) : ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-theme-example="<?php echo $e($example); ?>"<?php echo $this->aiReady ? '' : ' disabled'; ?>><?php echo $e($example); ?></button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="card mb-3">
      <h2 class="card-header h5"><?php echo Text::_('COM_MCPASK_THEME_COLOURS'); ?></h2>
      <div class="card-body">
        <div class="mcpask-appearance__colours">
          <?php foreach ($colours as $name => $colour) : ?>
            <label class="mcpask-appearance__colour" title="<?php echo $e($colour['label']); ?>">
              <input type="color" data-colour="<?php echo $e($name); ?>" value="<?php echo $e($this->saved[$name] ?? $colour['default']); ?>">
              <span><?php echo $e($colour['label']); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="card-footer d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-success" id="mcpask-theme-save"><span class="icon-save" aria-hidden="true"></span> <?php echo Text::_('JSAVE'); ?></button>
        <button type="button" class="btn btn-outline-secondary" id="mcpask-theme-revert"><?php echo Text::_('COM_MCPASK_THEME_REVERT'); ?></button>
        <button type="button" class="btn btn-outline-danger ms-auto" id="mcpask-theme-reset"><?php echo Text::_('COM_MCPASK_THEME_RESET'); ?></button>
      </div>
    </div>
    <div id="mcpask-theme-message" aria-live="polite"></div>
  </div>

  <div class="mcpask-appearance__preview">
    <div class="btn-group btn-group-sm mb-2" role="group" aria-label="<?php echo $e(Text::_('COM_MCPASK_THEME_PREVIEW')); ?>">
      <button type="button" class="btn btn-outline-primary active" data-preview-view="chat"><?php echo Text::_('COM_MCPASK_THEME_PREVIEW_CHAT'); ?></button>
      <button type="button" class="btn btn-outline-primary" data-preview-view="launcher"><?php echo Text::_('COM_MCPASK_THEME_PREVIEW_LAUNCHER'); ?></button>
    </div>
    <iframe class="mcpask-appearance__frame" id="mcpask-theme-preview" title="<?php echo $e(Text::_('COM_MCPASK_THEME_PREVIEW')); ?>"
      src="<?php echo $e(Uri::root(true) . '/media/com_mcpask/preview/index.html'); ?>"></iframe>
  </div>
</div>
