<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/** @var string $title */
$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?php echo $e(Factory::getApplication()->getLanguage()->getTag()); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo $e($title); ?></title>
<style>
:root { --bg: #f4f5f7; --card: #fff; --text: #1f2328; --muted: #656d76; --line: #d8dee4; --accent: #2a69b8; --accent-text: #fff; --warn-bg: #fff8e5; --warn-line: #e8c96a; }
@media (prefers-color-scheme: dark) { :root { --bg: #16181c; --card: #22252b; --text: #e6e8eb; --muted: #9aa3ad; --line: #3a3f47; --accent: #5b9be6; --accent-text: #0d1117; --warn-bg: #3a3220; --warn-line: #8a7433; } }
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; background: var(--bg); color: var(--text); font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang TC", "Microsoft JhengHei", Roboto, sans-serif; }
.card { width: 100%; max-width: 440px; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 28px; }
.site { color: var(--muted); font-size: 13px; margin: 0 0 16px; }
h1 { font-size: 20px; line-height: 1.35; margin: 0 0 8px; font-weight: 600; }
p { margin: 0 0 12px; }
.muted { color: var(--muted); font-size: 13px; }
.user { display: flex; justify-content: space-between; gap: 8px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; margin: 16px 0; font-size: 14px; }
.user a { color: var(--accent); }
ul { list-style: none; padding: 0; margin: 8px 0 16px; }
li label { display: flex; gap: 10px; align-items: flex-start; padding: 8px 0; border-bottom: 1px solid var(--line); cursor: pointer; }
li:last-child label { border-bottom: 0; }
input[type=checkbox] { margin-top: 3px; width: 16px; height: 16px; accent-color: var(--accent); }
.warn { background: var(--warn-bg); border: 1px solid var(--warn-line); border-radius: 8px; padding: 10px 12px; font-size: 13px; margin: 0 0 16px; }
.actions { display: flex; gap: 10px; margin-top: 20px; }
button { flex: 1; font: inherit; font-weight: 600; padding: 10px 14px; border-radius: 8px; border: 1px solid var(--line); background: transparent; color: var(--text); cursor: pointer; }
button.primary { background: var(--accent); border-color: var(--accent); color: var(--accent-text); }
code { font-size: 13px; word-break: break-all; }
</style>
</head>
<body>
<main class="card">
<p class="site"><?php echo $e($siteName); ?></p>
