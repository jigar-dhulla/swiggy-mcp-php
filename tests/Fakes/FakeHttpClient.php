<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Tests\Fakes;

use Closure;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers from a closure and records every request.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * @param  Closure(RequestInterface, array<string, mixed>): ResponseInterface  $handler
     */
    public function __construct(private readonly Closure $handler) {}

    /**
     * A fake MCP server: answers `initialize` with session `session-1` and
     * each `tools/call` from `$tools` (data arrays, or closures returning
     * data or a full response).
     *
     * @param  array<string, array<string, mixed>|Closure>  $tools
     */
    public static function mcp(array $tools = []): self
    {
        return new self(function (RequestInterface $request, array $payload) use ($tools): ResponseInterface {
            $method = $payload['method'] ?? null;

            if ($method === 'initialize') {
                return self::json(['jsonrpc' => '2.0', 'id' => $payload['id'], 'result' => ['protocolVersion' => '2025-06-18']], headers: ['Mcp-Session-Id' => 'session-1']);
            }

            if ($method !== 'tools/call') {
                return new Response(202);
            }

            $handler = $tools[$payload['params']['name']] ?? [];
            $data = $handler instanceof Closure ? $handler((array) $payload['params']['arguments']) : $handler;

            if ($data instanceof ResponseInterface) {
                return $data;
            }

            return self::result($payload['id'], ['content' => [['type' => 'text', 'text' => json_encode(['success' => true, 'data' => $data])]]]);
        });
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): ResponseInterface
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($body));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function result(mixed $id, array $result): ResponseInterface
    {
        return self::json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $payload = json_decode((string) $request->getBody(), true);

        return ($this->handler)($request, is_array($payload) ? $payload : []);
    }

    /**
     * The decoded JSON bodies of every request sent with the given JSON-RPC
     * method (and tool, for `tools/call`).
     *
     * @return list<array<string, mixed>>
     */
    public function sent(string $method, ?string $tool = null): array
    {
        $payloads = [];

        foreach ($this->requests as $request) {
            $payload = json_decode((string) $request->getBody(), true);

            if (($payload['method'] ?? null) === $method && ($tool === null || ($payload['params']['name'] ?? null) === $tool)) {
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }
}
