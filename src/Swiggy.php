<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp;

use JigarDhulla\SwiggyMcp\Auth\AccessToken;
use JigarDhulla\SwiggyMcp\Http\HttpClient;
use JigarDhulla\SwiggyMcp\Session\InMemorySessionStore;
use JigarDhulla\SwiggyMcp\Session\SessionStore;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;

/**
 * The entry point: one access token, a client per Swiggy MCP server.
 *
 *     $swiggy = new Swiggy($accessToken);
 *     $cart = $swiggy->instamart()->call('get_cart');
 *
 * Every dependency is optional. The HTTP client and PSR-17 factories are
 * discovered when not given; pass your own to set timeouts or proxies.
 */
final class Swiggy
{
    public const BASE_URL = 'https://mcp.swiggy.com';

    private readonly string $accessToken;

    private readonly HttpClient $http;

    /** @var array<string, McpClient> */
    private array $clients = [];

    public function __construct(
        #[SensitiveParameter] string|AccessToken $accessToken,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly SessionStore $sessions = new InMemorySessionStore,
        private readonly RetryPolicy $retry = new RetryPolicy,
        private readonly LoggerInterface $logger = new NullLogger,
        private readonly string $baseUrl = self::BASE_URL,
        private readonly string $clientName = 'swiggy-mcp-php',
        private readonly string $clientVersion = '1.0.0',
    ) {
        $this->accessToken = $accessToken instanceof AccessToken ? $accessToken->value : $accessToken;
        $this->http = new HttpClient($httpClient, $requestFactory, $streamFactory);
    }

    public function food(): McpClient
    {
        return $this->server(Server::Food);
    }

    public function instamart(): McpClient
    {
        return $this->server(Server::Instamart);
    }

    public function dineout(): McpClient
    {
        return $this->server(Server::Dineout);
    }

    public function scenes(): McpClient
    {
        return $this->server(Server::Scenes);
    }

    public function server(Server $server): McpClient
    {
        return $this->endpoint($server->url($this->baseUrl));
    }

    /**
     * A client for any MCP endpoint URL, e.g. a version-pinned
     * `https://mcp.swiggy.com/v1/food`.
     */
    public function endpoint(string $url): McpClient
    {
        return $this->clients[$url] ??= new McpClient(
            $url,
            $this->accessToken,
            $this->http,
            $this->sessions,
            $this->retry,
            $this->logger,
            $this->clientName,
            $this->clientVersion,
        );
    }
}
