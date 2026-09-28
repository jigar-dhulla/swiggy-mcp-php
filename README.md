# Swiggy MCP for PHP

[![Tests](https://github.com/jigar-dhulla/swiggy-mcp-php/actions/workflows/tests.yml/badge.svg)](https://github.com/jigar-dhulla/swiggy-mcp-php/actions/workflows/tests.yml)
[![License](https://img.shields.io/github/license/jigar-dhulla/swiggy-mcp-php.svg)](LICENSE)

A small, framework-agnostic PHP client for [Swiggy's MCP servers](https://mcp.swiggy.com/builders/) — Food, Instamart, Dineout and Scenes.

It handles the parts that are easy to get wrong: OAuth 2.1 + PKCE, MCP sessions, SSE responses, retries with backoff, and Swiggy's response envelope. You get plain PHP arrays back.

## Getting started

```bash
composer require jigar-dhulla/swiggy-mcp
```

Requires PHP 8.2+ and any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client (Guzzle, Symfony HttpClient, …). One is picked up automatically.

### 1. Log in

Swiggy uses OAuth with PKCE. The user logs in with their phone number and an OTP in the browser.

```php
use JigarDhulla\SwiggyMcp\Auth\OAuth;

$oauth = new OAuth(redirectUri: 'http://localhost:8080/callback');

$clientId = $oauth->register();         // one-time; save it and reuse it
$login = $oauth->authorize($clientId);  // save $login->toArray() in the session

header('Location: '.$login->url);
```

When Swiggy redirects back:

```php
$token = $oauth->exchange($login, $callbackUrl);  // checks state, returns an AccessToken

$token->value;      // the bearer token
$token->expiresAt;  // tokens last 5 days and can't be refreshed
```

### 2. Call a tool

```php
use JigarDhulla\SwiggyMcp\Swiggy;

$swiggy = new Swiggy($token);

$addresses = $swiggy->instamart()->call('get_addresses');

$results = $swiggy->instamart()->call('search_products', [
    'addressId' => $addresses['addresses'][0]['id'],
    'query' => 'milk',
]);
```

`food()`, `instamart()`, `dineout()` and `scenes()` each return a client for that server. `tools()` lists what a server offers. Tool names and arguments are in [Swiggy's reference](https://mcp.swiggy.com/builders/docs/reference).

### 3. Handle errors

Every failure extends `SwiggyException`:

| Exception | When | What to do |
| --- | --- | --- |
| `AuthenticationException` | Token expired or revoked | Log in again |
| `ToolException` | Out of stock, unserviceable address, … | Show the message to the user |
| `RateLimitedException` | Too many requests | Wait `$e->retryAfter` seconds |
| `TransientException` | Network or Swiggy outage | Already retried; try later |
| `InvalidRequestException` | Bad tool name or arguments | Fix the call |

Tools that must never run twice, like `checkout`, should skip automatic retries:

```php
$swiggy->instamart()->call('checkout', $arguments, retryable: false);
```

## Configuration

Everything is optional and passed to the constructor:

```php
$swiggy = new Swiggy(
    $token,
    httpClient: $psr18Client,                        // set timeouts and proxies here
    sessions: new CacheSessionStore($psr16Cache),    // share MCP sessions across processes
    retry: new RetryPolicy(retries: 5),              // or RetryPolicy::none()
    logger: $psr3Logger,                             // every call logged, never the token
    clientName: 'my-agent',
);
```

Logs carry the JSON-RPC `request_id` and session id that Swiggy asks for when you report an issue.

## Testing

```bash
composer test
```

## License

MIT. Not affiliated with or endorsed by Swiggy.
