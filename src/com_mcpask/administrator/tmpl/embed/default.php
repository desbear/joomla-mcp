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
use Joomla\CMS\Uri\Uri;

/** @var \Gss\Component\Mcpask\Administrator\View\Embed\HtmlView $this */

$wa = Factory::getApplication()->getDocument()->getWebAssetManager();
$wa->useScript('showon')->useScript('form.validate');

// Copying the line of script, and showing the secret only when asked
$wa->addInlineScript(<<<'JS'
    document.addEventListener('click', (event) => {
      const button = event.target.closest('[data-mcpask-copy], [data-mcpask-reveal]');

      if (!button) {
        return;
      }

      const field = document.getElementById(button.dataset.mcpaskCopy || button.dataset.mcpaskReveal);

      if (button.dataset.mcpaskReveal) {
        field.type = field.type === 'password' ? 'text' : 'password';

        return;
      }

      navigator.clipboard.writeText(field.value).then(() => {
        const label = button.textContent;

        button.textContent = button.dataset.done;
        window.setTimeout(() => { button.textContent = label; }, 1500);
      });
    });
    JS);

$e     = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$embed = $this->embed;
$id    = (int) ($embed->id ?? 0);
$line  = $embed ? '<script src="' . rtrim(Uri::root(), '/') . '/media/mod_mcp_ask/js/embed.js" data-key="' . $embed->public_key . '" async></script>' : '';
?>
<form action="<?php echo Route::_('index.php?option=com_mcpask'); ?>" method="post" name="adminForm" id="adminForm" class="form-validate">
	<div class="row">
		<div class="col-lg-7">
			<?php echo $this->form->renderFieldset('main'); ?>

			<?php echo HTMLHelper::_('uitab.startTabSet', 'embedTab', ['active' => 'look', 'recall' => true]); ?>
			<?php foreach (['look', 'use', 'who'] as $set) : ?>
				<?php $fieldset = $this->form->getFieldsets('params')[$set] ?? null; ?>
				<?php echo HTMLHelper::_('uitab.addTab', 'embedTab', $set, Text::_($fieldset->label ?? '')); ?>
					<?php if ($fieldset && !empty($fieldset->description)) : ?>
						<p class="text-muted"><?php echo Text::_($fieldset->description); ?></p>
					<?php endif; ?>
					<?php echo $this->form->renderFieldset($set); ?>
				<?php echo HTMLHelper::_('uitab.endTab'); ?>
			<?php endforeach; ?>
			<?php echo HTMLHelper::_('uitab.endTabSet'); ?>
		</div>

		<div class="col-lg-5">
			<div class="card">
				<div class="card-body">
					<h3 class="h5"><?php echo Text::_('COM_MCPASK_EMBED_CODE'); ?></h3>
					<?php if (!$embed) : ?>
						<p class="text-muted mb-0"><?php echo Text::_('COM_MCPASK_EMBED_CODE_AFTER_SAVE'); ?></p>
					<?php else : ?>
						<p class="small text-muted"><?php echo Text::_('COM_MCPASK_EMBED_CODE_DESC'); ?></p>
						<textarea id="mcpask-embed-line" class="form-control font-monospace small" rows="4" readonly><?php echo $e($line); ?></textarea>
						<button type="button" class="btn btn-secondary btn-sm mt-2" data-mcpask-copy="mcpask-embed-line" data-done="<?php echo $e(Text::_('COM_MCPASK_EMBED_COPIED')); ?>"><?php echo Text::_('COM_MCPASK_EMBED_COPY'); ?></button>

						<h3 class="h6 mt-4"><?php echo Text::_('COM_MCPASK_EMBED_KEY'); ?></h3>
						<code><?php echo $e($embed->public_key); ?></code>

						<h3 class="h6 mt-4"><?php echo Text::_('COM_MCPASK_EMBED_SECRET'); ?></h3>
						<p class="small text-muted"><?php echo Text::_('COM_MCPASK_EMBED_SECRET_DESC'); ?></p>
						<div class="input-group">
							<input id="mcpask-embed-secret" type="password" class="form-control font-monospace" value="<?php echo $e($embed->secret); ?>" readonly autocomplete="off">
							<button type="button" class="btn btn-outline-secondary" data-mcpask-reveal="mcpask-embed-secret"><?php echo Text::_('COM_MCPASK_EMBED_SHOW'); ?></button>
							<button type="button" class="btn btn-outline-secondary" data-mcpask-copy="mcpask-embed-secret" data-done="<?php echo $e(Text::_('COM_MCPASK_EMBED_COPIED')); ?>"><?php echo Text::_('COM_MCPASK_EMBED_COPY'); ?></button>
						</div>
						<button type="submit" class="btn btn-outline-danger btn-sm mt-2" onclick="if (!confirm('<?php echo $e(Text::_('COM_MCPASK_EMBED_SECRET_CONFIRM')); ?>')) return false; this.form.task.value='embeds.secret'; this.form.noValidate = true;"><?php echo Text::_('COM_MCPASK_EMBED_SECRET_RENEW'); ?></button>

						<h3 class="h6 mt-4"><?php echo Text::_('COM_MCPASK_EMBED_API'); ?></h3>
						<pre class="small bg-light p-2 mb-0"><code>McpAsk.setContext({ product: 'Vital CRM', section: '…' });
McpAsk.setUser(token);   // <?php echo $e(Text::_('COM_MCPASK_EMBED_API_USER')); ?>

McpAsk.open();
McpAsk.close();</code></pre>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>


	<input type="hidden" name="task" value="">
	<input type="hidden" name="id" value="<?php echo $id; ?>">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
