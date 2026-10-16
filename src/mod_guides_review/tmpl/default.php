<?php

/**
 * @package     Guides
 * @subpackage  mod_guides_review
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var array $review */
/** @var array $stale */
/** @var array $counts */

$e    = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$list = Route::_('index.php?option=com_guides');
?>
<?php if (!$counts['review'] && !$counts['stale']) : ?>
	<p class="mb-0 text-muted small"><?php echo Text::_('MOD_GUIDES_REVIEW_NONE'); ?></p>
<?php else : ?>
	<div class="d-flex gap-3 mb-2">
		<a href="<?php echo $list; ?>" class="text-decoration-none">
			<span class="badge bg-<?php echo $counts['review'] ? 'warning text-dark' : 'secondary'; ?> fs-6"><?php echo (int) $counts['review']; ?></span>
			<?php echo Text::_('MOD_GUIDES_REVIEW_WAITING'); ?>
		</a>
		<?php if ($counts['stale']) : ?>
			<a href="<?php echo $list; ?>" class="text-decoration-none">
				<span class="badge bg-danger fs-6"><?php echo (int) $counts['stale']; ?></span>
				<?php echo Text::_('MOD_GUIDES_REVIEW_STALE'); ?>
			</a>
		<?php endif; ?>
	</div>
	<ul class="list-group list-group-flush">
		<?php foreach (array_merge($review, $stale) as $guide) : ?>
			<li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
				<a href="<?php echo Route::_('index.php?option=com_guides&view=guide&id=' . (int) $guide->id); ?>"><?php echo $e($guide->title); ?></a>
				<?php if ($guide->state === 'stale') : ?>
					<span class="badge bg-danger"><?php echo Text::_('MOD_GUIDES_REVIEW_STALE_ONE'); ?></span>
				<?php else : ?>
					<span class="badge bg-<?php echo $guide->late ? 'danger' : 'light text-dark border'; ?>"><?php echo Text::plural('MOD_GUIDES_REVIEW_N_DAYS', (int) $guide->days); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
