<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Ask;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Joomla\Utilities\IpHelper;

\defined('_JEXEC') or die;

/**
 * The component options, with their defaults in one place.
 *
 * @since  0.1.0
 */
final class Settings
{
    public function __construct(private Registry $params)
    {
    }

    public static function load(): self
    {
        return new self(ComponentHelper::getParams('com_mcpask'));
    }

    public function enabled(): bool
    {
        return (bool) $this->params->get('enabled', 1);
    }

    public function apiKey(): string
    {
        return trim((string) $this->params->get('api_key', ''));
    }

    /**
     * Whether questions can be answered at all.
     */
    public function ready(): bool
    {
        return $this->enabled() && $this->apiKey() !== '' && \function_exists('curl_init');
    }

    public function apiBase(): string
    {
        return rtrim(trim((string) $this->params->get('api_base', '')) ?: 'https://api.openai.com/v1', '/');
    }

    /**
     * "responses" (OpenAI) or "chat" (the older chat completions format most compatible services offer).
     */
    public function apiStyle(): string
    {
        return $this->params->get('api_style', 'responses') === 'chat' ? 'chat' : 'responses';
    }

    public function chatModel(): string
    {
        return trim((string) $this->params->get('chat_model', '')) ?: 'gpt-4.1-mini';
    }

    public function reasoningEffort(): string
    {
        return (string) $this->params->get('reasoning_effort', '');
    }

    public function maxOutputTokens(): int
    {
        return $this->int('max_output_tokens', 900, 100, 4000);
    }

    public function embeddingModel(): string
    {
        return trim((string) $this->params->get('embedding_model', '')) ?: 'text-embedding-3-small';
    }

    /**
     * 0 leaves the size to the model.
     */
    public function embeddingDimensions(): int
    {
        return $this->int('embedding_dimensions', 512, 0, 3072);
    }

    public function chunkTokens(): int
    {
        return $this->int('chunk_tokens', 400, 100, 1500);
    }

    public function includeFields(): bool
    {
        return (bool) $this->params->get('include_fields', 1);
    }

    /**
     * @return  string[]  Lower-case names of the only custom fields to read; empty for all that visitors may see
     */
    public function fieldNames(): array
    {
        // A list since the option became a selection; before that, names typed into a text box
        $chosen = $this->params->get('fields_only', []);
        $names  = \is_string($chosen) ? preg_split('/[\s,]+/', $chosen, -1, PREG_SPLIT_NO_EMPTY) : (array) $chosen;

        return array_values(array_unique(array_filter(array_map(static fn ($name) => strtolower(trim((string) $name)), $names ?: []), 'strlen')));
    }

    /**
     * View level a visitor needs to ask questions. The answers always come from public content.
     */
    /**
     * Whether EasyBlog posts are answered from too, when EasyBlog is installed.
     */
    public function includeEasyblog(): bool
    {
        return (bool) $this->params->get('include_easyblog', 1);
    }

    public function accessLevel(): int
    {
        return $this->int('access', 1, 1, PHP_INT_MAX);
    }

    public function indexOnSave(): bool
    {
        return (bool) $this->params->get('index_on_save', 1);
    }

    /**
     * @return  int[]  Empty for all categories
     */
    public function categories(): array
    {
        return array_values(array_filter(array_map('intval', (array) $this->params->get('categories', []))));
    }

    public function topK(): int
    {
        return $this->int('top_k', 8, 1, 20);
    }

    /**
     * How many times the model may search again or read a whole page before it has to answer. 0 answers
     * from the first passages alone.
     */
    public function agentSteps(): int
    {
        return $this->int('agent_steps', 2, 0, 4);
    }

    /**
     * Whether the page a question is asked from counts when working out what it is about.
     */
    public function pageContext(): bool
    {
        return (bool) $this->params->get('page_context', 1);
    }

    public function minScore(): float
    {
        return max(0.0, min(1.0, (float) $this->params->get('min_score', 0.2)));
    }

    /**
     * How close a visitor's question must be to the question of a source rule for the rule to apply.
     */
    public function hintScore(): float
    {
        // Measured with text-embedding-3-small: the same question in other words scores about
        // 0.54 to 0.87, the same kind of question about another product about 0.45, unrelated ones below 0.3
        return min(0.95, max(0.3, (float) $this->params->get('hint_score', 0.5)));
    }

    /** How a how-to question is answered: rules for the model, editable in the options. */
    public const HOWTO_TEMPLATE = "這是在問如何操作。請這樣回答：\n1. 先用一句話說明會完成什麼。\n2. 若來源有前置條件，先列出。\n3. 依來源教學的原始順序列出步驟，用 1. 2. 3. 編號。不可調整順序，不可合併或省略來源中的步驟，每一步句尾標註引用 [n]。\n4. 若來源說明了如何確認完成，最後列出。\n5. 若有「下一份」教學且與問題相關，最後一句提示可以接著看哪一份。\n6. 來源只有部分步驟時，只列出有根據的部分，明確說明哪裡沒有涵蓋，並建議透過網站的支援管道詢問。不可自行補上步驟。\n7. 若問題只問其中某一步，先直接回答那一步，再從第 1 步依序列到那一步，讓訪客知道怎麼走到那裡；之後的步驟不必列出。\n8. 來源某一步附有截圖 ![說明](/路徑) 時，把它原樣放在該步驟之後，路徑一字不改；來源沒有的圖片不可加入。";

    /** Words that make a question a how-to question. */
    public const HOWTO_WORDS = "如何\n怎麼\n怎樣\n步驟\n設定\n教學\n流程\n操作\nhow to\nhow do i\nhow can i\nset up\nsetup\nconfigure\nsteps";

    /**
     * The rules a how-to answer follows; empty switches the template off.
     */
    /**
     * The colours of the module chosen on the Appearance page; empty for its own.
     *
     * @return  array<string, string>
     */
    public function theme(): array
    {
        $colours = json_decode((string) $this->params->get('theme', ''), true);

        return \is_array($colours) && $colours ? Theme::clean($colours) : [];
    }

    public function howtoTemplate(): string
    {
        $value = $this->params->get('howto_template', null);

        return trim($value === null ? self::HOWTO_TEMPLATE : (string) $value);
    }

    /**
     * @return  string[]  Words that make a question a how-to question
     */
    public function howtoWords(): array
    {
        $value = trim((string) $this->params->get('howto_words', '')) ?: self::HOWTO_WORDS;

        return array_values(array_filter(array_map(static fn ($word) => mb_strtolower(trim($word)), preg_split('/\R/u', $value) ?: []), 'strlen'));
    }

    public function instructions(): string
    {
        return trim((string) $this->params->get('instructions', ''));
    }

    public function perMinute(): int
    {
        return $this->int('per_minute', 5, 1, 1000);
    }

    public function perDay(): int
    {
        return $this->int('per_day', 50, 1, 100000);
    }

    /**
     * The limits that apply to a visitor: none for those allowed to ask without limits, otherwise
     * the most generous of the limits set for their user groups (0 meaning no limit), or the
     * general ones when none of their groups has its own. Guests are in the Public group (and
     * the guest group of the user settings).
     *
     * @return  array{minute: int, day: int}|null  Null when no limit applies
     */
    public function limitsFor(User $user): ?array
    {
        if ($user->authorise('mcpask.unlimited', 'com_mcpask')) {
            return null;
        }

        $groups = array_map('intval', $user->getAuthorisedGroups());
        $found  = null;

        foreach ((array) json_decode((string) json_encode($this->params->get('group_limits', [])), true) as $row) {
            if (!\in_array((int) ($row['group'] ?? 0), $groups, true)) {
                continue;
            }

            $limits = ['minute' => max(0, (int) ($row['per_minute'] ?? 0)), 'day' => max(0, (int) ($row['per_day'] ?? 0))];

            // 0 is no limit, so it is the most generous of all
            $found = $found === null ? $limits : [
                'minute' => $found['minute'] === 0 || $limits['minute'] === 0 ? 0 : max($found['minute'], $limits['minute']),
                'day'    => $found['day'] === 0 || $limits['day'] === 0 ? 0 : max($found['day'], $limits['day']),
            ];
        }

        return $found ?? ['minute' => $this->perMinute(), 'day' => $this->perDay()];
    }

    /**
     * Whether this visitor may ask. When no group has been given or refused the permission at
     * all (an installation that predates it), everyone may, as before.
     */
    public function mayAsk(User $user): bool
    {
        if ($user->authorise('mcpask.ask', 'com_mcpask')) {
            return true;
        }

        $rules = Access::getAssetRules('com_mcpask')->getData();

        return !isset($rules['mcpask.ask']) || !$rules['mcpask.ask']->getData();
    }

    public function sitePerDay(): int
    {
        return $this->int('site_per_day', 1000, 1, 10000000);
    }

    /**
     * Whether text that looks like a probe for weaknesses is turned away unanswered.
     */
    public function blockProbes(): bool
    {
        return (bool) $this->params->get('block_probes', 1);
    }

    /**
     * @return  string[]  Words, or patterns between slashes, that a question may not contain
     */
    public function blockedWords(): array
    {
        return $this->lines('blocked_words');
    }

    /**
     * @return  string[]  Addresses and ranges that may not ask
     */
    public function blockedIps(): array
    {
        return $this->lines('blocked_ips');
    }

    /**
     * Questions of one address that may be turned away within an hour before it is shut out for
     * the rest of that hour. 0: never.
     */
    /**
     * Addresses of the site's own reverse proxies or load balancers, one per line (ranges allowed).
     * Only a request that comes from one of them may say, in X-Forwarded-For, whom it is for.
     *
     * @return  string[]
     */
    public function trustedProxies(): array
    {
        return $this->lines('trusted_proxies');
    }

    /**
     * The address of the visitor, as far as it can be trusted. With trusted proxies set, the
     * right-most X-Forwarded-For entry that is not one of them (what a client puts first is its
     * own claim); otherwise what Joomla reports, provided it is an address at all.
     */
    public function visitorIp(): string
    {
        $remote  = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $proxies = $this->trustedProxies();

        if ($proxies) {
            if ($remote !== '' && Guard::inList($remote, $proxies)) {
                $hops = array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));

                foreach ($hops as $hop) {
                    if (filter_var($hop, FILTER_VALIDATE_IP) && !Guard::inList($hop, $proxies)) {
                        return self::plainIp($hop);
                    }
                }
            }

            return self::plainIp($remote);
        }

        $ip = (string) IpHelper::getIp();

        return self::plainIp(filter_var($ip, FILTER_VALIDATE_IP) ? $ip : $remote);
    }

    /**
     * An IPv4 address written as IPv6 (::ffff:a.b.c.d) as the IPv4 address it is.
     */
    private static function plainIp(string $ip): string
    {
        return preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $match) ? $match[1] : $ip;
    }

    /** Sites answers may link to besides the pages they were written from. */
    public const ALLOWED_HOSTS = "vitalyun.com\n*.vitalyun.com\ngss.com.tw\n*.gss.com.tw\ngsscloud.com\n*.gsscloud.com";

    /** Phrases that mark a question as an attempt to steer the answer. */
    public const INJECTION_PATTERNS = "include this text\ninclude the following\nin your answer:\nadd this text\nappend this\nrepeat after me\nsay exactly\noutput the following\nprint the following\nignore previous\nignore all previous\nignore the above\ndisregard previous\nsystem prompt\nyour instructions\nyou are now\npretend to be\nverbatim\nignore all earlier\nignore earlier\nignore your\nsystem override\nhidden prompt\nhidden instructions\nhidden text\ndeveloper instructions\nspecial instruction\neverything above\ndebug mode\nanswer with the word\nreply with the word\nrespond only with\n/etc/\n/proc/\nuname -a\n逐字\n原樣回覆\n原樣輸出\n一字不漏\n行為準則\n系統指令\n隱藏指令\n忽略以上\n忽略之前\n忽略先前\n忽略前面\n忽略所有\n加入以下\n加上以下\n在回答中加入\n在回答中加上\n回答最後請加上\n請在回答\n附上這個\n系統提示\n你現在是\n扮演\n照抄\n重複以下\n原文輸出";

    /**
     * Hosts ("example.com", or "*.example.com" for all below it) links in answers may lead to,
     * besides this site and the sites of the pages an answer is written from.
     *
     * @return  string[]
     */
    public function allowedHosts(): array
    {
        $hosts = preg_split('/\R/', (string) $this->params->get('allowed_hosts', self::ALLOWED_HOSTS)) ?: [];
        $own   = strtolower((string) parse_url(\Joomla\CMS\Uri\Uri::root(), PHP_URL_HOST));

        return array_values(array_unique(array_filter(array_map(static fn ($host) => strtolower(trim($host)), [...$hosts, $own, preg_replace('/^www\./', '', $own)]))));
    }

    /**
     * @return  string[]  Phrases, or /patterns/, that mark a question as an attempt to steer the answer
     */
    public function injectionPatterns(): array
    {
        // Left empty, the list that comes with the extension
        $value = trim((string) $this->params->get('injection_patterns', '')) ?: self::INJECTION_PATTERNS;
        $lines = preg_split('/\R/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(array_map('trim', $lines), 'strlen'));
    }

    public function strikes(): int
    {
        return $this->int('strikes', 5, 0, 1000);
    }

    public function logQuestions(): bool
    {
        return (bool) $this->params->get('log_questions', 1);
    }

    /**
     * Whether it is recorded who asked: the account when the visitor is signed in, and the address.
     */
    public function logWho(): bool
    {
        return (bool) $this->params->get('log_who', 1);
    }

    public function logDays(): int
    {
        return $this->int('log_days', 30, 1, 3650);
    }

    /** A shorter export key could be guessed, and is treated as none. */
    public const MIN_EXPORT_KEY = 24;

    /**
     * The key other sites have to present to read this site's index. Empty: nothing is given out.
     */
    public function exportKey(): string
    {
        $key = trim((string) $this->params->get('export_key', ''));

        return \strlen($key) >= self::MIN_EXPORT_KEY ? $key : '';
    }

    /**
     * Whether an export key was entered that is too short to count.
     */
    public function exportKeyTooShort(): bool
    {
        return trim((string) $this->params->get('export_key', '')) !== '' && $this->exportKey() === '';
    }

    /**
     * The other sites whose public content is answered from as well.
     *
     * @return  array<string, array{id: string, name: string, url: string, key: string}>  By ID
     */
    public function peers(): array
    {
        $peers = [];

        foreach ((array) json_decode((string) json_encode($this->params->get('peers', [])), true) as $row) {
            $url = rtrim(trim((string) ($row['url'] ?? '')), '/');

            if ($url === '' || !($row['enabled'] ?? 1) || !preg_match('#^https?://#i', $url)) {
                continue;
            }

            // The ID is what the stored pages are filed under, so it must not change with the name
            $id         = substr(sha1(strtolower($url)), 0, 10);
            $peers[$id] = [
                'id'   => $id,
                'name' => trim((string) ($row['name'] ?? '')) ?: (string) parse_url($url, PHP_URL_HOST),
                'url'  => $url,
                'key'  => trim((string) ($row['key'] ?? '')),
            ];
        }

        return $peers;
    }

    /**
     * Websites the administrator knows, read from their RSS or Atom feed or their sitemap.
     *
     * @return  array<string, array{id: string, name: string, url: string, selector: string, limit: int, depth: int}>  By ID
     */
    public function websites(): array
    {
        $sites = [];

        foreach ((array) json_decode((string) json_encode($this->params->get('websites', [])), true) as $row) {
            $url = trim((string) ($row['url'] ?? ''));

            if ($url === '' || !($row['enabled'] ?? 1) || !preg_match('#^https?://[^/]#i', $url)) {
                continue;
            }

            // Filed under the address of the list, so renaming a site keeps what was read
            $id         = substr(sha1(strtolower($url)), 0, 10);
            $sites[$id] = [
                'id'       => $id,
                'name'     => trim((string) ($row['name'] ?? '')) ?: (string) parse_url($url, PHP_URL_HOST),
                'url'      => $url,
                'selector' => trim((string) ($row['selector'] ?? '')),
                'limit'    => max(1, min(2000, (int) ($row['limit'] ?? 200) ?: 200)),
                // How many links away from the given page a crawl goes, when there is no feed or sitemap
                'depth'    => max(1, min(6, (int) ($row['depth'] ?? 3) ?: 3)),
            ];
        }

        return $sites;
    }

    /** Seconds everyone goes straight to the backup service after the main one failed. */
    public const FAILOVER_SECONDS = 60;

    /** Name of the note that the main service failed, and when everyone may try it again. */
    public const FAILOVER_STATE = 'ai:failover';

    private ?OpenAiClient $client = null;

    /**
     * Whether a backup service is set up, for when the main one is down.
     */
    public function fallbackReady(): bool
    {
        return (bool) $this->params->get('fallback_enabled', 0) && $this->fallbackKey() !== '';
    }

    public function fallbackBase(): string
    {
        return rtrim(trim((string) $this->params->get('fallback_api_base', '')) ?: 'https://api.openai.com/v1', '/');
    }

    public function fallbackKey(): string
    {
        return trim((string) $this->params->get('fallback_api_key', ''));
    }

    /**
     * "responses" (OpenAI) or "chat"; the backup may use another format than the main service.
     */
    public function fallbackStyle(): string
    {
        return $this->params->get('fallback_api_style', 'responses') === 'chat' ? 'chat' : 'responses';
    }

    public function fallbackChatModel(): string
    {
        return trim((string) $this->params->get('fallback_chat_model', '')) ?: 'gpt-4.1-mini';
    }

    /**
     * Whether embeddings may come from the backup too: only right when it offers the very same
     * embedding model (name and size) as the main service, or the vectors do not compare.
     */
    public function fallbackEmbeddings(): bool
    {
        return (bool) $this->params->get('fallback_embeddings', 0);
    }

    /**
     * The AI service, one for the whole request: once it has turned to the backup, it stays there.
     */
    public function client(): OpenAiClient
    {
        if ($this->client) {
            return $this->client;
        }

        $this->client = new OpenAiClient($this->apiBase(), $this->apiKey());

        if (!$this->fallbackReady()) {
            return $this->client;
        }

        $this->client->fallBackTo(
            new OpenAiClient($this->fallbackBase(), $this->fallbackKey()),
            $this->fallbackChatModel(),
            $this->fallbackEmbeddings(),
            static function (string $reason): void {
                // The next visitors do not each wait for the main service to fail first
                try {
                    (new State(Factory::getContainer()->get(DatabaseInterface::class)))->set(self::FAILOVER_STATE, [
                        'until'  => time() + self::FAILOVER_SECONDS,
                        'at'     => Factory::getDate()->toSql(),
                        'reason' => mb_substr($reason, 0, 300),
                    ]);
                } catch (\Throwable) {
                    // Without the note each request finds out for itself
                }
            },
            $this->fallbackStyle()
        );

        try {
            $failover = (new State(Factory::getContainer()->get(DatabaseInterface::class)))->get(self::FAILOVER_STATE);

            if ((int) ($failover['until'] ?? 0) > time()) {
                $this->client->switchNow((string) ($failover['reason'] ?? 'The main service failed moments ago.'));
            }
        } catch (\Throwable) {
            // The main service is tried first
        }

        return $this->client;
    }

    /**
     * Everything that changes what is stored for a page: when one of these changes, pages are indexed again.
     */
    public function indexSignature(): string
    {
        return implode('|', [
            $this->embeddingModel(), $this->embeddingDimensions(), $this->chunkTokens(),
            (int) $this->includeFields(), implode(',', $this->fieldNames()),
        ]);
    }

    /**
     * @return  string[]  The lines of a text option that are not empty
     */
    private function lines(string $name): array
    {
        $lines = preg_split('/\R/u', (string) $this->params->get($name, ''), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(array_map('trim', $lines ?: []), 'strlen'));
    }

    private function int(string $name, int $default, int $min, int $max): int
    {
        $value = $this->params->get($name, '');

        return max($min, min($max, $value === '' || $value === null ? $default : (int) $value));
    }
}
