<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Gss\Component\Mcpask\Administrator\Agent\MarkdownLite;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Gss\Component\Mcpask\Site\View\Report\HtmlView $this */

$e       = static fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
$content = $this->report->content;
$type    = $content['type'] ?? [];
$sources = $content['sources'] ?? [];
// Only the pages the report cites are listed, under the numbers it cites them by
preg_match_all('/[\[［【](\d{1,3})[\]］】]/u', implode("\n", array_column($content['sections'] ?? [], 'text')), $cites);
$cited   = array_intersect_key($sources, array_flip(array_map(static fn ($n) => (int) $n - 1, $cites[1])));
ksort($cited);
$colour  = preg_match('/^#[0-9a-f]{6}$/i', (string) ($type['color'] ?? '')) ? $type['color'] : '';
$safe    = static fn ($url) => preg_match('#^(https?://|/)#i', (string) $url) || $url === '#mcp-ask-handoff' ? (string) $url : '#';
?>
<article class="mcpask-report"<?php echo $colour !== '' ? ' style="--report-colour: ' . $e($colour) . '"' : ''; ?>>
	<header class="mcpask-report__hero">
		<div class="mcpask-report__lead">
			<p class="mcpask-report__eyebrow"><?php echo Text::_('COM_MCPASK_REPORT'); ?></p>
			<h1 class="mcpask-report__title"><?php echo $e($content['title'] ?? ''); ?></h1>
			<?php if (!empty($content['greeting'])) : ?>
				<p class="mcpask-report__greeting"><?php echo $e(Text::sprintf('COM_MCPASK_REPORT_HELLO', $content['greeting'])); ?></p>
			<?php endif; ?>
			<?php if (!empty($type['summary'])) : ?>
				<p class="mcpask-report__summary"><?php echo $e($type['summary']); ?></p>
			<?php endif; ?>
		</div>
		<?php if (!empty($type['image'])) : ?>
			<img class="mcpask-report__type-image" src="<?php echo $e($type['image']); ?>" alt="<?php echo $e($type['name'] ?? ''); ?>">
		<?php endif; ?>
	</header>

	<?php if (!empty($content['scores'])) : ?>
		<section class="mcpask-report__scores" aria-label="<?php echo $e(Text::_('COM_MCPASK_REPORT_SCORES')); ?>">
			<?php foreach ($content['scores'] as $score) : ?>
				<div class="mcpask-report__score">
					<div class="mcpask-report__score-head">
						<span class="mcpask-report__score-name"><?php echo $e($score['name']); ?></span>
						<span class="mcpask-report__score-value"><?php echo (int) $score['score']; ?></span>
					</div>
					<span class="mcpask-report__bar" style="--score: <?php echo (int) $score['score']; ?>%" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $score['score']; ?>" aria-label="<?php echo $e($score['name']); ?>"></span>
					<?php if (!empty($score['description'])) : ?>
						<p class="mcpask-report__score-desc"><?php echo $e($score['description']); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</section>
	<?php endif; ?>

	<?php if (!empty($content['intro'])) : ?>
		<p class="mcpask-report__intro"><?php echo nl2br($e($content['intro']), false); ?></p>
	<?php endif; ?>

	<?php foreach ($content['sections'] ?? [] as $index => $section) : ?>
		<section class="mcpask-report__section">
			<h2><span class="mcpask-report__number"><?php echo $index + 1; ?></span><?php echo $e($section['title']); ?></h2>
			<?php if (!empty($section['image']['url'])) : ?>
				<img class="mcpask-report__image" src="<?php echo $e($safe($section['image']['url'])); ?>" alt="<?php echo $e($section['image']['alt'] ?? ''); ?>" loading="lazy">
			<?php endif; ?>
			<div class="mcpask-report__text"><?php echo MarkdownLite::html((string) $section['text'], count($sources)); ?></div>
		</section>
	<?php endforeach; ?>

	<?php if (!empty($content['actions'])) : ?>
		<nav class="mcpask-report__actions">
			<?php foreach ($content['actions'] as $action) : ?>
				<a class="mcpask-report__action" href="<?php echo $e($safe($action['url'])); ?>"><?php echo $e($action['label']); ?></a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<?php if ($cited) : ?>
		<section class="mcpask-report__sources">
			<h2><?php echo Text::_('COM_MCPASK_REPORT_SOURCES'); ?></h2>
			<ol>
				<?php foreach ($cited as $number => $source) : ?>
					<li id="source-<?php echo $number + 1; ?>" value="<?php echo $number + 1; ?>"><a href="<?php echo $e($safe($source['url'])); ?>"><?php echo $e($source['title']); ?></a></li>
				<?php endforeach; ?>
			</ol>
		</section>
	<?php endif; ?>

	<footer class="mcpask-report__footer">
		<p><?php echo Text::sprintf('COM_MCPASK_REPORT_KEPT_UNTIL', HTMLHelper::_('date', $this->report->expires, Text::_('DATE_FORMAT_LC3'))); ?></p>
		<p><?php echo Text::_('COM_MCPASK_REPORT_AI_NOTE'); ?></p>
		<button type="button" class="mcpask-report__print" onclick="window.print()"><?php echo Text::_('COM_MCPASK_REPORT_PRINT'); ?></button>
	</footer>
</article>
