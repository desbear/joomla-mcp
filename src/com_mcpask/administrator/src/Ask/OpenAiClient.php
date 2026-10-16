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
 * The calls this component makes to OpenAI or a compatible service: embeddings, and a streamed
 * answer during which the model may ask for tools to be run. cURL is used directly because
 * Joomla's HTTP client cannot read a response while it arrives.
 *
 * @since  0.1.0
 */
final class OpenAiClient
{
    /** Largest error body kept from the service. */
    private const MAX_ERROR_BYTES = 65536;

    /** Largest response taken from the service (embeddings of a whole batch fit easily). */
    private const MAX_RESPONSE_BYTES = 33554432;

    /** Longest line of a stream: an event that never ends is not waited for. */
    private const MAX_LINE_BYTES = 1048576;

    /** Tools run for the model in one round; what it asks for beyond that is declined. */
    private const MAX_CALLS = 3;

    /** HTTP statuses that say the service is down or busy rather than that the request was wrong. */
    private const TEMPORARY = [408, 409, 425, 429, 500, 502, 503, 504, 520, 521, 522, 523, 524, 529];

    /** Another service to turn to when this one is down, and how. */
    private ?OpenAiClient $fallback = null;

    private string $fallbackModel = '';

    /** The API format of the other service, which may differ from this one's: "responses" or "chat". */
    private string $fallbackStyle = '';

    private bool $fallbackEmbeddings = false;

    /** @var callable(string): void|null */
    private $onSwitch = null;

    /** Why this request turned to the other service, once it has. */
    private string $switched = '';

    public function __construct(private string $base, private string $key)
    {
    }

    /**
     * Turn to another service when this one is down: for answers always (with its own chat model),
     * for embeddings only when told so, because vectors are only comparable when they come from
     * the same model.
     *
     * @param  callable(string): void  $onSwitch  Told why, when it happens
     */
    public function fallBackTo(OpenAiClient $fallback, string $chatModel, bool $embeddings, callable $onSwitch, string $style = ''): void
    {
        $this->fallback           = $fallback;
        $this->fallbackModel      = $chatModel;
        $this->fallbackStyle      = $style;
        $this->fallbackEmbeddings = $embeddings;
        $this->onSwitch           = $onSwitch;
    }

    /**
     * Use the other service from now on, without trying this one first (it failed moments ago).
     */
    public function switchNow(string $reason): void
    {
        if ($this->fallback) {
            $this->switched = $reason;
        }
    }

    /**
     * Why the other service was used, or empty when it was not.
     */
    public function usedFallback(): string
    {
        return $this->switched;
    }

    private function canFallBack(ServiceException $e, bool $embeddings = false): bool
    {
        return $this->fallback !== null && $e->temporary && !$e->partial && (!$embeddings || $this->fallbackEmbeddings);
    }

    private function switchOver(ServiceException $e): void
    {
        if ($this->switched === '') {
            $this->switched = $e->getMessage();

            if ($this->onSwitch) {
                ($this->onSwitch)($e->getMessage());
            }
        }
    }

    /**
     * @param   string[]  $inputs
     *
     * @return  array<int, float[]>  One vector per input, in the same order
     */
    public function embeddings(string $model, array $inputs, int $dimensions): array
    {
        if ($this->switched !== '' && $this->fallbackEmbeddings) {
            return $this->fallback->embeddings($model, $inputs, $dimensions);
        }

        try {
            return $this->requestEmbeddings($model, $inputs, $dimensions);
        } catch (ServiceException $e) {
            if (!$this->canFallBack($e, true)) {
                throw $e;
            }

            $this->switchOver($e);

            return $this->fallback->embeddings($model, $inputs, $dimensions);
        }
    }

    /**
     * @param   string[]  $inputs
     *
     * @return  array<int, float[]>
     */
    private function requestEmbeddings(string $model, array $inputs, int $dimensions): array
    {
        $payload = ['model' => $model, 'input' => array_values($inputs), 'encoding_format' => 'float'];

        if ($dimensions > 0) {
            $payload['dimensions'] = $dimensions;
        }

        $curl     = $this->open('/embeddings', $payload, 30);
        $received = '';

        // However much the service sends, only so much is taken in
        curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function ($curl, string $data) use (&$received): int {
            $received .= $data;

            return \strlen($received) > self::MAX_RESPONSE_BYTES ? 0 : \strlen($data);
        });

        $body   = curl_exec($curl) === false && $received === '' ? false : $received;
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($curl);

        curl_close($curl);

        if ($body === false) {
            throw new ServiceException('Could not reach the AI service: ' . $error, true);
        }

        if ($status >= 400) {
            throw new ServiceException(self::errorMessage((string) $body, $status), \in_array($status, self::TEMPORARY, true));
        }

        $vectors = [];

        foreach ((array) (json_decode((string) $body, true)['data'] ?? []) as $row) {
            if (isset($row['index']) && \is_array($row['embedding'] ?? null)) {
                $vectors[(int) $row['index']] = $row['embedding'];
            }
        }

        if (\count($vectors) !== \count($inputs)) {
            throw new ServiceException('The AI service returned an unexpected embeddings response.');
        }

        ksort($vectors);

        return array_values($vectors);
    }

    /**
     * Let the model answer, running the tools it asks for in between, and hand over the text of
     * the answer as it arrives.
     *
     * @param   string                                             $style     "responses" or "chat"
     * @param   array<int, array{role: string, content: string}>   $messages  The conversation, oldest first
     * @param   array<int, array{name: string, description: string, parameters: array}>  $tools
     * @param   int                                                $rounds    Times the model may ask for tools before it has to answer
     * @param   callable(string): bool                             $onText    Returns false to stop (the visitor has gone)
     * @param   callable(string, array): ?string                   $onCall    Runs a tool and returns what the model is told,
     *                                                                        or null to stop
     *
     * @return  array{input: int, output: int}  Tokens used over all requests, when the service reports them
     */
    public function converse(
        string $style,
        string $model,
        string $instructions,
        array $messages,
        array $tools,
        int $rounds,
        int $maxTokens,
        string $effort,
        callable $onText,
        callable $onCall
    ): array {
        $other = fn () => $this->fallback->converse($this->fallbackStyle ?: $style, $this->fallbackModel ?: $model, $instructions, $messages, $tools, $rounds, $maxTokens, $effort, $onText, $onCall);

        if ($this->switched !== '') {
            return $other();
        }

        // Whether the visitor has seen any of the answer, over all rounds
        $emitted = false;
        $shown   = static function (string $text) use (&$emitted, $onText): bool {
            $emitted = true;

            return $onText($text);
        };

        try {
            return $this->rounds($style, $model, $instructions, $messages, $tools, $rounds, $maxTokens, $effort, $shown, $onCall);
        } catch (ServiceException $e) {
            if (!$this->canFallBack(new ServiceException($e->getMessage(), $e->temporary, $e->partial || $emitted))) {
                throw $e;
            }

            // The other service starts the conversation over, in its own format: the rounds so far were
            // written in this service's format, which the other may not read
            $this->switchOver($e);

            return $other();
        }
    }

    /**
     * The rounds of one conversation with this service alone; see converse().
     *
     * @return  array{input: int, output: int}
     */
    private function rounds(
        string $style,
        string $model,
        string $instructions,
        array $messages,
        array $tools,
        int $rounds,
        int $maxTokens,
        string $effort,
        callable $onText,
        callable $onCall
    ): array {
        $usage = ['input' => 0, 'output' => 0];

        for ($round = 0; ; $round++) {
            // After the last round the tools stay known, so the earlier calls still make sense, but may not be used
            $final  = !$tools || $round >= $rounds;
            $result = $this->request($style, $model, $instructions, $messages, $maxTokens, $effort, $onText, $tools, $final ? 'none' : 'auto');

            $usage['input']  += $result['input'];
            $usage['output'] += $result['output'];

            if ($final || !$result['calls'] || $result['stopped']) {
                return $usage;
            }

            // What the model said and asked for goes back to it, followed by what the tools returned
            if ($style === 'chat') {
                $messages[] = [
                    'role'       => 'assistant',
                    'content'    => $result['text'] !== '' ? $result['text'] : null,
                    'tool_calls' => array_map(static fn ($call) => [
                        'id'       => $call['id'],
                        'type'     => 'function',
                        'function' => ['name' => $call['name'], 'arguments' => $call['arguments']],
                    ], $result['calls']),
                ];
            } else {
                $messages = array_merge($messages, $result['items']);
            }

            foreach ($result['calls'] as $number => $call) {
                // Every call has to be answered, but only the first few are worth their cost
                $output = $number < self::MAX_CALLS
                    ? $onCall($call['name'], (array) json_decode($call['arguments'], true))
                    : 'Not run: too many requests at once. Answer with what you have.';

                if ($output === null) {
                    return $usage;
                }

                $messages[] = $style === 'chat'
                    ? ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => $output]
                    : ['type' => 'function_call_output', 'call_id' => $call['id'], 'output' => $output];
            }
        }
    }

    /**
     * One request for an answer, handing over its text as it arrives.
     *
     * @param   array                    $messages    The conversation, in the format of the chosen API
     * @param   callable(string): bool   $onText      Returns false to stop (the visitor has gone)
     * @param   array                    $tools       See converse()
     * @param   string                   $toolChoice  "auto" or "none"
     * @param   int                      $timeout     Seconds the whole request may take
     *
     * @return  array{input: int, output: int, text: string, calls: array<int, array{id: string, name: string, arguments: string}>, items: array, stopped: bool}
     */
    public function stream(
        string $style,
        string $model,
        string $instructions,
        array $messages,
        int $maxTokens,
        string $effort,
        callable $onText,
        array $tools = [],
        string $toolChoice = 'auto',
        int $timeout = 90
    ): array {
        // The other service answers in the same format, with its own model
        // A single request carries plain messages, which both formats read alike
        if ($this->switched !== '') {
            return $this->fallback->stream($this->fallbackStyle ?: $style, $this->fallbackModel ?: $model, $instructions, $messages, $maxTokens, $effort, $onText, $tools, $toolChoice, $timeout);
        }

        try {
            return $this->request($style, $model, $instructions, $messages, $maxTokens, $effort, $onText, $tools, $toolChoice, $timeout);
        } catch (ServiceException $e) {
            // Not once the visitor has seen part of the answer: it would start over in front of them
            if (!$this->canFallBack($e)) {
                throw $e;
            }

            $this->switchOver($e);

            return $this->fallback->stream($this->fallbackStyle ?: $style, $this->fallbackModel ?: $model, $instructions, $messages, $maxTokens, $effort, $onText, $tools, $toolChoice, $timeout);
        }
    }

    /**
     * One request to this service; see stream().
     */
    private function request(
        string $style,
        string $model,
        string $instructions,
        array $messages,
        int $maxTokens,
        string $effort,
        callable $onText,
        array $tools = [],
        string $toolChoice = 'auto',
        int $timeout = 90
    ): array {
        // Whether any of the answer has been handed over, which decides whether another service may take over
        $emitted = false;
        $onText  = static function (string $text) use (&$emitted, $onText): bool {
            $emitted = true;

            return $onText($text);
        };

        if ($style === 'chat') {
            $path    = '/chat/completions';
            $payload = [
                'model'                 => $model,
                'messages'              => array_merge([['role' => 'system', 'content' => $instructions]], $messages),
                'stream'                => true,
                'stream_options'        => ['include_usage' => true],
                'max_completion_tokens' => $maxTokens,
            ];

            if ($effort !== '') {
                $payload['reasoning_effort'] = $effort;
            }

            if ($tools) {
                $payload['tool_choice'] = $toolChoice;
                $payload['tools']       = array_map(static fn ($tool) => ['type' => 'function', 'function' => $tool + ['strict' => true]], $tools);
            }
        } else {
            $path    = '/responses';
            $payload = [
                'model'             => $model,
                'instructions'      => $instructions,
                'input'             => $messages,
                'stream'            => true,
                'store'             => false,
                'max_output_tokens' => $maxTokens,
            ];

            if ($effort !== '') {
                $payload['reasoning'] = ['effort' => $effort];
            }

            if ($tools) {
                $payload['tool_choice'] = $toolChoice;
                $payload['tools']       = array_map(static fn ($tool) => ['type' => 'function'] + $tool + ['strict' => true], $tools);
            }

            if ($tools && $effort !== '') {
                // Nothing is stored at the service, so a reasoning model's reasoning has to travel with the tool calls
                $payload['include'] = ['reasoning.encrypted_content'];
            }
        }

        $usage   = ['input' => 0, 'output' => 0];
        $buffer  = '';
        $raw     = '';
        $answer  = '';
        $calls   = [];
        $items   = [];
        $failure = null;
        $stopped = false;

        $handle = static function (array $event) use (&$usage, &$failure, &$stopped, &$answer, &$calls, &$items, $onText): void {
            $text = null;

            if (isset($event['choices'])) {
                // Chat completions: a tool call arrives in pieces, numbered by its index
                $text = $event['choices'][0]['delta']['content'] ?? null;

                foreach ((array) ($event['choices'][0]['delta']['tool_calls'] ?? []) as $piece) {
                    $index         = (int) ($piece['index'] ?? 0);
                    $calls[$index] = [
                        'id'        => ($calls[$index]['id'] ?? '') . ($piece['id'] ?? ''),
                        'name'      => ($calls[$index]['name'] ?? '') . ($piece['function']['name'] ?? ''),
                        'arguments' => ($calls[$index]['arguments'] ?? '') . ($piece['function']['arguments'] ?? ''),
                    ];
                }
            } elseif (($event['type'] ?? '') === 'response.output_text.delta') {
                $text = $event['delta'] ?? null;
            } elseif (($event['type'] ?? '') === 'response.output_item.done') {
                $item = (array) ($event['item'] ?? []);

                if (($item['type'] ?? '') === 'function_call') {
                    $calls[] = ['id' => (string) ($item['call_id'] ?? ''), 'name' => (string) ($item['name'] ?? ''), 'arguments' => (string) ($item['arguments'] ?? '')];
                    $items[] = ['type' => 'function_call', 'call_id' => $item['call_id'] ?? '', 'name' => $item['name'] ?? '', 'arguments' => $item['arguments'] ?? ''];
                } elseif (($item['type'] ?? '') === 'reasoning' && !empty($item['encrypted_content'])) {
                    $items[] = $item;
                }
            } elseif (($event['type'] ?? '') === 'response.failed') {
                $failure = (string) ($event['response']['error']['message'] ?? 'The AI service could not complete the answer.');
            } elseif (($event['type'] ?? '') === 'error') {
                $failure = (string) ($event['message'] ?? $event['error']['message'] ?? 'The AI service reported an error.');
            }

            if (isset($event['error']['message']) && !isset($event['type'])) {
                $failure = (string) $event['error']['message'];
            }

            $reported = $event['usage'] ?? $event['response']['usage'] ?? null;

            if (\is_array($reported)) {
                $usage = [
                    'input'  => (int) ($reported['input_tokens'] ?? $reported['prompt_tokens'] ?? 0),
                    'output' => (int) ($reported['output_tokens'] ?? $reported['completion_tokens'] ?? 0),
                ];
            }

            if (\is_string($text) && $text !== '') {
                $answer .= $text;

                if (!$onText($text)) {
                    $stopped = true;
                }
            }
        };

        $curl = $this->open($path, $payload, $timeout);

        curl_setopt($curl, CURLOPT_HTTPHEADER, array_merge($this->headers(), ['Accept: text/event-stream']));
        curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function ($curl, string $data) use (&$buffer, &$raw, &$stopped, $handle): int {
            if (curl_getinfo($curl, CURLINFO_RESPONSE_CODE) >= 400) {
                // An error is a plain JSON body, not a stream
                $raw = substr($raw . $data, 0, self::MAX_ERROR_BYTES);

                return \strlen($data);
            }

            $buffer .= $data;

            if (\strlen($buffer) > self::MAX_LINE_BYTES && !str_contains($buffer, "\n")) {
                return 0;
            }

            while (($end = strpos($buffer, "\n")) !== false) {
                $line   = rtrim(substr($buffer, 0, $end), "\r");
                $buffer = substr($buffer, $end + 1);

                if (!str_starts_with($line, 'data:')) {
                    continue;
                }

                $event = json_decode(trim(substr($line, 5)), true);

                if (\is_array($event)) {
                    $handle($event);
                }

                if ($stopped) {
                    // Ends the transfer, so no more of the answer is generated or paid for
                    return 0;
                }
            }

            return \strlen($data);
        });

        curl_exec($curl);

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($curl);
        $error  = curl_error($curl);

        curl_close($curl);

        $result = $usage + ['text' => $answer, 'calls' => array_values($calls), 'items' => $items, 'stopped' => $stopped];

        if ($stopped) {
            return $result;
        }

        // A reasoning effort is only understood by reasoning models. The request was refused before
        // anything was written, so it can simply be sent again without it.
        if ($status === 400 && $effort !== '' && stripos($raw, 'reasoning') !== false) {
            return $this->request($style, $model, $instructions, $messages, $maxTokens, '', $onText, $tools, $toolChoice, $timeout);
        }

        if ($status >= 400) {
            throw new ServiceException(self::errorMessage($raw, $status), \in_array($status, self::TEMPORARY, true), $emitted);
        }

        if ($errno !== 0) {
            throw new ServiceException('Could not reach the AI service: ' . $error, true, $emitted);
        }

        if ($failure !== null) {
            // An error the service reports in the middle of the stream: it broke down, the request was fine
            throw new ServiceException($failure, true, $emitted);
        }

        return $result;
    }

    /**
     * @return  \CurlHandle
     */
    private function open(string $path, array $payload, int $timeout): object
    {
        // The key travels in a header: never over an unencrypted connection, except to this machine itself
        $scheme = strtolower((string) parse_url($this->base, PHP_URL_SCHEME));
        $host   = strtolower(trim((string) parse_url($this->base, PHP_URL_HOST), '[]'));

        if ($scheme !== 'https' && !($scheme === 'http' && \in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
            throw new ServiceException('The service address must start with https://.');
        }

        $curl = curl_init($this->base . $path);

        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER     => $this->headers(),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        return $curl;
    }

    /**
     * @return  string[]
     */
    private function headers(): array
    {
        return ['Content-Type: application/json', 'Authorization: Bearer ' . $this->key];
    }

    private static function errorMessage(string $body, int $status): string
    {
        $message = json_decode($body, true)['error']['message'] ?? null;

        // Some services quote the key they were sent: it is never kept or shown
        $message = \is_string($message) ? (string) preg_replace('/\b(sk-[A-Za-z0-9]{0,4})[A-Za-z0-9_\-]{8,}|\b(Bearer\s+)\S+/i', '$1$2…', mb_substr($message, 0, 500)) : 'no details given';

        return \sprintf('The AI service answered with HTTP %d: %s', $status, $message);
    }
}
