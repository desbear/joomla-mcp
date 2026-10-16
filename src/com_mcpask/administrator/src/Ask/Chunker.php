<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Ask;

\defined('_JEXEC') or die;

/**
 * Cuts the HTML of a page into passages small enough to be found and quoted one by one.
 *
 * A passage never crosses a heading, so each one can say which section of the page it came from.
 *
 * @since  0.1.0
 */
final class Chunker
{
    private const HEADING = "\x01";

    /**
     * How passages are written. When it changes every page is looked at again; only those whose
     * passages come out different are embedded again.
     */
    public const FORMAT = 2;

    /** Image files a passage may show, by extension. */
    private const IMAGE_FILE = '/\.(?:jpe?g|png|gif|webp|avif)$/i';

    /** An image in a passage: ![description](/path/from/the/site/root.png) */
    public const IMAGE = '/!\[([^\]\n]{0,200})\]\((\/[^()\s]{1,500})\)/u';

    /**
     * Passages are written as light Markdown: headings stay the passages' headings, lists keep
     * their marks and numbers, tables their rows, bold its stars, and images of the site itself
     * (files in its folder, as paths from the site's root) are kept where they stand, so an
     * answer can show the screenshot of the step it explains. Pages of other sites keep no images.
     *
     * @param   int                                       $budget  Approximate size of a passage in tokens
     * @param   array{folder: string, url: string}|null   $site    For the site's own pages: its folder
     *                                                             (JPATH_ROOT) and address; null for other sites
     *
     * @return  array<int, array{heading: string, text: string}>
     */
    public static function chunks(string $html, int $budget, ?array $site = null): array
    {
        $budget  = max(100, $budget);
        $chunks  = [];
        $levels  = [];
        $heading = '';
        $parts   = [];
        $size    = 0;

        $flush = static function () use (&$chunks, &$parts, &$size, &$heading): void {
            $text = trim(implode("\n\n", $parts));

            if ($text !== '') {
                $chunks[] = ['heading' => $heading, 'text' => $text];
            }

            $parts = [];
            $size  = 0;
        };

        foreach (self::blocks($html, $site) as $block) {
            if ($block[0] === self::HEADING) {
                $flush();

                // "\x01" + level + text: keep the path of headings above this one
                $level  = (int) $block[1];
                $levels = array_filter($levels, static fn ($key) => $key < $level, ARRAY_FILTER_USE_KEY);

                $levels[$level] = substr($block, 2);
                $heading        = mb_substr(implode(' › ', $levels), 0, 250);

                continue;
            }

            foreach (self::split($block, $budget) as $piece) {
                $tokens = self::tokens($piece);

                if ($size > 0 && $size + $tokens > $budget) {
                    // Repeat a short closing paragraph, so a thought cut in two is whole in one of them
                    $last = end($parts);
                    $flush();

                    if (self::tokens($last) <= $budget / 5) {
                        $parts = [$last];
                        $size  = self::tokens($last);
                    }
                }

                $parts[] = $piece;
                $size   += $tokens;
            }
        }

        $flush();

        return $chunks;
    }

    /**
     * A passage as words only, for its embedding: an image is its description, bold is plain.
     */
    public static function words(string $text): string
    {
        $text = (string) preg_replace(self::IMAGE, '$1', $text);

        return str_replace('**', '', $text);
    }

    /**
     * The images of passages, as paths from the site's root.
     *
     * @return  string[]
     */
    public static function images(string $text): array
    {
        preg_match_all(self::IMAGE, $text, $matches);

        return array_values(array_unique($matches[2]));
    }

    /**
     * Rough token count: one per CJK character, one per four other characters.
     */
    public static function tokens(string $text): int
    {
        $cjk = (int) preg_match_all('/[\x{2E80}-\x{9FFF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}\x{FF00}-\x{FFEF}]/u', $text);

        return $cjk + (int) ceil((mb_strlen($text) - $cjk) / 4);
    }

    /**
     * The HTML without the elements an editor hid (the hidden attribute, display: none,
     * visibility: hidden, aria-hidden), each with everything inside it however deeply nested.
     */
    private static function withoutHidden(string $html): string
    {
        if (!preg_match('/\shidden\b|display\s*:\s*none|visibility\s*:\s*hidden|aria-hidden/i', $html)) {
            return $html;
        }

        $document = new \DOMDocument();

        libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();

        // A page that cannot be read as HTML is not indexed at all: safer than indexing what is hidden
        if (!$loaded) {
            return '';
        }

        $xpath  = new \DOMXPath($document);
        $style  = "translate(@style, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ \t\n\r', 'abcdefghijklmnopqrstuvwxyz')";
        $hidden = $xpath->query(
            '//*[@hidden or translate(@aria-hidden, "TRUE", "true") = "true"'
            . ' or contains(' . $style . ', "display:none") or contains(' . $style . ', "visibility:hidden")]'
        );

        foreach (iterator_to_array($hidden ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $body = $document->getElementsByTagName('body')->item(0);
        $kept = '';

        foreach ($body ? $body->childNodes : [] as $child) {
            $kept .= $document->saveHTML($child);
        }

        return $kept;
    }

    /**
     * Paragraphs of text in reading order; headings are marked with "\x01" and their level.
     *
     * @return  string[]
     */
    private static function blocks(string $html, ?array $site): array
    {
        // Content that is never text. If a pattern cannot be applied (text too large for the regex
        // engine) the result is null and the page is indexed as empty, which fails on the safe side.
        $html = (string) preg_replace('#<(script|style|noscript|iframe|svg|template|form)\b.*?</\1>#is', ' ', $html);
        $html = (string) preg_replace('#<(script|style)\b.*$#is', ' ', $html);
        $html = (string) preg_replace('#<!--.*?-->#s', ' ', $html);

        // Elements an editor has hidden are not what a visitor reads, with all they hold
        $html = self::withoutHidden($html);

        // Plugin tags. A pair such as {source}...{/source} (code that is run on the server) or
        // {show ...}...{/show} (text for some visitors only) goes with everything inside it: what it
        // would turn into is not known here, and it is not for everyone. Pairs inside pairs go
        // from the innermost out, so an outer pair is never cut short by an inner one. Then the
        // single tags, such as {loadmoduleid 12}.
        for ($round = 0; $round < 20; $round++) {
            $before = $html;
            $html   = (string) preg_replace('#\{([a-z][a-z0-9_-]*)(\s[^{}]*)?\}((?:(?!\{/?\1[\s}]).)*?)\{/\1\}#is', ' ', $html);

            if ($html === $before) {
                break;
            }
        }

        $html = (string) preg_replace('#\{/?[a-z][a-z0-9_-]*(\s[^{}]*)?\}#i', ' ', $html);

        $html = (string) preg_replace_callback(
            '#<h([1-6])\b[^>]*>(.*?)</h\1>#is',
            static fn ($match) => "\n\n" . self::HEADING . $match[1] . self::plain($match[2]) . "\n\n",
            $html
        );

        // Images of the site itself, as Markdown that survives the removal of the tags below
        $html = (string) preg_replace_callback(
            '#<img\b[^>]*>#i',
            static fn ($match) => self::image($match[0], $site),
            $html
        );

        // Bold, unless it is the whole of a heading or a cell (where it says nothing)
        $html = (string) preg_replace('#<(strong|b)\b[^>]*>\s*(.*?)\s*</\1>#is', '**$2**', $html);

        // Numbered lists keep their numbers; the innermost first, so a list inside a list is numbered on its own
        for ($round = 0; $round < 5; $round++) {
            $before = $html;
            $html   = (string) preg_replace_callback('#<ol\b[^>]*>((?:(?!<ol\b).)*?)</ol>#is', static function ($match) {
                $number = 0;

                return "\n\n" . preg_replace_callback('#<li\b[^>]*>#i', static function () use (&$number) {
                    return "\n" . ++$number . '. ';
                }, $match[1]) . "\n\n";
            }, $html);

            if ($html === $before) {
                break;
            }
        }

        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = (string) preg_replace('#<li\b[^>]*>#i', "\n- ", $html);

        // Table rows as Markdown rows, one per line
        $html = (string) preg_replace('#<tr\b[^>]*>#i', "\n| ", $html);
        $html = (string) preg_replace('#</t[dh]>#i', ' | ', $html);
        $html = (string) preg_replace('#</?(?:tr|thead|tbody|tfoot)\b[^>]*>#i', '', $html);
        $html = (string) preg_replace('#</(p|div|section|article|ul|ol|table|blockquote|pre|dl|dd|dt|figure|hr)>|<hr\b[^>]*>#i', "\n\n", $html);

        $blocks = [];

        foreach (preg_split('/\n\s*\n/', $html) ?: [] as $block) {
            $marker = str_contains($block, self::HEADING) ? substr($block, (int) strpos($block, self::HEADING), 2) : '';
            $lines  = array_filter(array_map([self::class, 'plain'], explode("\n", str_replace($marker, '', $block))), 'strlen');

            if ($lines) {
                $blocks[] = $marker . implode("\n", $lines);
            }
        }

        return $blocks;
    }

    /**
     * An image as Markdown when it is a file of this site; otherwise nothing. Kept as its path from
     * the site's root, checked to be a file there: an address of another site, a script, a path
     * leading out of the site or a file that is not there is never shown in an answer.
     *
     * @param  array{folder: string, url: string}|null  $site
     */
    private static function image(string $tag, ?array $site): string
    {
        if ($site === null || !preg_match('#\ssrc\s*=\s*(["\'])(.*?)\1#is', $tag, $src)) {
            return ' ';
        }

        $url = trim(html_entity_decode($src[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // The site's address without its scheme ("//www.example.com/sub/"), and its path ("/sub")
        $root = (string) preg_replace('#^[a-z][a-z0-9+.-]*:#i', '', rtrim($site['url'], '/') . '/');
        $base = rtrim((string) parse_url($site['url'], PHP_URL_PATH), '/');

        if (preg_match('#^(?:https?:)?//#i', $url)) {
            // An address with a host is the site's own only when it is the site's address (either scheme)
            $bare = (string) preg_replace('#^https?:#i', '', $url);

            if ($root === '/' || stripos($bare, $root) !== 0) {
                return ' ';
            }

            $url = '/' . substr($bare, \strlen($root));
        } elseif (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            // data:, javascript: and the like
            return ' ';
        } elseif (str_starts_with($url, '/')) {
            if ($base !== '' && str_starts_with($url, $base . '/')) {
                $url = substr($url, \strlen($base));
            }
        } else {
            $url = '/' . $url;
        }

        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));

        if (
            !preg_match(self::IMAGE_FILE, $path) || str_contains($path, '..') || str_contains($path, '//')
            || !preg_match('#^/[\p{L}\p{N}._~\-/ ]+$#u', $path) || !is_file(rtrim($site['folder'], '/') . $path)
        ) {
            return ' ';
        }

        $alt = preg_match('#\salt\s*=\s*(["\'])(.*?)\1#is', $tag, $a) ? self::plain($a[2]) : '';
        $alt = mb_substr(str_replace(['[', ']'], ['(', ')'], $alt), 0, 200);

        // A space in a path would end the address in Markdown
        return ' ![' . $alt . '](' . str_replace(' ', '%20', $path) . ') ';
    }

    private static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[\s\x{00A0}\x{3000}]+/u', ' ', $text));
    }

    /**
     * A paragraph longer than a passage is cut at sentence ends, and a sentence that is still
     * too long is cut where it must be.
     *
     * @return  string[]
     */
    private static function split(string $text, int $budget): array
    {
        if (self::tokens($text) <= $budget) {
            return [$text];
        }

        $pieces  = [];
        $current = '';

        foreach (preg_split('/(?<=[。！？!?；;\n])|(?<=\.)\s/u', $text) ?: [] as $sentence) {
            while (self::tokens($sentence) > $budget) {
                $pieces[] = trim($current);
                $current  = '';
                $pieces[] = mb_substr($sentence, 0, $budget);
                $sentence = mb_substr($sentence, $budget);
            }

            if ($current !== '' && self::tokens($current . $sentence) > $budget) {
                $pieces[] = trim($current);
                $current  = '';
            }

            $current .= $sentence . (preg_match('/[.!?;]$/', $sentence) ? ' ' : '');
        }

        $pieces[] = trim($current);

        return array_values(array_filter($pieces, 'strlen'));
    }
}
