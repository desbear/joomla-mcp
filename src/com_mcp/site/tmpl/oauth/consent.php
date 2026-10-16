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

/**
 * @var array                   $request  Validated authorization request
 * @var array                   $params   Original request parameters
 * @var \Joomla\CMS\User\User   $user
 * @var string                  $siteName
 * @var string                  $logoutUrl
 */

$client = $request['client'];
$title  = Text::sprintf('COM_MCP_OAUTH_TITLE', $client->client_name);

include __DIR__ . '/_head.php';
?>
<h1><?php echo Text::sprintf('COM_MCP_OAUTH_HEADING', $e($client->client_name), $e($siteName)); ?></h1>
<p class="muted"><?php echo Text::_('COM_MCP_OAUTH_INTRO'); ?></p>

<div class="user">
	<span><?php echo Text::sprintf('COM_MCP_OAUTH_SIGNED_IN', '<strong>' . $e($user->name) . '</strong>'); ?></span>
	<a href="<?php echo $e($logoutUrl); ?>"><?php echo Text::_('COM_MCP_OAUTH_NOT_YOU'); ?></a>
</div>

<form method="post" action="<?php echo $e(Route::_('index.php?option=com_mcp&task=oauth.approve', false)); ?>">
	<p><strong><?php echo Text::_('COM_MCP_OAUTH_PERMISSIONS'); ?></strong></p>
	<ul>
		<?php foreach ($request['scopes'] as $scope) : ?>
			<?php $locked = $scope === 'mcp:read'; ?>
			<li>
				<label>
					<input type="checkbox" name="scopes[]" value="<?php echo $e($scope); ?>"<?php echo $locked ? ' checked disabled' : ''; ?>>
					<span><?php echo Text::_('COM_MCP_OAUTH_SCOPE_' . strtoupper(str_replace(':', '_', $scope))); ?></span>
				</label>
			</li>
		<?php endforeach; ?>
	</ul>
	<p class="muted"><?php echo Text::_('COM_MCP_OAUTH_ACL_NOTE'); ?></p>

	<?php // Always shown, and always the whole URI: where the code is sent is the only thing the person can actually check ?>
	<div class="warn"><?php echo Text::sprintf('COM_MCP_OAUTH_REDIRECT', '<code>' . $e($request['redirect_uri']) . '</code>'); ?></div>

	<?php foreach ($params as $name => $value) : ?>
		<input type="hidden" name="<?php echo $e($name); ?>" value="<?php echo $e($value); ?>">
	<?php endforeach; ?>
	<?php echo HTMLHelper::_('form.token'); ?>

	<div class="actions">
		<button type="submit" name="decision" value="deny"><?php echo Text::_('COM_MCP_OAUTH_DENY'); ?></button>
		<button type="submit" name="decision" value="allow" class="primary"><?php echo Text::_('COM_MCP_OAUTH_ALLOW'); ?></button>
	</div>
</form>
<?php include __DIR__ . '/_foot.php';
