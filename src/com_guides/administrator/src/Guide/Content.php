<?php

/**
 * @package     Guides
 * @subpackage  com_guides
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Guides\Administrator\Guide;

use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The articles guides are made from: Joomla articles and FLEXIcontent items (which are articles
 * with a content type), found by words, and read section by section, a section being what stands
 * under one heading.
 *
 * @since  0.1.0
 */
final class Content
{
    public const TYPES = ['content', 'flexicontent'];

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * Published articles whose title (or text, with $inText) holds all these words.
     *
     * @return  array<int, array{source_type: string, item_id: int, title: string, category: string, catid: int, modified: string, used: int}>
     */
    public function search(string $words, bool $inText = false, int $limit = 30): array
    {
        $db    = $this->db;
        $fc    = Flexicontent::isAvailable();
        $query = $db->getQuery(true)
            ->select('a.id, a.title, a.catid, a.modified, c.title AS category')
            ->select($fc ? '(SELECT ie.type_id FROM ' . $db->quoteName('#__flexicontent_items_ext') . ' ie WHERE ie.item_id = a.id) AS fc_type' : '0 AS fc_type')
            ->select('(SELECT COUNT(DISTINCT s.step_id) FROM ' . $db->quoteName('#__guides_sources') . ' s WHERE s.item_id = a.id) AS used')
            ->from($db->quoteName('#__content', 'a'))
            ->join('LEFT', $db->quoteName('#__categories', 'c'), 'c.id = a.catid')
            ->where('a.state = 1')
            ->order('a.modified DESC')
            ->setLimit($limit);
        $likes = [];

        foreach (array_values(array_filter(preg_split('/\s+/u', trim($words)) ?: [])) as $n => $word) {
            $likes[$n] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word) . '%';
            $query->where($inText
                ? '(a.title LIKE :w' . $n . ' OR a.introtext LIKE :i' . $n . ' OR a.fulltext LIKE :f' . $n . ')'
                : 'a.title LIKE :w' . $n);
            $query->bind(':w' . $n, $likes[$n]);

            if ($inText) {
                $query->bind(':i' . $n, $likes[$n])->bind(':f' . $n, $likes[$n]);
            }
        }

        return array_map(static fn ($row) => [
            'source_type' => (int) $row->fc_type > 0 ? 'flexicontent' : 'content',
            'item_id'     => (int) $row->id,
            'title'       => (string) $row->title,
            'category'    => (string) $row->category,
            'catid'       => (int) $row->catid,
            'modified'    => (string) $row->modified,
            'used'        => (int) $row->used,
        ], $db->setQuery($query)->loadObjectList());
    }

    /**
     * An article as a guide is made from it.
     *
     * @return  array{source_type: string, item_id: int, title: string, html: string, url: string, catid: int}|null
     */
    public function item(string $type, int $id): ?array
    {
        $db  = $this->db;
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select('id, title, alias, catid, introtext, ' . $db->quoteName('fulltext') . ', language')
                ->from($db->quoteName('#__content'))
                ->where('id = ' . $id)
        )->loadObject();

        if (!$row || !\in_array($type, self::TYPES, true)) {
            return null;
        }

        return [
            'source_type' => $type,
            'item_id'     => (int) $row->id,
            'title'       => (string) $row->title,
            'html'        => (string) $row->introtext . "\n" . (string) $row->fulltext,
            'url'         => self::url($type, (int) $row->id, (string) $row->alias, (int) $row->catid, (string) $row->language),
            'catid'       => (int) $row->catid,
        ];
    }

    /**
     * The sections of an article: what stands under each heading (h2 to h4), with the text before
     * the first heading as a section without a heading. A section's hash is that of its text, so a
     * change of its wording, not of its markup, is noticed.
     *
     * @return  array<int, array{section: string, level: int, html: string, text: string, hash: string}>
     */
    public function sections(string $type, int $id): array
    {
        $item = $this->item($type, $id);

        if (!$item) {
            return [];
        }

        $parts    = preg_split('#(<h([2-4])\b[^>]*>.*?</h\2>)#is', $item['html'], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $sections = [];
        $heading  = '';
        $level    = 0;
        $buffer   = (string) array_shift($parts);

        $flush = function () use (&$sections, &$heading, &$level, &$buffer): void {
            $html = self::balanced($buffer);
            $text = self::text($html);

            if ($text !== '' || $heading !== '') {
                $sections[] = ['section' => $heading, 'level' => $level, 'html' => $html, 'text' => mb_substr($text, 0, 400), 'hash' => sha1($heading . "\n" . $text)];
            }
        };

        while ($parts) {
            $flush();

            [$whole, $depth] = [array_shift($parts), array_shift($parts)];
            $heading = trim(html_entity_decode(strip_tags((string) $whole), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $level   = (int) $depth;
            $buffer  = (string) array_shift($parts);
        }

        $flush();

        return $sections;
    }

    /**
     * The hash of one section as it is now; null when the article or the section is gone.
     */
    public function sectionHash(string $type, int $id, string $section): ?string
    {
        foreach ($this->sections($type, $id) as $part) {
            if ($part['section'] === $section) {
                return $part['hash'];
            }
        }

        return null;
    }

    /**
     * The site address of an article or FLEXIcontent item.
     */
    public static function url(string $type, int $id, string $alias, int $catid, string $language = '*'): string
    {
        $link = $type === 'flexicontent'
            ? 'index.php?option=com_flexicontent&view=item&cid=' . $catid . '&id=' . $id . ':' . $alias
            : 'index.php?option=com_content&view=article&id=' . $id . ':' . $alias . '&catid=' . $catid;

        if ($language !== '' && $language !== '*') {
            $link .= '&lang=' . $language;
        }

        try {
            return Route::link('site', $link, false, Route::TLS_IGNORE, true);
        } catch (\Throwable) {
            return rtrim(Uri::root(), '/') . '/' . $link;
        }
    }

    /**
     * A fragment of HTML with its tags closed, as a section cut out of an article may not be.
     */
    private static function balanced(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new \DOMDocument();

        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();

        $body = $document->getElementsByTagName('body')->item(0);
        $out  = '';

        foreach ($body ? $body->childNodes : [] as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out);
    }

    private static function text(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
