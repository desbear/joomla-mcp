<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Mcp;

\defined('_JEXEC') or die;

/**
 * Stateless MCP server (JSON-RPC 2.0 over Streamable HTTP, JSON responses only).
 *
 * Every POST is handled on its own, so no session storage or long running process is needed and
 * it works on ordinary shared hosting.
 *
 * @since  0.1.0
 */
final class Server
{
    public const NAME = 'joomla-mcp';

    public const VERSION = '0.9.2';

    /** Supported protocol revisions, newest first. */
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    /**
     * Most messages accepted in one batch. Every entry in a batch is a full tool call, so without
     * a limit a single request can ask for an unbounded amount of work.
     */
    public const MAX_BATCH = 20;

    /** Most tool calls accepted in one batch, whatever else it contains. */
    public const MAX_BATCH_CALLS = 10;

    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ToolContext $context,
        private readonly ?Logger $logger = null
    ) {
    }

    /**
     * Handle a raw request body.
     *
     * @return  array|null  The JSON-RPC response (or list of responses), null when there is nothing to return
     */
    public function handle(string $body): ?array
    {
        try {
            $message = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::error(null, -32700, 'Parse error');
        }

        if (\is_array($message) && $message !== [] && array_is_list($message)) {
            if (\count($message) > self::MAX_BATCH) {
                return self::error(null, -32600, \sprintf('A batch may contain at most %d messages; send the rest in another request.', self::MAX_BATCH));
            }

            $calls = \count(array_filter($message, fn ($item) => \is_array($item) && ($item['method'] ?? null) === 'tools/call'));

            if ($calls > self::MAX_BATCH_CALLS) {
                return self::error(null, -32600, \sprintf('A batch may contain at most %d tool calls; send the rest in another request.', self::MAX_BATCH_CALLS));
            }

            $responses = array_values(array_filter(array_map(
                fn ($item) => \is_array($item) ? $this->handleMessage($item) : self::error(null, -32600, 'Invalid Request'),
                $message
            )));

            return $responses ?: null;
        }

        if (!\is_array($message)) {
            return self::error(null, -32600, 'Invalid Request');
        }

        return $this->handleMessage($message);
    }

    /**
     * Tools visible to the current user with the current configuration.
     *
     * @return Tool[]
     */
    public function visibleTools(): array
    {
        $params   = $this->context->params;
        $disabled = (array) $params->get('disabled_tools', []);

        return array_filter($this->registry->all(), function (Tool $tool) use ($params, $disabled) {
            if (\in_array($tool->name, $disabled, true)) {
                return false;
            }

            if (!$tool->isReadOnly() && $this->context->isReadOnly()) {
                return false;
            }

            if ($tool->scope === Tool::SCOPE_PUBLISH && !$params->get('allow_publish', 1)) {
                return false;
            }

            if ($tool->scope === Tool::SCOPE_DELETE && !$params->get('allow_delete', 0)) {
                return false;
            }

            if (!$this->context->hasScope($tool->scope)) {
                return false;
            }

            return $tool->isAvailable($this->context);
        });
    }

    private function handleMessage(array $message): ?array
    {
        $id = $message['id'] ?? null;

        // A response or error sent by the client: nothing to answer
        if (!isset($message['method']) && (\array_key_exists('result', $message) || \array_key_exists('error', $message))) {
            return null;
        }

        if (($message['jsonrpc'] ?? null) !== '2.0' || !\is_string($message['method'] ?? null)) {
            return self::error($id, -32600, 'Invalid Request');
        }

        $method = $message['method'];
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];

        // Notifications (no id) never get a response
        if (!\array_key_exists('id', $message)) {
            return null;
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'ping'       => new \stdClass(),
                'tools/list' => ['tools' => array_values(array_map(fn (Tool $t) => $t->toArray(), $this->visibleTools()))],
                'tools/call' => $this->callTool($params),
                default      => throw new JsonRpcException('Method not found: ' . $method, -32601),
            };
        } catch (JsonRpcException $e) {
            return self::error($id, $e->getCode(), $e->getMessage());
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');
        $version   = \in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0];

        return [
            'protocolVersion' => $version,
            'capabilities'    => ['tools' => ['listChanged' => false]],
            'serverInfo'      => [
                'name'    => self::NAME,
                'title'   => 'MCP Server for Joomla',
                'version' => self::VERSION,
            ],
            'instructions' => $this->instructions(),
        ];
    }

    private function instructions(): string
    {
        $app   = $this->context->app;
        $lines = [
            \sprintf('You are connected to the Joomla site "%s" (%s) as user "%s".', $app->get('sitename'), $this->context->siteUrl(), $this->context->user->username),
            'Call site_info first to learn the site languages, your permissions and the server configuration.',
            'Categories and tags can be referenced by ID, title or path; call list_fields before setting custom fields.',
            'Article text is HTML.',
        ];

        if ($this->context->isReadOnly()) {
            $lines[] = 'The server is in read-only mode: content cannot be created or changed.';
        } else {
            $lines[] = 'Before changing or publishing existing content, confirm the change with the user unless they explicitly asked for it.';
        }

        return implode("\n", $lines);
    }

    private function callTool(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $tool = $this->visibleTools()[$name] ?? null;

        if ($tool === null) {
            throw new JsonRpcException('Unknown tool: ' . $name, -32602);
        }

        $arguments = $params['arguments'] ?? [];

        if (!\is_array($arguments)) {
            throw new JsonRpcException('Tool arguments must be an object', -32602);
        }

        $start = microtime(true);

        $detail = '';

        try {
            $arguments = ArgumentValidator::validate($arguments, $tool->inputSchema);
            $result    = $tool->call($arguments, $this->context);
            $isError   = false;
            $text      = \is_string($result) ? $result : self::json($result);
        } catch (ToolException $e) {
            // Written for the model to act on, but it may quote a message from elsewhere
            $isError = true;
            $detail  = $e->getMessage();
            $text    = $this->redact($detail);
        } catch (\Throwable $e) {
            // Database and third party exception messages carry database names, table prefixes,
            // column names and absolute paths. The caller gets a reference it can pass on; the
            // detail stays in the activity log.
            $isError   = true;
            $reference = strtoupper(bin2hex(random_bytes(4)));
            $detail    = \sprintf('[%s] %s: %s (%s:%d)', $reference, $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
            $text      = \sprintf(
                'The tool failed because of an internal error on the site (reference %s). Do not retry; ask the site administrator to look the reference up in Components > MCP Server > Activity Log.',
                $reference
            );
        }

        $this->logger?->log($tool->name, $arguments, !$isError, $detail, (int) round((microtime(true) - $start) * 1000));

        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => $isError,
        ];
    }

    /**
     * Remove what a message from the database layer or a third party extension tends to carry:
     * the database name, the real table prefix, column names and absolute paths. What is left is
     * still a useful sentence for the model.
     */
    private function redact(string $message): string
    {
        $app = $this->context->app;

        foreach ([JPATH_ROOT, \dirname(JPATH_ROOT)] as $path) {
            if ($path !== '' && $path !== '/') {
                $message = str_replace($path, '', $message);
            }
        }

        // The configured database name and table prefix, wherever they appear
        foreach ([(string) $app->get('db') => '<database>', (string) $this->context->db->getPrefix() => '#__'] as $needle => $replacement) {
            if ($needle !== '') {
                $message = str_replace($needle, $replacement, $message);
            }
        }

        // MySQL reports columns as 'database'.'table'.'column' and quotes identifiers in backticks
        $message = (string) preg_replace("/'[^']*'\\.'[^']*'(\\.'[^']*')?/", "'<column>'", $message);
        $message = (string) preg_replace("/'[A-Za-z0-9_\$#]+\\.[A-Za-z0-9_\$#.]+'/", "'<identifier>'", $message);
        $message = (string) preg_replace('/`[^`]*`/', '<identifier>', $message);

        return $message;
    }

    public static function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    public static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
