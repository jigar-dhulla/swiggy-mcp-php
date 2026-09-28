<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp;

use JigarDhulla\SwiggyMcp\Exceptions\AuthenticationException;
use JigarDhulla\SwiggyMcp\Exceptions\InvalidRequestException;
use JigarDhulla\SwiggyMcp\Exceptions\RateLimitedException;
use JigarDhulla\SwiggyMcp\Exceptions\SwiggyException;
use JigarDhulla\SwiggyMcp\Exceptions\ToolException;
use JigarDhulla\SwiggyMcp\Exceptions\TransientException;
use JigarDhulla\SwiggyMcp\Http\HttpClient;
use JigarDhulla\SwiggyMcp\Session\SessionStore;
use JigarDhulla\SwiggyMcp\Support\Uuid;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * A client for one Swiggy MCP server: JSON-RPC 2.0 over streamable HTTP.
 *
 * It keeps one MCP session per token and server (Swiggy counts every
 * `initialize` as an auth event), re-initialises when a session expires,
 * retries transient failures for tools that are safe to repeat, and unwraps
 * Swiggy's `{success, data, message}` envelope into the tool's data.
 *
 * Get one from {@see Swiggy}: `$swiggy->instamart()`.
 */
final class McpClient
{
    public const PROTOCOL_VERSION = '2025-06-18';

    /**
     * @internal use {@see Swiggy} to create clients
     */
    public function __construct(
        private readonly string $url,
        #[SensitiveParameter] private readonly string $accessToken,
        private readonly HttpClient $http,
        private readonly SessionStore $sessions,
        private readonly RetryPolicy $retry,
        private readonly LoggerInterface $logger,
        private readonly string $clientName,
        private readonly string $clientVersion,
    ) {}

    /**
     * Call a tool and return its data.
     *
     * Pass `retryable: false` for tools that must never be repeated blindly,
     * such as `checkout`: a transient failure is then thrown immediately.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws SwiggyException
     */
    public function call(string $tool, array $arguments = [], bool $retryable = true): array
    {
        return $this->retry->run(
            fn (): array => $this->unwrap($tool, $this->send('tools/call', ['name' => $tool, 'arguments' => (object) $arguments])),
            $retryable,
            fn (SwiggyException $exception, int $attempts) => $this->logger->warning('swiggy.mcp.failed', [
                'tool' => $tool,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'attempts' => $attempts,
            ]),
        );
    }

    /**
     * Every tool the server offers, with its description and input schema.
     *
     * @return list<array{name: string, description?: string, inputSchema?: array<string, mixed>}>
     *
     * @throws SwiggyException
     */
    public function tools(): array
    {
        $tools = [];
        $cursor = null;

        do {
            $params = $cursor !== null ? ['cursor' => $cursor] : (object) [];
            $result = (array) ($this->retry->run(fn (): array => $this->send('tools/list', $params))['result'] ?? []);

            foreach ((array) ($result['tools'] ?? []) as $tool) {
                $tools[] = $tool;
            }

            $cursor = is_string($result['nextCursor'] ?? null) && $result['nextCursor'] !== '' ? $result['nextCursor'] : null;
        } while ($cursor !== null);

        return $tools;
    }

    /**
     * Drop the cached MCP session, e.g. after logging out. The next call
     * starts a new one.
     */
    public function forgetSession(): void
    {
        $this->sessions->forget($this->sessionKey());
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * Send one JSON-RPC request and return the response message.
     *
     * @param  array<string, mixed>|object  $params
     * @return array<string, mixed>
     */
    private function send(string $method, array|object $params, bool $freshSession = false): array
    {
        $sessionId = $this->session();

        $response = $this->post($sessionId, [
            'jsonrpc' => '2.0',
            'id' => Uuid::v4(),
            'method' => $method,
            'params' => $params,
        ]);

        // MCP servers answer 404 once a session has expired; start a new one.
        if ($response->getStatusCode() === 404 && $sessionId !== '' && ! $freshSession) {
            $this->forgetSession();

            return $this->send($method, $params, freshSession: true);
        }

        $message = $this->rpcMessage($response);

        $this->reportDeprecation($params, $message);

        return $message;
    }

    /**
     * The MCP session id for this token, initialising one when needed. An
     * empty string means the server runs stateless and issued none.
     */
    private function session(): string
    {
        $sessionId = $this->sessions->get($this->sessionKey());

        if ($sessionId !== null) {
            return $sessionId;
        }

        $response = $this->post('', [
            'jsonrpc' => '2.0',
            'id' => Uuid::v4(),
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => (object) [],
                'clientInfo' => ['name' => $this->clientName, 'version' => $this->clientVersion],
            ],
        ]);

        $this->rpcMessage($response);

        $sessionId = $response->getHeaderLine('Mcp-Session-Id');

        $this->post($sessionId, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $this->sessions->put($this->sessionKey(), $sessionId);

        return $sessionId;
    }

    private function sessionKey(): string
    {
        return hash('sha256', $this->url.'|'.$this->accessToken);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $sessionId, array $payload): ResponseInterface
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->accessToken,
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
        ];

        if ($sessionId !== '') {
            $headers['Mcp-Session-Id'] = $sessionId;
        }

        $startedAt = hrtime(true);

        try {
            $response = $this->http->post($this->url, $payload, $headers);
        } catch (TransientException $exception) {
            $this->logger->warning('swiggy.mcp.call', $this->callContext($payload, $sessionId, $startedAt) + [
                'status' => null,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $context = $this->callContext($payload, $sessionId, $startedAt) + [
            'status' => $response->getStatusCode(),
            'response_session_id' => $response->getHeaderLine('Mcp-Session-Id') ?: null,
            'rate_limit_remaining' => $response->getHeaderLine('X-RateLimit-Remaining') ?: null,
        ];

        if ($response->getStatusCode() < 400) {
            $this->logger->info('swiggy.mcp.call', $context);
        } else {
            $this->logger->warning('swiggy.mcp.call', $context + ['body' => mb_substr((string) $response->getBody(), 0, 500)]);
        }

        return $response;
    }

    /**
     * What Swiggy asks for when a call is escalated: which tool, when, which
     * session and request id, and with what arguments. Never the token.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function callContext(array $payload, string $sessionId, int|float $startedAt): array
    {
        $params = (array) ($payload['params'] ?? []);

        return [
            'url' => $this->url,
            'method' => $payload['method'] ?? null,
            'tool' => $params['name'] ?? null,
            'request_id' => $payload['id'] ?? null,
            'session_id' => $sessionId !== '' ? $sessionId : null,
            'arguments' => (array) ($params['arguments'] ?? []),
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'at' => date(DATE_ATOM),
        ];
    }

    /**
     * Turn an HTTP response into the JSON-RPC message it carries, mapping
     * transport and protocol failures onto exceptions.
     *
     * @return array<string, mixed>
     */
    private function rpcMessage(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 419) {
            throw AuthenticationException::rejected();
        }

        if ($status === 429) {
            $retryAfter = $response->getHeaderLine('Retry-After');

            throw new RateLimitedException(is_numeric($retryAfter) ? (int) $retryAfter : null);
        }

        $message = $this->decodeBody($response);

        if ($status >= 500) {
            throw new TransientException($this->errorText($message) ?? "Swiggy returned HTTP {$status}.");
        }

        if ($status >= 400) {
            throw new InvalidRequestException($this->errorText($message) ?? "Swiggy returned HTTP {$status}.");
        }

        if (isset($message['error'])) {
            $error = (array) $message['error'];
            $code = (int) ($error['code'] ?? 0);
            $text = is_string($error['message'] ?? null) ? $error['message'] : 'Swiggy MCP error.';

            throw match ($code) {
                -32001 => AuthenticationException::rejected(),
                -32603 => new TransientException($text),
                default => new InvalidRequestException($text),
            };
        }

        return $message;
    }

    /**
     * Streamable HTTP may answer with plain JSON or a one-shot SSE stream
     * whose `data:` lines carry the JSON-RPC message.
     *
     * @return array<string, mixed>
     */
    private function decodeBody(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        if (str_contains($response->getHeaderLine('Content-Type'), 'text/event-stream')) {
            $last = [];

            foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
                if (! str_starts_with($line, 'data:')) {
                    continue;
                }

                $message = json_decode(trim(substr($line, 5)), true);

                if (is_array($message) && (isset($message['result']) || isset($message['error']))) {
                    $last = $message;
                }
            }

            return $last;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function errorText(array $message): ?string
    {
        $text = $message['error']['message'] ?? $message['message'] ?? null;

        return is_string($text) && $text !== '' ? $text : null;
    }

    /**
     * Swiggy announces tool and parameter deprecations in `_meta`; surface
     * them in the logs well before the removal date.
     *
     * @param  array<string, mixed>|object  $params
     * @param  array<string, mixed>  $message
     */
    private function reportDeprecation(array|object $params, array $message): void
    {
        $deprecation = $message['result']['_meta']['swiggy']['deprecation']
            ?? $message['result']['_meta']['swiggy.deprecation']
            ?? $message['_meta']['swiggy']['deprecation']
            ?? $message['_meta']['swiggy.deprecation']
            ?? null;

        if (! empty($deprecation)) {
            $this->logger->warning('swiggy.mcp.deprecation', [
                'tool' => ((array) $params)['name'] ?? null,
                'deprecation' => $deprecation,
            ]);
        }
    }

    /**
     * Unwrap a `tools/call` result into the tool's data.
     *
     * The docs describe a `{success, data, message}` envelope, but the live
     * servers often put the data straight into `structuredContent` (with a
     * prose summary in `content`). Both shapes are accepted: anything with a
     * `success` key is treated as the envelope, anything else as the data.
     * A top-level envelope `message` is merged into the data as `message`.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function unwrap(string $tool, array $message): array
    {
        $result = (array) ($message['result'] ?? []);
        $isError = ($result['isError'] ?? false) === true;

        $texts = [];

        foreach ((array) ($result['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $texts[] = $block['text'];
            }
        }

        $text = implode("\n", $texts);

        $payload = $result['structuredContent'] ?? null;

        if (! is_array($payload)) {
            $decoded = json_decode($text, true);
            $payload = is_array($decoded) ? $decoded : null;
        }

        if ($payload === null) {
            if ($isError) {
                throw new ToolException($text !== '' ? $text : 'Swiggy could not complete that request.', $tool);
            }

            return $text !== '' ? ['message' => $text] : [];
        }

        $isEnvelope = array_key_exists('success', $payload);

        if ($isError || ($isEnvelope && $payload['success'] === false)) {
            $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
            $reason = $error['message'] ?? $payload['message'] ?? null;

            throw new ToolException(
                is_string($reason) && $reason !== '' ? $reason : ($text !== '' && ! $isEnvelope ? $text : 'Swiggy could not complete that request.'),
                $tool,
                is_string($error['reportLink'] ?? null) ? $error['reportLink'] : null,
                $payload,
            );
        }

        if (! $isEnvelope) {
            return $payload;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if (is_string($payload['message'] ?? null) && ! isset($data['message'])) {
            $data['message'] = $payload['message'];
        }

        return $data;
    }
}
