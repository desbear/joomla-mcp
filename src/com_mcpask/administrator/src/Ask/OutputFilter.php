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
 * The last word on what an answer may say, whatever the model was talked into: no links to sites
 * that are neither allowed nor among the pages the answer was written from, no phone numbers,
 * e-mail addresses or LINE IDs the pages do not give, and no text a visitor asked to have repeated.
 *
 * The answer is held back until a sentence is complete and only then let through, filtered, so a
 * visitor never sees what is removed, not even for a moment.
 *
 * @since  0.6.5
 */
final class OutputFilter
{
    /** Shortest stretch of the question that counts as repeated when it turns up in the answer. */
    private const ECHO_LENGTH = 8;

    /** Text held back while no sentence ends, at most: past this it is let through as it is. */
    private const MAX_HELD = 2000;

    private const URL = '#(?:https?://|www\.)[^\s<>()\[\]{}"\'（）「」『』，。、；！？]+#iu';

    /** A markdown link; its address may hold one level of brackets, as javascript:alert(1) does. */
    private const MARKDOWN_LINK = '#\[([^\]\n]{0,200})\]\(\s*((?:[^()\s]|\([^()\s]*\))+)\s*\)#u';

    /** The end of a text that stops inside a markdown link or image, which is not complete yet. */
    private const OPEN_LINK = '/\[[^\]\n]{0,200}(?:\]\([^)\s]*)?$/u';

    /** A markdown image, as the answer may give one: ![description](address) */
    private const MARKDOWN_IMAGE = '#!\[([^\]\n]{0,200})\]\(\s*([^()\s]{1,500})\s*\)#u';

    private const EMAIL = '#[A-Za-z0-9._%+\-]{1,64}@[A-Za-z0-9.\-]{1,190}\.[A-Za-z]{2,24}#';

    /** Phone numbers in the usual Taiwanese and international forms, with an extension perhaps. */
    private const PHONE = '#(?<![\w+])(?:\+?\d{1,3}[\s\-.]?)?(?:\(\d{1,4}\)[\s\-.]?)?\d{2,4}[\s\-.]?\d{3,4}[\s\-.]?\d{3,4}(?:\s?(?:\#|ext\.?|分機|轉)\s?\d{1,6})?(?![\w])#u';

    /** A LINE account: "LINE ID: x", "LINE: x" or "LINE @x" (not the word "line" in a sentence). */
    private const LINE_ID = '#\bLINE\s*(?:ID\s*[:：]?|[:：]|(?=@))\s*@?[A-Za-z0-9._\-]{3,30}#iu';

    /** What looks like a code or a marker rather than words: letters and digits mixed, joined by - or _. */
    private const TOKEN = '#(?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9][A-Za-z0-9_\-]{5,}#';

    private string $held = '';

    private string $raw = '';

    private string $clean = '';

    /** @var array<string, int>  What was removed: "link", "contact", "echo", "image", by count */
    private array $removed = [];

    /**
     * @param  string[]                                  $allowedHosts  Hosts links may lead to: "example.com" or "*.example.com"
     * @param  callable(): array{text: string, urls: string[]}  $sources  The text and addresses of the pages the answer is written from, as they are now
     * @param  bool                                      $suspect       The question looked like an attempt to steer the answer
     */
    public function __construct(
        private string $question,
        private array $allowedHosts,
        private $sources,
        private bool $suspect,
        private bool $chinese
    ) {
    }

    /**
     * Take the next piece of the answer; give back what may be shown now (complete sentences, filtered).
     */
    public function feed(string $text): string
    {
        $this->raw  .= $text;
        $this->held .= $text;

        // Up to the end of the last complete sentence, or of the last line; "!" before "[" starts
        // an image, and an image or link is never cut in two
        if (
            (!preg_match('/^.*(?:[。！？?]|!(?=[^\[])|\.(?=\s)|\n)/us', $this->held, $match) || preg_match(self::OPEN_LINK, $match[0]))
            && mb_strlen($this->held) < self::MAX_HELD
        ) {
            return '';
        }

        $ready      = $match[0] ?? $this->held;
        $this->held = (string) substr($this->held, \strlen($ready));

        return $this->pass($ready);
    }

    /**
     * Whatever is still held back, filtered: the answer is complete.
     */
    public function finish(): string
    {
        $rest       = $this->held;
        $this->held = '';

        return $rest !== '' ? $this->pass($rest) : '';
    }

    /**
     * The answer as written by the model.
     */
    public function raw(): string
    {
        return $this->raw;
    }

    /**
     * The answer as shown to the visitor.
     */
    public function clean(): string
    {
        return $this->clean;
    }

    /**
     * @return  array<string, int>  What was removed, by kind; empty when nothing was
     */
    public function removed(): array
    {
        return $this->removed;
    }

    private function pass(string $text): string
    {
        $filtered     = $this->filter($text);
        $this->clean .= $filtered;

        return $filtered;
    }

    /**
     * Filter one stretch of the answer.
     */
    public function filter(string $text): string
    {
        $sources = ($this->sources)();
        $known   = mb_strtolower($sources['text']);
        $digits  = $this->phoneDigits($sources['text']);
        $hosts   = array_map(static fn ($url) => strtolower((string) parse_url($url, PHP_URL_HOST)), $sources['urls']);

        // Repeated text first, so a link or number that was only asked for goes with its sentence
        $text = $this->withoutEcho($text, $known);

        // Images: only those the pages themselves show (the site's own files, kept by the index as
        // paths), given exactly as they stand there. Set aside so the rules below leave their
        // addresses alone; any other image goes without a trace.
        $images = [];
        $text   = (string) preg_replace_callback(self::MARKDOWN_IMAGE, function ($match) use ($known, &$images) {
            $path = $match[2];

            if (!str_starts_with($path, '/') || str_starts_with($path, '//') || !str_contains($known, mb_strtolower('](' . $path . ')'))) {
                $this->removed['image'] = ($this->removed['image'] ?? 0) + 1;

                return '';
            }

            $images[] = '![' . str_replace(['[', ']'], ['(', ')'], $match[1]) . '](' . $path . ')';

            return "\u{E000}" . (\count($images) - 1) . "\u{E001}";
        }, $text);

        $text = (string) preg_replace_callback(self::MARKDOWN_LINK, function ($match) use ($known, $hosts) {
            return $this->linkAllowed($match[2], $known, $hosts) ? $match[0] : $match[1] . $this->note('link');
        }, $text);

        $text = (string) preg_replace_callback(self::URL, function ($match) use ($known, $hosts) {
            $url = rtrim($match[0], '.,;:!?');

            return $this->linkAllowed($url, $known, $hosts) ? $match[0] : $this->note('link') . substr($match[0], \strlen($url));
        }, $text);

        $text = (string) preg_replace_callback(self::EMAIL, fn ($match) => str_contains($known, mb_strtolower($match[0])) ? $match[0] : $this->note('contact'), $text);

        $text = (string) preg_replace_callback(self::LINE_ID, fn ($match) => str_contains($known, mb_strtolower($match[0])) || str_contains($known, mb_strtolower((string) preg_replace('/^.*?[:：]?\s*@?(?=[A-Za-z0-9._\-]+$)/i', '', $match[0]))) ? $match[0] : $this->note('contact'), $text);

        $text = (string) preg_replace_callback(self::PHONE, function ($match) use ($digits) {
            $number = preg_replace('/\D/', '', $match[0]);

            // Not a phone number: too short or long, or a date
            if (\strlen($number) < 8 || \strlen($number) > 15 || preg_match('#^(19|20)\d{2}[\-/.]\d{1,2}[\-/.]\d{1,2}$#', trim($match[0]))) {
                return $match[0];
            }

            foreach ($digits as $given) {
                if (str_contains($given, $number) || str_contains($number, $given)) {
                    return $match[0];
                }
            }

            return $this->note('contact');
        }, $text);

        return (string) preg_replace_callback("/\u{E000}(\d+)\u{E001}/u", static fn ($match) => $images[(int) $match[1]] ?? '', $text);
    }

    /**
     * Sentences that repeat a stretch of the question the pages do not hold go. For a question that
     * looked like an attempt to steer the answer, any such stretch of 8 characters or more; for any
     * other, only stretches that look like codes or markers, so an answer may still restate what
     * was asked in its own words.
     */
    private function withoutEcho(string $text, string $known): string
    {
        $question = mb_strtolower($this->question);
        $answer   = mb_strtolower($text);
        $length   = mb_strlen($question);
        $echoes   = [];

        for ($start = 0; $start + self::ECHO_LENGTH <= $length; $start++) {
            $size = self::ECHO_LENGTH;

            if (!str_contains($answer, mb_substr($question, $start, $size))) {
                continue;
            }

            while ($start + $size < $length && str_contains($answer, mb_substr($question, $start, $size + 1))) {
                $size++;
            }

            $echo = trim(mb_substr($question, $start, $size));

            // Text the pages hold is the site's own; and a stretch of only spaces and punctuation is no echo
            if (mb_strlen($echo) >= self::ECHO_LENGTH && !str_contains($known, $echo) && preg_match('/[\p{L}\p{N}]{3}/u', $echo)
                && ($this->suspect || preg_match(self::TOKEN, $this->question, $token) && str_contains($echo, mb_strtolower($token[0])))) {
                $echoes[] = $echo;
            }

            $start += $size - 1;
        }

        if (!$echoes) {
            return $text;
        }

        // The whole sentence goes, since what is left of it would rarely make sense
        $sentences = preg_split('/(?<=[。！？!?\n])|(?<=\.)(?=\s)/u', $text) ?: [$text];
        $kept      = [];

        foreach ($sentences as $sentence) {
            $lower = mb_strtolower($sentence);

            if (array_filter($echoes, static fn ($echo) => str_contains($lower, $echo))) {
                $this->removed['echo'] = ($this->removed['echo'] ?? 0) + 1;

                // A line break that ended the sentence stays, so what follows keeps its place
                $kept[] = str_ends_with($sentence, "\n") ? "\n" : '';

                continue;
            }

            $kept[] = $sentence;
        }

        return implode('', $kept);
    }

    /**
     * Links may lead to allowed sites, to the sites of the pages the answer is written from, or to
     * an address those pages give; only over http(s).
     *
     * @param  string[]  $hosts
     */
    private function linkAllowed(string $url, string $known, array $hosts): bool
    {
        $address = str_starts_with(strtolower($url), 'www.') ? 'https://' . $url : $url;
        $scheme  = strtolower((string) parse_url($address, PHP_URL_SCHEME));
        $host    = strtolower((string) parse_url($address, PHP_URL_HOST));

        if (!\in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if (\in_array($host, $hosts, true) || str_contains($known, mb_strtolower($url))) {
            return true;
        }

        foreach ($this->allowedHosts as $allowed) {
            $allowed = strtolower(trim($allowed));

            if ($allowed === $host || (str_starts_with($allowed, '*.') && (str_ends_with($host, substr($allowed, 1)) || $host === substr($allowed, 2)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return  string[]  The digits of each phone number the pages give
     */
    private function phoneDigits(string $text): array
    {
        preg_match_all(self::PHONE, $text, $matches);

        return array_values(array_filter(array_map(static fn ($number) => (string) preg_replace('/\D/', '', $number), $matches[0]), static fn ($digits) => \strlen($digits) >= 6));
    }

    private function note(string $kind): string
    {
        $this->removed[$kind] = ($this->removed[$kind] ?? 0) + 1;

        return match ($kind) {
            'link'  => $this->chinese ? '（連結已移除）' : '(link removed)',
            default => $this->chinese ? '（聯絡資訊已移除）' : '(contact details removed)',
        };
    }
}
