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
 * Finds what is worth reading in a page of another website: its title, and the body without
 * the menus, headers, footers and side columns around it.
 *
 * @since  0.3.0
 */
final class WebPage
{
    /** Elements that are never the text of a page. */
    /** Characters of text from which a form is taken to be the frame of the page rather than a form. */
    private const PAGE_FORM = 600;

    private const NOISE = ['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'form', 'button', 'select', 'dialog'];

    /** Elements that hold the parts around the text, unless they are inside the text itself. */
    private const FRAME = ['nav', 'header', 'footer', 'aside'];

    /** Classes and IDs of the parts around the text: menus, headers, footers, side columns. */
    private const AROUND = '/(^|[\s_-])(nav|navbar|menu|header|footer|sidebar|side-bar|banner|widget)([\s_-]|$)/i';

    /** Classes and IDs of what is not the text even within it: sharing, comments, related links, notices. */
    private const EXTRAS = '/(^|[\s_-])(breadcrumbs?|cookie|consent|share|sharing|social|related|comments?|pagination|pager|advert|ads|popup|modal|subscribe|newsletter|toolbar|skip|print|tags)([\s_-]|$)/i';

    /**
     * @param   string  $selector  Where the text is, as a simple CSS selector (#id, .class,
     *                             tag, tag.class, tag#id, and these separated by spaces); empty to guess
     * @param   string  $charset   As the server said, if it did
     *
     * @return  array{title: string, html: string}
     */
    public static function read(string $html, string $selector = '', string $charset = ''): array
    {
        $document = new \DOMDocument();

        // Everything is made UTF-8 first. A page that declares another character set in a meta
        // tag would otherwise make the parser switch half-way through and lose the rest.
        if ($charset === '' && preg_match('/<meta[^>]+charset\s*=\s*["\']?([a-z0-9_-]+)/i', $html, $match)) {
            $charset = $match[1];
        }

        // Text that is valid UTF-8 is UTF-8, whatever it says: Big5 and the like hardly ever are
        if ($charset !== '' && !\in_array(strtolower($charset), ['utf-8', 'utf8'], true) && !mb_check_encoding($html, 'UTF-8')) {
            try {
                $html = mb_convert_encoding($html, 'UTF-8', $charset);
            } catch (\Throwable) {
                // An unknown character set: read it as it is
            }
        }

        $html = (string) preg_replace('/<meta[^>]+charset[^>]*>/i', '', $html);

        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();

        $xpath = new \DOMXPath($document);
        $title = self::title($xpath);

        // A form wrapped around the whole page (ASP.NET Web Forms does this) is a frame, not a
        // form: its contents stay and only its controls go. Real forms are small and go entirely.
        foreach (iterator_to_array($xpath->query('//form') ?: []) as $form) {
            if (mb_strlen(trim((string) preg_replace('/\s+/u', ' ', $form->textContent))) >= self::PAGE_FORM) {
                self::remove($xpath, './/input | .//textarea | .//label', $form);

                while ($form->firstChild) {
                    $form->parentNode->insertBefore($form->firstChild, $form);
                }

                $form->parentNode->removeChild($form);
            }
        }

        self::remove($xpath, '//' . implode(' | //', self::NOISE));

        $body = null;

        if ($selector !== '' && ($path = self::xpath($selector)) !== '') {
            $body = $xpath->query($path)?->item(0);
        }

        if (!$body) {
            // The longest article, else the main part
            $longest = 0;

            foreach ($xpath->query('//article') as $node) {
                if (\strlen($node->textContent) > $longest) {
                    $longest = \strlen($node->textContent);
                    $body    = $node;
                }
            }

            $body ??= $xpath->query('//main | //*[@role="main"]')->item(0);
        }

        // Without a part that is clearly the text, everything around it has to go
        if (!$body) {
            $body = $document->getElementsByTagName('body')->item(0);

            self::remove($xpath, '//' . implode(' | //', self::FRAME));
            self::remove($xpath, '//*[@role="navigation" or @role="banner" or @role="contentinfo" or @role="complementary" or @role="search"]');
            self::strip($xpath, '//*[@class or @id]', self::AROUND);
        }

        if (!$body) {
            return ['title' => $title, 'html' => ''];
        }

        self::strip($xpath, './/*[@class or @id]', self::EXTRAS, $body);
        self::remove($xpath, './/nav | .//aside', $body);

        $inner = '';

        foreach ($body->childNodes as $child) {
            $inner .= $document->saveHTML($child);
        }

        return ['title' => $title, 'html' => $inner];
    }

    /**
     * What a crawler needs from a page besides its text: where it links to, its canonical address,
     * and whether it asks not to be indexed or followed (robots meta tag).
     *
     * @return  array{links: string[], base: string, canonical: string, noindex: bool, nofollow: bool}
     */
    public static function signals(string $html): array
    {
        $document = new \DOMDocument();

        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();

        $xpath  = new \DOMXPath($document);
        $robots = '';

        foreach ($xpath->query('//meta[@name]') ?: [] as $meta) {
            if (\in_array(strtolower($meta->getAttribute('name')), ['robots', 'askthesite'], true)) {
                $robots .= ' ' . strtolower($meta->getAttribute('content'));
            }
        }

        $links = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $link) {
            // A link the site itself asks not to follow is not followed
            if (!preg_match('/\bnofollow\b/i', $link->getAttribute('rel'))) {
                $links[] = $link->getAttribute('href');
            }
        }

        $canonical = $xpath->query('//link[@rel="canonical"][@href]')?->item(0);
        $base      = $xpath->query('//base[@href]')?->item(0);

        return [
            'links'     => array_values(array_unique($links)),
            'base'      => $base ? $base->getAttribute('href') : '',
            'canonical' => $canonical ? $canonical->getAttribute('href') : '',
            'noindex'   => (bool) preg_match('/\b(noindex|none)\b/', $robots),
            'nofollow'  => (bool) preg_match('/\b(nofollow|none)\b/', $robots),
        ];
    }

    private static function remove(\DOMXPath $xpath, string $path, ?\DOMNode $within = null): void
    {
        foreach (iterator_to_array($xpath->query($path, $within) ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private static function strip(\DOMXPath $xpath, string $path, string $pattern, ?\DOMNode $within = null): void
    {
        foreach (iterator_to_array($xpath->query($path, $within) ?: []) as $node) {
            if ($node->parentNode && preg_match($pattern, $node->getAttribute('class') . ' ' . $node->getAttribute('id'))) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    private static function title(\DOMXPath $xpath): string
    {
        foreach (['//meta[@property="og:title"]/@content', '//title', '//h1'] as $path) {
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $xpath->query($path)->item(0)?->textContent));

            if ($text !== '') {
                return mb_substr($text, 0, 250);
            }
        }

        return '';
    }

    /**
     * A simple CSS selector as XPath. What is not understood gives an empty path, and the guess is used.
     */
    private static function xpath(string $selector): string
    {
        $steps = [];

        foreach (preg_split('/\s+/', trim($selector)) ?: [] as $part) {
            if (!preg_match('/^([a-z][a-z0-9]*)?((?:[#.][a-z0-9_-]+)*)$/i', $part, $match) || $part === '') {
                return '';
            }

            $step = $match[1] !== '' ? strtolower($match[1]) : '*';

            preg_match_all('/([#.])([a-z0-9_-]+)/i', $match[2], $marks, PREG_SET_ORDER);

            foreach ($marks as [, $kind, $name]) {
                $step .= $kind === '#'
                    ? '[@id="' . $name . '"]'
                    : '[contains(concat(" ", normalize-space(@class), " "), " ' . $name . ' ")]';
            }

            $steps[] = $step;
        }

        return $steps ? '//' . implode('//', $steps) : '';
    }
}
