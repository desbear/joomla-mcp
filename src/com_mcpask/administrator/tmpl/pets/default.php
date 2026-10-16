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
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Gss\Component\Mcpask\Administrator\View\Pets\HtmlView $this */

$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// One pet: its thumbnail (or its idle picture), name and moments; what can be done with it below
$card = function (string $choice, string $title, string $link, string $footer) use ($e): string {
    $shown   = $this->shown[$choice] ?? [];
    $picture = ($shown['thumb'] ?? '') ?: ($shown['actions']['idle']['src'] ?? '');
    $count   = count($shown['actions'] ?? []);

    return '<div class="card h-100">'
        . '<div class="card-body text-center">'
        . ($picture !== '' ? '<img src="' . $e($picture) . '" alt="" width="120" height="120" style="object-fit:contain;aspect-ratio:1">' : '<div class="text-danger small py-5">' . Text::_('COM_MCPASK_PET_NEEDS_IDLE') . '</div>')
        . '<h3 class="h6 mt-2 mb-1">' . ($link !== '' ? '<a href="' . $e($link) . '">' . $e($title) . '</a>' : $e($title)) . '</h3>'
        . '<div class="small text-muted">' . Text::sprintf('COM_MCPASK_PET_ACTIONS_COUNT', $count, count(Pets::ACTIONS)) . '</div>'
        . '</div>'
        . '<div class="card-footer bg-transparent d-flex flex-wrap gap-2 justify-content-center align-items-center">' . $footer . '</div>'
        . '</div>';
};
?>
<form action="<?php echo Route::_('index.php?option=com_mcpask&view=pets'); ?>" method="post" name="adminForm" id="adminForm">
	<p class="text-muted"><?php echo Text::_('COM_MCPASK_PETS_DESC'); ?></p>

	<div class="row row-cols-2 row-cols-md-4 row-cols-xl-6 g-3 mb-4">
		<div class="col">
			<?php echo $card('default', Text::_('COM_MCPASK_PET_BUILTIN'), '', '<button type="submit" class="btn btn-outline-secondary btn-sm" onclick="this.form.task.value=\'pets.copy\';"><span class="icon-copy" aria-hidden="true"></span> ' . Text::_('COM_MCPASK_PET_COPY_BUILTIN') . '</button>'); ?>
		</div>
		<?php foreach ($this->pets as $i => $pet) : ?>
			<div class="col">
				<?php echo $card(
					(string) $pet->id,
					$pet->title,
					Route::_('index.php?option=com_mcpask&view=pet&id=' . (int) $pet->id, false),
					'<a class="btn btn-outline-primary btn-sm" href="' . $e(Route::_('index.php?option=com_mcpask&view=pet&id=' . (int) $pet->id, false)) . '"><span class="icon-edit" aria-hidden="true"></span> ' . Text::_('JTOOLBAR_EDIT') . '</a>'
					. '<label class="small d-inline-flex align-items-center gap-1 mb-0">' . HTMLHelper::_('grid.id', $i, (int) $pet->id) . ' ' . Text::_('JSELECT') . '</label>'
				); ?>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ($this->pets) : ?>
		<div class="border-top pt-3">
			<button type="submit" class="btn btn-outline-danger btn-sm" onclick="if (!document.adminForm.boxchecked.value || document.adminForm.boxchecked.value === '0') { alert('<?php echo $e(Text::_('JLIB_HTML_PLEASE_MAKE_A_SELECTION_FROM_THE_LIST')); ?>'); return false; } if (!confirm('<?php echo $e(Text::_('COM_MCPASK_PET_DELETE_CONFIRM')); ?>')) return false; this.form.task.value='pets.delete';">
				<span class="icon-trash" aria-hidden="true"></span> <?php echo Text::_('COM_MCPASK_PET_DELETE_CHOSEN'); ?>
			</button>
		</div>
	<?php endif; ?>

	<input type="hidden" name="task" value="">
	<input type="hidden" name="boxchecked" value="0">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
