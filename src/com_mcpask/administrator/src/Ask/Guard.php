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
 * Turns away what is not a question before anything is paid for: addresses the administrator
 * has shut out, and text that is a probe for weaknesses rather than something to answer.
 *
 * None of that text can harm this component (it is never run, and always escaped), but each
 * question costs an embedding and an answer, and probes come by the hundred.
 *
 * @since  0.2.2
 */
final class Guard
{
    /**
     * What probes look like. Each is a pattern for the whole question, tried without regard to case.
     */
    private const PROBES = [
        // Markup and scripts
        '/<\s*\/?\s*[a-z!][^>]*>/i',
        '/\b(javascript|vbscript)\s*:|data\s*:\s*text\/html/i',
        '/\bon(error|load|click|mouseover|focus|toggle|animationstart)\s*=/i',
        // Database statements
        '/\bunion\s+(all\s+)?select\b|\bselect\b.{1,80}\bfrom\b.{0,40}(information_schema|mysql\.|pg_|sqlite_|users?\b)/i',
        '/\b(sleep|benchmark|pg_sleep|waitfor\s+delay|load_file|xp_cmdshell)\s*[\s(]/i',
        '/[\'"]\s*(or|and)\s*[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+/i',
        '/\b(drop|truncate)\s+table\b|\binsert\s+into\b.{1,60}\bvalues\b/i',
        // Template expressions
        '/\{\{.{0,200}\}\}|\$\{[^}]{0,200}\}|#\{[^}]{0,200}\}|<%|%>/s',
        // Files of the server
        '/\.\.[\/\\\\]|\/etc\/(passwd|shadow|hosts)|\/proc\/self|\bboot\.ini\b|\bwin\.ini\b|configuration\.php/i',
        // Commands, alone or chained on
        '/^\s*(whoami|id|ls|dir|pwd|uname(\s+-\w+)?|hostname|ifconfig|ipconfig|env|printenv|phpinfo\s*\(\s*\)|(cat|type|ping|nslookup|dig|curl|wget)\s+\S+)\s*;?\s*$/i',
        '/[;&|`]\s*(whoami|id|ls|cat|curl|wget|nc|ncat|bash|sh|cmd|powershell|ping|nslookup)\b|\$\([^)]{1,200}\)|`[^`]{1,200}`/i',
        // An address and nothing else, a sum and nothing else, one character over and over (the
        // last QUESTION_ONLY patterns: not a question, but a fair value of a form's field)
        '/^\s*(https?|ftp|file|gopher|dict|ldap):\/\/\S+\s*$/i',
        '/^[\s\d()+\-*\/.=]+$/',
        '/(.)\1{29,}/us',
    ];

    /** The last patterns of PROBES, which mark what is not a question rather than an attack. */
    private const QUESTION_ONLY = 3;

    public function __construct(private Settings $settings)
    {
    }

    /**
     * Whether the administrator has shut this address out.
     */
    /**
     * Marks for the question log, so attempts can be watched for: "injection_suspect" when the
     * text tries to steer the answer (the administrator's list of phrases and patterns),
     * "html_payload" when it carries markup or script.
     *
     * @return  string[]
     */
    public function flags(string $text): array
    {
        $flags = [];
        $lower = mb_strtolower($text);

        foreach ($this->settings->injectionPatterns() as $pattern) {
            $hit = \strlen($pattern) > 2 && $pattern[0] === '/' && preg_match('#^/.*/[a-z]*$#is', $pattern)
                ? @preg_match($pattern, $text) === 1
                : str_contains($lower, mb_strtolower($pattern));

            if ($hit) {
                $flags[] = 'injection_suspect';

                break;
            }
        }

        if (preg_match('/<\s*[a-z!\/][^>]*>|<\s*(?:script|img|svg|iframe)\b|\bon[a-z]{3,}\s*=|javascript\s*:|data\s*:\s*text\/html/i', $text)) {
            $flags[] = 'html_payload';
        }

        return $flags;
    }

    public function banned(string $ip): bool
    {
        $list = $this->settings->blockedIps();

        return $ip !== '' && $list && self::inList($ip, $list);
    }

    /**
     * Whether an address is in a list of addresses, CIDR ranges (10.0.0.0/8, 2001:db8::/32) and
     * dash ranges (10.0.0.1-10.0.0.9).
     *
     * @param  string[]  $list
     */
    public static function inList(string $ip, array $list): bool
    {
        $address = @inet_pton($ip);

        if ($address === false) {
            return false;
        }

        foreach ($list as $entry) {
            $entry = trim($entry);

            if (str_contains($entry, '/')) {
                [$network, $bits] = explode('/', $entry, 2);
                $network          = @inet_pton(trim($network));
                $bits             = (int) $bits;

                if ($network === false || \strlen($network) !== \strlen($address) || $bits < 0 || $bits > 8 * \strlen($address)) {
                    continue;
                }

                $bytes = intdiv($bits, 8);
                $rest  = $bits % 8;

                if (substr($address, 0, $bytes) === substr($network, 0, $bytes)
                    && ($rest === 0 || ((\ord($address[$bytes]) ^ \ord($network[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0)) {
                    return true;
                }
            } elseif (str_contains($entry, '-')) {
                [$from, $to] = array_map(static fn ($part) => @inet_pton(trim($part)), explode('-', $entry, 2));

                if ($from !== false && $to !== false && \strlen($from) === \strlen($address) && \strlen($to) === \strlen($address) && $address >= $from && $address <= $to) {
                    return true;
                }
            } elseif (@inet_pton($entry) === $address) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a question is one to turn away.
     *
     * @return  string|null  Null for a question to answer; otherwise what it was caught by, for the log
     */
    /**
     * Like refuses(), for what is filled in a form's field: a date, a range of numbers or a web
     * address alone is a fair value there; markup, scripts, statements and commands are not.
     */
    public function refusesValue(string $value): ?string
    {
        return $this->refuses($value, false);
    }

    public function refuses(string $question, bool $asQuestion = true): ?string
    {
        foreach ($this->settings->blockedWords() as $word) {
            // A line between slashes is a pattern of the administrator's own; anything else is looked for as it is
            if (\strlen($word) > 2 && $word[0] === '/' && preg_match('#^/.*/[a-z]*$#is', $word)) {
                if (@preg_match($word, $question) === 1) {
                    return 'word: ' . $word;
                }
            } elseif (mb_stripos($question, $word) !== false) {
                return 'word: ' . $word;
            }
        }

        if ($this->settings->blockProbes()) {
            $probes = $asQuestion ? self::PROBES : \array_slice(self::PROBES, 0, -self::QUESTION_ONLY);

            foreach ($probes as $number => $pattern) {
                if (preg_match($pattern, $question) === 1) {
                    return 'probe ' . ($number + 1);
                }
            }
        }

        return null;
    }
}
