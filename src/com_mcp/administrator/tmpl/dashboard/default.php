<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Gss\Component\Mcp\Administrator\View\Dashboard\HtmlView $this */

$wa = \Joomla\CMS\Factory::getApplication()->getDocument()->getWebAssetManager();
$wa->registerAndUseScript('com_mcp.dashboard', 'com_mcp/dashboard.js', [], ['defer' => true]);

Text::script('COM_MCP_COPIED');
Text::script('COM_MCP_CHECK_AUTH_OK');
Text::script('COM_MCP_CHECK_AUTH_FAIL');
Text::script('COM_MCP_CHECK_AUTH_UNREACHABLE');
Text::script('COM_MCP_CHECK_AUTH_XTOKEN');
Text::script('COM_MCP_CHECK_AUTH_OAUTH_BROKEN');
Text::script('COM_MCP_CHECK_OAUTH_OK');
Text::script('COM_MCP_CHECK_OAUTH_FAIL');
Text::script('COM_MCP_CHECK_REDIRECTED');
Text::script('COM_MCP_CHECK_NOT_JSON');

$icons = ['ok' => 'icon-check text-success', 'warning' => 'icon-warning text-warning', 'error' => 'icon-times text-danger'];
$e     = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<div id="mcp-dashboard" data-endpoint="<?php echo $e($this->endpoint); ?>" data-health="<?php echo $e($this->healthUrl); ?>" data-oauth="<?php echo $this->oauthEnabled ? $e($this->oauthMetadataUrl) : ''; ?>">

	<div class="row">
		<div class="col-lg-7">
			<div class="card mb-3">
				<h2 class="card-header"><?php echo Text::_('COM_MCP_ENDPOINT'); ?></h2>
				<div class="card-body">
					<p><?php echo Text::_('COM_MCP_ENDPOINT_DESC'); ?></p>
					<div class="input-group">
						<input type="text" class="form-control font-monospace" id="mcp-endpoint" readonly value="<?php echo $e($this->endpoint); ?>">
						<button type="button" class="btn btn-primary" data-mcp-copy="#mcp-endpoint">
							<span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_MCP_COPY'); ?>
						</button>
					</div>
				</div>
			</div>

			<div class="card mb-3">
				<h2 class="card-header"><?php echo Text::_('COM_MCP_CONNECT'); ?></h2>
				<div class="card-body">
					<?php if ($this->oauthEnabled) : ?>
						<p><?php echo Text::_('COM_MCP_CONNECT_OAUTH'); ?></p>
					<?php endif; ?>
					<details class="mb-3"<?php echo $this->oauthEnabled ? '' : ' open'; ?>>
					<summary class="mb-2"><?php echo Text::_('COM_MCP_CONNECT_TOKEN_SUMMARY'); ?></summary>
					<ol>
						<li>
							<?php echo Text::sprintf(
								'COM_MCP_CONNECT_STEP_TOKEN',
								Route::_('index.php?option=com_users&task=user.edit&id=' . $this->userId)
							); ?>
						</li>
						<li><?php echo Text::_('COM_MCP_CONNECT_STEP_PASTE'); ?></li>
					</ol>
					<div class="mb-3">
						<label class="form-label" for="mcp-token"><?php echo Text::_('COM_MCP_TOKEN_LABEL'); ?></label>
						<input type="password" class="form-control font-monospace" id="mcp-token" autocomplete="off" placeholder="YOUR_API_TOKEN">
						<div class="form-text"><?php echo Text::_('COM_MCP_TOKEN_HINT'); ?></div>
					</div>
					</details>

					<?php echo HTMLHelper::_('uitab.startTabSet', 'mcpClients', ['active' => $this->oauthEnabled ? 'claude-ai' : 'claude-code', 'recall' => true]); ?>
					<?php foreach ($this->clients() as $key => $client) : ?>
						<?php echo HTMLHelper::_('uitab.addTab', 'mcpClients', $key, $e($client['title'])); ?>
						<p class="text-muted small mb-1"><?php echo $e($client['file']); ?></p>
						<div class="position-relative">
							<pre class="bg-light border rounded p-3 pe-5 mb-2" style="white-space: pre-wrap; word-break: break-all;"><code id="mcp-snippet-<?php echo $key; ?>" data-mcp-template="<?php echo $e($client['code']); ?>"><?php echo $e($this->snippet($client['code'])); ?></code></pre>
							<button type="button" class="btn btn-sm btn-secondary position-absolute top-0 end-0 m-2" data-mcp-copy="#mcp-snippet-<?php echo $key; ?>">
								<span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_MCP_COPY'); ?>
							</button>
						</div>
						<p class="small"><?php echo Text::_('COM_MCP_CLIENT_' . strtoupper(str_replace('-', '_', $key)) . '_HELP'); ?></p>
						<?php echo HTMLHelper::_('uitab.endTab'); ?>
					<?php endforeach; ?>
					<?php echo HTMLHelper::_('uitab.endTabSet'); ?>

				</div>
			</div>
		</div>

		<div class="col-lg-5">
			<div class="card mb-3">
				<h2 class="card-header"><?php echo Text::_('COM_MCP_HEALTH'); ?></h2>
				<ul class="list-group list-group-flush">
					<li class="list-group-item" id="mcp-auth-check">
						<span class="icon-spinner icon-spin" aria-hidden="true"></span>
						<strong><?php echo Text::_('COM_MCP_CHECK_AUTH'); ?></strong>
						<div class="small text-muted" data-mcp-detail><?php echo Text::_('COM_MCP_CHECK_AUTH_RUNNING'); ?></div>
						<div class="small mt-1 d-none" data-mcp-fix><?php echo Text::_('COM_MCP_CHECK_AUTH_FIX'); ?></div>
					</li>
					<?php if ($this->oauthEnabled) : ?>
					<li class="list-group-item" id="mcp-oauth-check">
						<span class="icon-spinner icon-spin" aria-hidden="true"></span>
						<strong><?php echo Text::_('COM_MCP_CHECK_OAUTH'); ?></strong>
						<div class="small text-muted" data-mcp-detail><?php echo Text::_('COM_MCP_CHECK_AUTH_RUNNING'); ?></div>
					</li>
					<?php endif; ?>
					<?php foreach ($this->checks as $check) : ?>
						<li class="list-group-item">
							<span class="<?php echo $icons[$check['status']]; ?>" aria-hidden="true"></span>
							<strong><?php echo $e($check['label']); ?></strong>
							<span class="text-muted">— <?php echo $e($check['detail']); ?></span>
							<?php if ($check['fix']) : ?>
								<?php // Not escaped on purpose: 'fix' is language markup built in HealthCheck (links, <code>); any dynamic part must be escaped there, via HealthCheck::e() ?>
								<div class="small mt-1"><?php echo $check['fix']; ?></div>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<div class="card mb-3">
				<h2 class="card-header"><?php echo Text::_('COM_MCP_RECENT'); ?></h2>
				<?php if (!$this->recent) : ?>
					<div class="card-body text-muted"><?php echo Text::_('COM_MCP_RECENT_EMPTY'); ?></div>
				<?php else : ?>
					<table class="table table-sm mb-0">
						<tbody>
						<?php foreach ($this->recent as $row) : ?>
							<tr>
								<td class="small text-nowrap"><?php echo HTMLHelper::_('date', $row->created, Text::_('DATE_FORMAT_LC5')); ?></td>
								<td><code><?php echo $e($row->tool); ?></code></td>
								<td class="small"><?php echo $e($row->user_name); ?></td>
								<td><span class="badge bg-<?php echo $row->status === 'success' ? 'success' : 'danger'; ?>"><?php echo $e($row->status); ?></span></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<div class="card-footer text-end">
						<a href="<?php echo Route::_('index.php?option=com_mcp&view=logs'); ?>"><?php echo Text::_('COM_MCP_RECENT_ALL'); ?></a>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="card mb-3">
		<h2 class="card-header"><?php echo Text::sprintf('COM_MCP_TOOLS', \count($this->tools)); ?></h2>
		<div class="card-body pb-0"><p><?php echo Text::_('COM_MCP_TOOLS_DESC'); ?></p></div>
		<table class="table table-striped mb-0">
			<thead>
				<tr>
					<th scope="col"><?php echo Text::_('COM_MCP_TOOL_NAME'); ?></th>
					<th scope="col"><?php echo Text::_('COM_MCP_TOOL_DESCRIPTION'); ?></th>
					<th scope="col"><?php echo Text::_('COM_MCP_TOOL_SCOPE'); ?></th>
					<th scope="col" class="d-none d-md-table-cell"><?php echo Text::_('COM_MCP_TOOL_GROUP'); ?></th>
					<th scope="col"><?php echo Text::_('JSTATUS'); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($this->tools as $tool) : ?>
				<tr>
					<td><code><?php echo $e($tool->name); ?></code><div class="small text-muted"><?php echo $e($tool->title); ?></div></td>
					<td class="small"><?php echo $e($tool->description); ?></td>
					<td><span class="badge bg-<?php echo $tool->isReadOnly() ? 'info' : ($tool->scope === 'delete' ? 'danger' : 'warning text-dark'); ?>"><?php echo Text::_('COM_MCP_SCOPE_' . strtoupper($tool->scope)); ?></span></td>
					<td class="d-none d-md-table-cell small"><?php echo $e($tool->group); ?></td>
					<td>
						<?php if ($this->toolEnabled($tool)) : ?>
							<span class="icon-check text-success" aria-hidden="true"></span> <?php echo Text::_('JENABLED'); ?>
						<?php else : ?>
							<span class="icon-times text-muted" aria-hidden="true"></span> <?php echo Text::_('JDISABLED'); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<p class="text-muted small text-end">MCP Server for Joomla <?php echo $e($this->version); ?></p>
</div>
