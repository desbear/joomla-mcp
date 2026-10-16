<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  mod_mcp_ask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

/**
 * @var string   $mode         "icon", "box" (always-open search box) or "float" (button fixed to the corner of the window)
 * @var boolean  $start        The icon opens towards the end of the line
 * @var string   $floatAt      Where the floating button sits on the edge of the window: "top", "middle" or "bottom"
 * @var string   $title
 * @var string   $placeholder
 * @var string   $endpoint     Address of the answer service, empty in demo mode
 * @var integer  $pageItem     ID of the article or item this page shows, or 0
 * @var array    $demoSources
 * @var string   $accent
 * @var array|null $spot       Where the visitor moved the floating button: side ("left"/"right") and y (0–1)
 * @var array    $strings      Texts for ask.js
 */

$e     = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$json  = static fn ($value) => $e(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$class = 'mcp-ask' . ($mode !== 'icon' ? ' mcp-ask--' . $mode : '') . (!empty($pet) ? ' mcp-ask--pet' : '') . ($start && $mode === 'icon' ? ' mcp-ask--start' : '')
    . ($mode === 'float' && $floatAt !== 'bottom' ? ' mcp-ask--float-' . $floatAt : '');
?>
<div class="<?php echo $class; ?>" data-mcp-ask data-mode="<?php echo $mode; ?>" data-state="idle"
    <?php if ($endpoint !== '') : ?>data-endpoint="<?php echo $e($endpoint); ?>"<?php endif; ?>
    <?php if (!empty($feedback)) : ?>data-feedback="<?php echo $e($feedback); ?>"<?php endif; ?>
    <?php if (!empty($agentStart)) : ?>data-agent-start="<?php echo $e($agentStart); ?>" data-agent-step="<?php echo $e($agentStep); ?>" data-agent-report="<?php echo $e($agentReport ?? ''); ?>" data-agent-handoff="<?php echo $e($agentHandoff ?? ''); ?>"<?php endif; ?>
    <?php if (!empty($agents)) : ?>data-agents="<?php echo $json($agents); ?>"<?php endif; ?>
    <?php if (!empty($handoff)) : ?>data-handoff="<?php echo $json($handoff); ?>"<?php endif; ?>
    <?php if (!empty($agentLooks)) : ?>data-agent-looks="<?php echo $json((object) $agentLooks); ?>"<?php endif; ?>
    <?php if (!empty($allowedHosts)) : ?>data-allowed-hosts="<?php echo $json($allowedHosts); ?>"<?php endif; ?>
    <?php if ($pageItem) : ?>data-page-item="<?php echo (int) $pageItem; ?>"<?php endif; ?>
    data-sources-shown="<?php echo (int) ($sourcesShown ?? 3); ?>"
    <?php if (!empty($pet)) : ?>data-pet="<?php echo $json($pet); ?>"<?php endif; ?>
    <?php if (!empty($embed)) : ?>data-embed="<?php echo $json($embed); ?>" data-side="<?php echo $e($embed['side'] ?? 'right'); ?>"<?php endif; ?>
    <?php if ($demoSources) : ?>data-demo-sources="<?php echo $json($demoSources); ?>"<?php endif; ?>
    <?php if ($spot) : ?>data-side="<?php echo $spot['side']; ?>" data-y<?php endif; ?>
    <?php if ($accent !== '' || $spot) : ?>style="<?php echo $accent !== '' ? '--mcp-ask-accent: ' . $e($accent) . ';' : ''; ?><?php echo $spot ? ' --mcp-ask-y: ' . round($spot['y'], 4) . ';' : ''; ?>"<?php endif; ?>
    data-site-root="<?php echo $e(rtrim(Uri::root(true), '/')); ?>"
    data-ufo="<?php echo $e(Uri::root(true) . '/media/mod_mcp_ask/images/ufo.png'); ?>"
    data-strings="<?php echo $json($strings); ?>">
    <form class="mcp-ask__form" role="search">
        <button type="button" class="mcp-ask__toggle" aria-label="<?php echo $e($title); ?>"<?php echo $mode === 'box' ? '' : ' aria-expanded="false"'; ?>>
            <svg class="mcp-ask__ai" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4h10a4 4 0 0 1 4 4v5a4 4 0 0 1-4 4h-5.2l-4.3 3.4V17H7a4 4 0 0 1-4-4V8a4 4 0 0 1 4-4z"/><path class="mcp-ask__spark" d="M10.4 6.9c.38 2.07 1.45 3.14 3.5 3.5-2.05.37-3.12 1.44-3.5 3.5-.37-2.06-1.44-3.13-3.5-3.5 2.06-.36 3.13-1.43 3.5-3.5z"/><path class="mcp-ask__spark mcp-ask__spark--small" d="M16.1 6.2c.18.95.66 1.43 1.6 1.6-.94.18-1.42.66-1.6 1.6-.17-.94-.65-1.42-1.6-1.6.95-.17 1.43-.65 1.6-1.6z"/></svg>
            <?php if ($mode === 'float') : ?>
                <svg class="mcp-ask__send" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
            <?php endif; ?>
        </button>
        <input class="mcp-ask__input" type="text" inputmode="search" enterkeyhint="search" autocomplete="off" maxlength="500"
            placeholder="<?php echo $e($placeholder); ?>" aria-label="<?php echo $e($placeholder); ?>">
        <span class="mcp-ask-dots" aria-hidden="true"><i></i><i></i><i></i></span>
        <button type="submit" class="mcp-ask__submit" aria-label="<?php echo $e(Text::_('MOD_MCP_ASK_SEND')); ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
        </button>
    </form>
    <span class="mcp-ask__status" role="status"></span>
</div>
