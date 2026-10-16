<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Agent;

\defined('_JEXEC') or die;

/**
 * The light Markdown answers are written in, as HTML, for pages and e-mails: headings, lists,
 * bold and paragraphs, and citations [n] as links to the list of sources. Everything is escaped
 * first; no link, image or markup of the text itself comes through.
 *
 * @since  0.9.0
 */
final class MarkdownLite
{
    /**
     * @param  int  $sources  How many sources there are: a citation beyond them is dropped
     */
    public static function html(string $text, int $sources = 0, string $anchor = 'source-'): string
    {
        $out       = [];
        $paragraph = [];
        $list      = null;

        $inline = static function (string $line) use ($sources, $anchor): string {
            $line = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
            $line = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $line);

            return (string) preg_replace_callback('/\s?[\[［【](\d{1,3})[\]］】]/u', static function ($match) use ($sources, $anchor) {
                $number = (int) $match[1];

                return $number >= 1 && $number <= $sources ? '<a class="report-cite" href="#' . $anchor . $number . '">' . $number . '</a>' : '';
            }, $line);
        };

        $endParagraph = static function () use (&$out, &$paragraph): void {
            if ($paragraph) {
                $out[]     = '<p>' . implode('<br>', $paragraph) . '</p>';
                $paragraph = [];
            }
        };

        $endList = static function () use (&$out, &$list): void {
            if ($list) {
                $out[] = '<' . $list['tag'] . ($list['start'] > 1 ? ' start="' . $list['start'] . '"' : '') . '>' . implode('', array_map(static fn ($item) => '<li>' . $item . '</li>', $list['items'])) . '</' . $list['tag'] . '>';
                $list  = null;
            }
        };

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^(#{1,6})\s+(.*)$/u', $line, $match)) {
                $endParagraph();
                $endList();
                $out[] = '<h3>' . $inline($match[2]) . '</h3>';
            } elseif (preg_match('/^\s*(?:([-*•])|(\d+)[.)])\s+(.*)$/u', $line, $match)) {
                $endParagraph();
                $tag = $match[1] !== '' ? 'ul' : 'ol';

                if (!$list || $list['tag'] !== $tag) {
                    $endList();
                    // A numbered list broken by paragraphs goes on with its own numbers
                    $list = ['tag' => $tag, 'items' => [], 'start' => $tag === 'ol' ? max(1, (int) $match[2]) : 1];
                }

                $list['items'][] = $inline(rtrim($match[3]));
            } elseif (trim($line) === '') {
                // A blank line ends a paragraph; a list goes on until something else comes
                $endParagraph();
            } elseif ($list && preg_match('/^\s{2,}\S/u', $line)) {
                // An indented line goes on with the item above it
                $list['items'][\count($list['items']) - 1] .= '<br>' . $inline(trim($line));
            } else {
                $endList();
                // Markdown that is not handled here (images, tables, quotes) is read as plain text
                $paragraph[] = $inline(trim((string) preg_replace('/^>\s?|!\[[^\]]*\]\([^)]*\)/u', '', $line)));
            }
        }

        $endParagraph();
        $endList();

        return implode("\n", array_filter($out, static fn ($html) => $html !== '<p></p>'));
    }
}
