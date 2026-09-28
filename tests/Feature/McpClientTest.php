<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use JigarDhulla\SwiggyMcp\Exceptions\AuthenticationException;
use JigarDhulla\SwiggyMcp\Exceptions\InvalidRequestException;
use JigarDhulla\SwiggyMcp\Exceptions\RateLimitedException;
use JigarDhulla\SwiggyMcp\Exceptions\ToolException;
use JigarDhulla\SwiggyMcp\Exceptions\TransientException;
use JigarDhulla\SwiggyMcp\Tests\Fakes\FakeHttpClient;
use JigarDhulla\SwiggyMcp\Tests\Fakes\SpyLogger;
use Psr\Http\Message\RequestInterface;

it('initialises one session and reuses it with the bearer token', function () {
    $http = FakeHttpClient::mcp(['get_cart' => ['items' => []]]);
    $instamart = swiggy($http)->instamart();

    expect($instamart->call('get_cart'))->toBe(['items' => []]);
    $instamart->call('get_cart');

    expect($http->sent('initialize'))->toHaveCount(1)
        ->and($http->sent('notifications/initialized'))->toHaveCount(1);

    $call = $http->requests[array_key_last($http->requests)];

    expect((string) $call->getUri())->toBe('https://mcp.swiggy.com/im')
        ->and($call->getHeaderLine('Authorization'))->toBe('Bearer secret-token')
        ->and($call->getHeaderLine('Mcp-Session-Id'))->toBe('session-1')
        ->and($call->getHeaderLine('MCP-Protocol-Version'))->toBe('2025-06-18');
});

it('points each server at its own endpoint', function () {
    $http = FakeHttpClient::mcp();
    $swiggy = swiggy($http);

    expect($swiggy->food()->url())->toBe('https://mcp.swiggy.com/food')
        ->and($swiggy->instamart()->url())->toBe('https://mcp.swiggy.com/im')
        ->and($swiggy->dineout()->url())->toBe('https://mcp.swiggy.com/dineout')
        ->and($swiggy->scenes()->url())->toBe('https://mcp.swiggy.com/scenes')
        ->and($swiggy->instamart())->toBe($swiggy->instamart());
});

it('sends arguments as a JSON object, even when empty', function () {
    $http = FakeHttpClient::mcp();

    swiggy($http)->instamart()->call('get_cart');

    expect((string) $http->requests[2]->getBody())->toContain('"arguments":{}');
});

it('unwraps data from a server-sent event stream', function () {
    $http = new FakeHttpClient(function (RequestInterface $request, array $payload) {
        if ($payload['method'] !== 'tools/call') {
            return new Response(202);
        }

        $envelope = json_encode(['success' => true, 'data' => ['cartTotalAmount' => '120'], 'message' => 'Cart loaded']);
        $message = json_encode(['jsonrpc' => '2.0', 'id' => $payload['id'], 'result' => ['content' => [['type' => 'text', 'text' => $envelope]]]]);

        return new Response(200, ['Content-Type' => 'text/event-stream'], "event: message\ndata: {$message}\n\n");
    });

    expect(swiggy($http)->instamart()->call('get_cart'))->toBe(['cartTotalAmount' => '120', 'message' => 'Cart loaded']);
});

it('reads data placed straight into structuredContent', function () {
    $http = new FakeHttpClient(fn (RequestInterface $request, array $payload) => FakeHttpClient::result($payload['id'] ?? null, [
        'content' => [['type' => 'text', 'text' => 'Found 1 saved address.']],
        'structuredContent' => ['addresses' => [['id' => 'a1']]],
    ]));

    expect(swiggy($http)->instamart()->call('get_addresses'))->toBe(['addresses' => [['id' => 'a1']]]);
});

it('returns plain text results under message', function () {
    $http = new FakeHttpClient(fn (RequestInterface $request, array $payload) => FakeHttpClient::result($payload['id'] ?? null, [
        'content' => [['type' => 'text', 'text' => 'Cart cleared.']],
    ]));

    expect(swiggy($http)->instamart()->call('clear_cart'))->toBe(['message' => 'Cart cleared.']);
});

it('throws a ToolException for a success:false envelope', function () {
    $http = FakeHttpClient::mcp(['update_cart' => fn () => FakeHttpClient::result('1', [
        'isError' => true,
        'content' => [['type' => 'text', 'text' => json_encode(['success' => false, 'error' => ['message' => 'Item out of stock', 'reportLink' => 'https://report']])]],
    ])]);

    expect(fn () => swiggy($http)->instamart()->call('update_cart'))
        ->toThrow(function (ToolException $e) {
            expect($e->getMessage())->toBe('Item out of stock')
                ->and($e->tool)->toBe('update_cart')
                ->and($e->reportLink)->toBe('https://report')
                ->and($e->isRetryable())->toBeFalse();
        });
});

it('throws a ToolException for an error result without an envelope', function () {
    $http = FakeHttpClient::mcp(['search_products' => fn () => FakeHttpClient::result('1', [
        'isError' => true,
        'content' => [['type' => 'text', 'text' => 'Invalid addressId']],
    ])]);

    expect(fn () => swiggy($http)->instamart()->call('search_products', ['query' => 'milk']))
        ->toThrow(ToolException::class, 'Invalid addressId');
});

it('throws an AuthenticationException when the token is rejected', function (int $status) {
    $http = FakeHttpClient::mcp(['get_cart' => fn () => new Response($status)]);

    expect(fn () => swiggy($http)->instamart()->call('get_cart'))->toThrow(AuthenticationException::class);
})->with([401, 419]);

it('throws an AuthenticationException for JSON-RPC -32001', function () {
    $http = FakeHttpClient::mcp(['get_cart' => fn () => FakeHttpClient::json(['jsonrpc' => '2.0', 'id' => '1', 'error' => ['code' => -32001, 'message' => 'Session expired']])]);

    expect(fn () => swiggy($http)->instamart()->call('get_cart'))->toThrow(AuthenticationException::class);
});

it('throws a RateLimitedException with Retry-After', function () {
    $http = FakeHttpClient::mcp(['get_cart' => fn () => new Response(429, ['Retry-After' => '30'])]);

    expect(fn () => swiggy($http)->instamart()->call('get_cart'))
        ->toThrow(fn (RateLimitedException $e) => expect($e->retryAfter)->toBe(30));
});

it('throws an InvalidRequestException for a 4xx', function () {
    $http = FakeHttpClient::mcp(['get_cart' => fn () => FakeHttpClient::json(['error' => ['message' => 'Missing addressId']], 400)]);

    expect(fn () => swiggy($http)->instamart()->call('get_cart'))->toThrow(InvalidRequestException::class, 'Missing addressId');
});

it('retries transient failures for safe tools', function () {
    $attempts = 0;
    $http = FakeHttpClient::mcp(['get_cart' => function () use (&$attempts) {
        return ++$attempts < 3 ? new Response(503) : ['items' => []];
    }]);

    expect(swiggy($http)->instamart()->call('get_cart'))->toBe(['items' => []])
        ->and($attempts)->toBe(3);
});

it('gives up after the configured retries', function () {
    $http = FakeHttpClient::mcp(['get_cart' => fn () => new Response(502)]);

    expect(fn () => swiggy($http)->instamart()->call('get_cart'))->toThrow(TransientException::class);

    expect($http->sent('tools/call', 'get_cart'))->toHaveCount(4);
});

it('never retries a call marked non-retryable', function () {
    $http = FakeHttpClient::mcp(['checkout' => fn () => new Response(503)]);

    expect(fn () => swiggy($http)->instamart()->call('checkout', retryable: false))->toThrow(TransientException::class);

    expect($http->sent('tools/call', 'checkout'))->toHaveCount(1);
});

it('starts a new session when the old one expired', function () {
    $sessions = 0;
    $http = new FakeHttpClient(function (RequestInterface $request, array $payload) use (&$sessions) {
        return match ($payload['method']) {
            'initialize' => FakeHttpClient::json(['result' => []], headers: ['Mcp-Session-Id' => 'session-'.++$sessions]),
            'tools/call' => $request->getHeaderLine('Mcp-Session-Id') === 'session-1'
                ? new Response(404)
                : FakeHttpClient::result($payload['id'], ['structuredContent' => ['ok' => true]]),
            default => new Response(202),
        };
    });

    expect(swiggy($http)->instamart()->call('get_cart'))->toBe(['ok' => true])
        ->and($sessions)->toBe(2);
});

it('lists tools across pages', function () {
    $http = new FakeHttpClient(function (RequestInterface $request, array $payload) {
        if ($payload['method'] !== 'tools/list') {
            return FakeHttpClient::json(['result' => []]);
        }

        return ($payload['params']['cursor'] ?? null) === 'page-2'
            ? FakeHttpClient::result($payload['id'], ['tools' => [['name' => 'get_cart']]])
            : FakeHttpClient::result($payload['id'], ['tools' => [['name' => 'search_products']], 'nextCursor' => 'page-2']);
    });

    expect(array_column(swiggy($http)->instamart()->tools(), 'name'))->toBe(['search_products', 'get_cart']);
});

it('logs every call with the ids Swiggy asks for, never the token', function () {
    $logger = new SpyLogger;
    $http = FakeHttpClient::mcp(['get_cart' => ['items' => []]]);

    swiggy($http, $logger)->instamart()->call('get_cart');

    $calls = array_values(array_filter($logger->named('swiggy.mcp.call'), fn (array $r) => $r['context']['tool'] === 'get_cart'));

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['level'])->toBe('info')
        ->and($calls[0]['context'])->toMatchArray(['status' => 200, 'session_id' => 'session-1'])
        ->and($calls[0]['context']['request_id'])->toMatch('/^[0-9a-f-]{36}$/')
        ->and(json_encode($logger->records))->not->toContain('secret-token');
});

it('logs the final failure with its exception class', function () {
    $logger = new SpyLogger;
    $http = FakeHttpClient::mcp(['get_cart' => fn () => FakeHttpClient::json(['error' => ['message' => 'Missing addressId']], 400)]);

    expect(fn () => swiggy($http, $logger)->instamart()->call('get_cart'))->toThrow(InvalidRequestException::class);

    expect($logger->named('swiggy.mcp.failed'))->toHaveCount(1)
        ->and($logger->named('swiggy.mcp.failed')[0]['context'])->toMatchArray([
            'tool' => 'get_cart',
            'exception' => InvalidRequestException::class,
            'attempts' => 1,
        ]);
});

it('warns when Swiggy flags a tool as deprecated', function () {
    $logger = new SpyLogger;
    $http = new FakeHttpClient(fn (RequestInterface $request, array $payload) => FakeHttpClient::result($payload['id'] ?? null, [
        'structuredContent' => ['items' => []],
        '_meta' => ['swiggy' => ['deprecation' => ['replacement' => 'get_cart_v2']]],
    ]));

    swiggy($http, $logger)->instamart()->call('get_cart');

    expect($logger->named('swiggy.mcp.deprecation')[0]['context'])
        ->toMatchArray(['tool' => 'get_cart', 'deprecation' => ['replacement' => 'get_cart_v2']]);
});
