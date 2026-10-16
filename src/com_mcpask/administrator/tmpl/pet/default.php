<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Gss\Component\Mcpask\Administrator\Pet\Pets;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\HTML\Helpers\Number;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/** @var \Gss\Component\Mcpask\Administrator\View\Pet\HtmlView $this */

$wa = Factory::getApplication()->getDocument()->getWebAssetManager();
$wa->useScript('form.validate');

$e      = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$pet    = $this->pet;
$id     = (int) ($pet->id ?? 0);
$params = $pet ? $pet->params : null;
$total  = array_sum($this->sizes);
$url    = static fn ($path) => $path !== '' ? rtrim(Uri::root(true), '/') . '/' . $path : '';
$plays  = [];

foreach (($this->shown['actions'] ?? []) as $name => $action) {
    $plays[] = ['name' => Text::_('COM_MCPASK_PET_ACTION_' . strtoupper($name)), 'src' => $action['src'], 'seconds' => $action['seconds'] ?: 3];
}

// Trying it out: each moment in turn, for as long as it lasts (three seconds for those that go on)
$wa->addInlineScript(<<<'JS'
    document.addEventListener('click', (event) => {
      const button = event.target.closest('[data-mcpask-try]');

      if (!button) {
        return;
      }

      const plays = JSON.parse(button.dataset.mcpaskTry);
      const picture = document.getElementById('mcpask-pet-try');
      const label = document.getElementById('mcpask-pet-try-label');
      let index = 0;

      button.disabled = true;

      const next = () => {
        if (index >= plays.length) {
          button.disabled = false;
          label.textContent = '';

          return;
        }

        const play = plays[index++];

        picture.src = play.src + (play.src.includes('?') ? '&' : '?') + 't=' + Date.now();
        label.textContent = play.name;
        window.setTimeout(next, play.seconds * 1000);
      };

      next();
    });
    JS);
?>
<form action="<?php echo Route::_('index.php?option=com_mcpask'); ?>" method="post" name="adminForm" id="adminForm" class="form-validate" enctype="multipart/form-data">
	<p class="text-muted"><?php echo Text::_('COM_MCPASK_PET_DESC'); ?></p>

	<div class="row">
		<div class="col-lg-7">
			<?php echo $this->form->renderFieldset('main'); ?>
			<?php echo $this->form->renderFieldset('look'); ?>
		</div>
		<div class="col-lg-5">
			<div class="card text-center">
				<div class="card-body">
					<div style="height:180px;display:grid;place-items:center;background:repeating-conic-gradient(#f3f4f7 0 25%,#fff 0 50%) 0 0/20px 20px;border-radius:12px">
						<img id="mcpask-pet-try" src="<?php echo $e($this->shown['actions']['idle']['src'] ?? ''); ?>" alt="" width="160" height="160" style="object-fit:contain">
					</div>
					<div id="mcpask-pet-try-label" class="small text-muted mt-2" aria-live="polite"></div>
					<?php if ($plays) : ?>
						<button type="button" class="btn btn-secondary btn-sm mt-2" data-mcpask-try="<?php echo $e(json_encode($plays, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>"><?php echo Text::_('COM_MCPASK_PET_TRY'); ?></button>
					<?php endif; ?>
					<div class="small mt-2 <?php echo $total > 4 * 1048576 ? 'text-danger' : 'text-muted'; ?>"><?php echo Text::sprintf('COM_MCPASK_PET_TOTAL', Number::bytes($total)); ?></div>
				</div>
			</div>
		</div>
	</div>

	<h3 class="h5 mt-4"><?php echo Text::_('COM_MCPASK_PET_PICTURES'); ?></h3>
	<p class="small text-muted"><?php echo Text::_('COM_MCPASK_PET_PICTURES_DESC'); ?></p>

	<?php
	// One picture's cell: what it is now (with its size), a new one, and taking it away
	$cell = function (string $field) use ($params, $e, $url, $id): string {
		$param = Pets::pictures()[$field];
		$path  = $params ? Pets::clean((string) $params->get($param, '')) : '';
		$bytes = $this->sizes[$param] ?? 0;
		$still = in_array($field, ['static', 'thumb'], true);
		$html  = '<div class="d-flex align-items-center gap-2">';
		$html .= $path !== ''
			? '<div class="text-center flex-none"><img src="' . $e($url($path)) . '" alt="" width="64" height="64" style="object-fit:contain;border-radius:12px;background:#f3f4f7"><div class="small ' . ($bytes > Pets::ADVISED_BYTES ? 'text-danger' : 'text-muted') . '">' . Number::bytes($bytes) . '</div></div>'
			: '<div class="text-muted small text-center" style="width:64px">' . Text::_($field === 'idle' || $field === 'static' ? 'COM_MCPASK_PET_NONE_YET' : (str_ends_with($field, 'thumb') ? 'COM_MCPASK_PET_USES_FULL' : 'COM_MCPASK_PET_USES_IDLE')) . '</div>';
		$html .= '<div class="flex-grow-1"><input type="file" class="form-control form-control-sm" name="pictures[' . $field . ']" accept="' . ($still ? '.png,.webp,.gif,.avif,.jpg,.jpeg' : '.png,.apng,.avif,.webp,.gif') . '"' . ($id ? '' : ' disabled') . '>';

		if ($path !== '' && $field !== 'idle') {
			$html .= '<label class="small mt-1"><input type="checkbox" name="remove[' . $field . ']" value="1"> ' . Text::_('COM_MCPASK_PET_REMOVE') . '</label>';
		}

		return $html . '</div></div>';
	};
	?>
	<table class="table align-middle">
		<thead>
			<tr>
				<th><?php echo Text::_('COM_MCPASK_PET_MOMENT'); ?></th>
				<th><?php echo Text::_('COM_MCPASK_PET_FULL'); ?></th>
				<th><?php echo Text::_('COM_MCPASK_PET_THUMB'); ?></th>
				<th><?php echo Text::_('COM_MCPASK_PET_SECONDS'); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ([...array_keys(Pets::ACTIONS), 'static'] as $name) : ?>
				<?php $once = (Pets::ACTIONS[$name] ?? 0) > 0; ?>
				<tr>
					<td style="min-width:12rem">
						<strong><?php echo Text::_('COM_MCPASK_PET_ACTION_' . strtoupper($name)); ?></strong>
						<?php if ($name === 'idle') : ?><span class="badge bg-warning text-dark"><?php echo Text::_('JREQUIRED'); ?></span><?php endif; ?>
						<div class="small text-muted"><?php echo Text::_('COM_MCPASK_PET_ACTION_' . strtoupper($name) . '_DESC'); ?></div>
					</td>
					<td><?php echo $cell($name); ?></td>
					<td><?php echo $cell($name === 'static' ? 'thumb' : $name . '_thumb'); ?></td>
					<td style="width:110px">
						<?php if ($once) : ?>
							<input type="number" class="form-control form-control-sm" name="seconds[<?php echo $name; ?>]" min="0.3" max="10" step="0.1" value="<?php echo $e($params ? $params->get($name . '_seconds', Pets::ACTIONS[$name]) : Pets::ACTIONS[$name]); ?>">
						<?php else : ?>
							<span class="small text-muted"><?php echo Text::_($name === 'static' ? 'COM_MCPASK_PET_STILL' : 'COM_MCPASK_PET_LOOPS'); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if (!$id) : ?>
		<div class="alert alert-info"><?php echo Text::_('COM_MCPASK_PET_SAVE_FIRST'); ?></div>
	<?php endif; ?>


	<input type="hidden" name="task" value="">
	<input type="hidden" name="id" value="<?php echo $id; ?>">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
