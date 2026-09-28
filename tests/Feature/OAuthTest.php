<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use JigarDhulla\SwiggyMcp\Auth\AuthorizationRequest;
use JigarDhulla\SwiggyMcp\Auth\OAuth;
use JigarDhulla\SwiggyMcp\Auth\Pkce;
use JigarDhulla\SwiggyMcp\Exceptions\OAuthException;
use JigarDhulla\SwiggyMcp\Tests\Fakes\FakeHttpClient;
use Psr\Http\Message\RequestInterface;

function fakeOAuthServer(): FakeHttpClient
{
    return new FakeHttpClient(fn (RequestInterface $request) => match ($request->getUri()->getPath()) {
        '/auth/register' => FakeHttpClient::json(['client_id' => 'client-123']),
        '/auth/token' => FakeHttpClient::json(['access_token' => 'token-abc', 'token_type' => 'Bearer', 'expires_in' => 432000, 'scope' => 'mcp:tools']),
        '/auth/logout' => new Response(200),
        default => new Response(404),
    });
}

it('registers a public client for the redirect URI', function () {
    $http = fakeOAuthServer();

    $clientId = (new OAuth('http://localhost:8080/callback', 'My Agent', $http))->register();

    expect($clientId)->toBe('client-123')
        ->and(json_decode((string) $http->requests[0]->getBody(), true))->toMatchArray([
            'client_name' => 'My Agent',
            'redirect_uris' => ['http://localhost:8080/callback'],
            'token_endpoint_auth_method' => 'none',
        ]);
});

it('builds the authorize URL with a PKCE challenge', function () {
    $oauth = new OAuth('http://localhost:8080/callback', httpClient: fakeOAuthServer());
    $pkce = new Pkce('verifier');

    $login = $oauth->authorize('client-123', $pkce, 'state-1');

    parse_str((string) parse_url($login->url, PHP_URL_QUERY), $query);

    expect($login->url)->toStartWith('https://mcp.swiggy.com/auth/authorize?')
        ->and($query)->toMatchArray([
            'client_id' => 'client-123',
            'redirect_uri' => 'http://localhost:8080/callback',
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
            'state' => 'state-1',
            'scope' => 'mcp:tools',
        ])
        ->and($login->codeVerifier)->toBe('verifier');
});

it('exchanges the callback for an access token', function () {
    $http = fakeOAuthServer();
    $oauth = new OAuth('http://localhost:8080/callback', httpClient: $http);
    $login = $oauth->authorize('client-123', state: 'state-1');

    $token = $oauth->exchange($login, 'http://localhost:8080/callback?code=auth-code&state=state-1');

    expect($token->value)->toBe('token-abc')
        ->and($token->expiresIn)->toBe(432000)
        ->and($token->scope)->toBe('mcp:tools')
        ->and($token->isExpired())->toBeFalse()
        ->and(json_decode((string) $http->requests[0]->getBody(), true))->toMatchArray([
            'grant_type' => 'authorization_code',
            'code' => 'auth-code',
            'code_verifier' => $login->codeVerifier,
            'client_id' => 'client-123',
            'redirect_uri' => 'http://localhost:8080/callback',
        ]);
});

it('refuses a callback from another login attempt', function () {
    $http = fakeOAuthServer();
    $oauth = new OAuth('http://localhost', httpClient: $http);
    $login = $oauth->authorize('client-123', state: 'state-1');

    expect(fn () => $oauth->exchange($login, 'http://localhost?code=abc&state=forged'))
        ->toThrow(OAuthException::class, 'state mismatch');

    expect($http->requests)->toBeEmpty();
});

it('surfaces an error sent back on the callback', function () {
    $login = new AuthorizationRequest('url', 'client', 'http://localhost', 'state-1', 'verifier');

    expect(fn () => $login->codeFrom('http://localhost?error=access_denied&error_description=User+cancelled&state=state-1'))
        ->toThrow(OAuthException::class, 'User cancelled');
});

it('round-trips a pending login through an array', function () {
    $login = new AuthorizationRequest('url', 'client', 'http://localhost', 'state-1', 'verifier');

    expect(AuthorizationRequest::fromArray($login->toArray()))->toEqual($login);
});

it('fails loudly when the token exchange is refused', function () {
    $http = new FakeHttpClient(fn () => FakeHttpClient::json(['error' => 'invalid_grant'], 400));

    expect(fn () => (new OAuth('http://localhost', httpClient: $http))->exchangeCode('client', 'code', 'verifier'))
        ->toThrow(OAuthException::class, 'invalid_grant');
});

it('revokes the session on logout', function () {
    $http = fakeOAuthServer();

    expect((new OAuth('http://localhost', httpClient: $http))->logout('token-abc'))->toBeTrue()
        ->and($http->requests[0]->getHeaderLine('Authorization'))->toBe('Bearer token-abc');
});
