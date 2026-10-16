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

/** @var \Gss\Component\Mcpask\Administrator\View\Agent\HtmlView $this */

Factory::getApplication()->getDocument()->getWebAssetManager()->useScript('showon')->useScript('form.validate');

$e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$id = (int) ($this->agent->id ?? 0);
?>
<form action="<?php echo Route::_('index.php?option=com_mcpask'); ?>" method="post" name="adminForm" id="adminForm" class="form-validate">
	<p class="text-muted"><?php echo $e($this->kind->description()); ?></p>

	<div class="row">
		<div class="col-lg-8">
			<?php echo $this->form->renderFieldset('main'); ?>
		</div>
	</div>

	<?php echo HTMLHelper::_('uitab.startTabSet', 'agentTab', ['active' => 'own', 'recall' => true]); ?>

	<?php echo HTMLHelper::_('uitab.addTab', 'agentTab', 'own', $e($this->kind->title())); ?>
		<?php echo $this->form->renderFieldset('own'); ?>
	<?php echo HTMLHelper::_('uitab.endTab'); ?>

	<?php foreach (['talk', 'triggers', 'report', 'scope'] as $set) : ?>
		<?php echo HTMLHelper::_('uitab.addTab', 'agentTab', $set, Text::_('COM_MCPASK_AGENT_' . strtoupper($set))); ?>
			<?php $fieldset = $this->form->getFieldsets('params')[$set] ?? null; ?>
			<?php if ($fieldset && !empty($fieldset->description)) : ?>
				<p class="text-muted"><?php echo Text::_($fieldset->description); ?></p>
			<?php endif; ?>
			<?php echo $this->form->renderFieldset($set); ?>
		<?php echo HTMLHelper::_('uitab.endTab'); ?>
	<?php endforeach; ?>

	<?php echo HTMLHelper::_('uitab.endTabSet'); ?>


	<input type="hidden" name="task" value="">
	<input type="hidden" name="id" value="<?php echo $id; ?>">
	<input type="hidden" name="type" value="<?php echo $e($this->kind->type()); ?>">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
