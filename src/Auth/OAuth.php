<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Auth;

use JigarDhulla\SwiggyMcp\Exceptions\OAuthException;
use JigarDhulla\SwiggyMcp\Exceptions\TransientException;
use JigarDhulla\SwiggyMcp\Http\HttpClient;
use JigarDhulla\SwiggyMcp\Swiggy;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;

/**
 * Swiggy's OAuth 2.1 + PKCE flow: dynamic client registration, the browser
 * authorize step, the code-for-token exchange, and logout.
 *
 *     $oauth = new OAuth('http://localhost:8080/callback');
 *     $login = $oauth->authorize($oauth->register());
 *     // send the user to $login->url, then on the callback:
 *     $token = $oauth->exchange($login, $callbackUrl);
 *
 * The redirect URI must be `http://localhost…` or an HTTPS URI allowlisted
 * by Swiggy (builders@swiggy.in).
 *
 * @see https://mcp.swiggy.com/builders/docs/start/authenticate
 */
final class OAuth
{
    private readonly HttpClient $http;

    public function __construct(
        private readonly string $redirectUri,
        private readonly string $clientName = 'swiggy-mcp-php',
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly string $baseUrl = Swiggy::BASE_URL,
    ) {
        $this->http = new HttpClient($httpClient, $requestFactory, $streamFactory);
    }

    /**
     * Register an OAuth client for this redirect URI (RFC 7591) and return
     * its id. Store it and reuse it for later logins.
     *
     * @throws OAuthException
     */
    public function register(): string
    {
        $response = $this->post('/auth/register', [
            'client_name' => $this->clientName,
            'redirect_uris' => [$this->redirectUri],
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ]);

        $clientId = $this->json($response)['client_id'] ?? null;

        if ($response->getStatusCode() >= 300 || ! is_string($clientId) || $clientId === '') {
            throw new OAuthException('Swiggy client registration failed: '.$response->getBody());
        }

        return $clientId;
    }

    /**
     * Start a login: a fresh PKCE pair and state, and the URL to send the
     * user to. Pass your own `$pkce` / `$state` to control them.
     */
    public function authorize(string $clientId, ?Pkce $pkce = null, ?string $state = null, string $scope = 'mcp:tools'): AuthorizationRequest
    {
        $pkce ??= Pkce::generate();
        $state ??= bin2hex(random_bytes(20));

        $url = $this->url('/auth/authorize').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri,
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
            'scope' => $scope,
        ]);

        return new AuthorizationRequest($url, $clientId, $this->redirectUri, $state, $pkce->verifier);
    }

    /**
     * Finish a login from the URL Swiggy redirected to. The state is checked
     * before the code is exchanged. Codes are single-use and live 120 seconds.
     *
     * @throws OAuthException
     */
    public function exchange(AuthorizationRequest $request, string $callbackUrl): AccessToken
    {
        return $this->exchangeCode($request->clientId, $request->codeFrom($callbackUrl), $request->codeVerifier);
    }

    /**
     * Exchange an authorization code for an access token, when you already
     * pulled the code out of the callback yourself.
     *
     * @throws OAuthException
     */
    public function exchangeCode(string $clientId, string $code, #[SensitiveParameter] string $codeVerifier): AccessToken
    {
        $response = $this->post('/auth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $clientId,
        ]);

        $body = $this->json($response);
        $token = $body['access_token'] ?? null;

        if ($response->getStatusCode() >= 300 || ! is_string($token) || $token === '') {
            throw new OAuthException('Swiggy token exchange failed: '.$response->getBody());
        }

        return new AccessToken(
            $token,
            is_numeric($body['expires_in'] ?? null) ? (int) $body['expires_in'] : 0,
            is_string($body['scope'] ?? null) ? $body['scope'] : '',
        );
    }

    /**
     * Revoke the session on Swiggy's side. Best effort: returns false when
     * Swiggy could not be reached or refused (e.g. the token already expired).
     */
    public function logout(#[SensitiveParameter] string|AccessToken $accessToken): bool
    {
        $token = $accessToken instanceof AccessToken ? $accessToken->value : $accessToken;

        try {
            $response = $this->http->post($this->url('/auth/logout'), null, ['Authorization' => 'Bearer '.$token]);
        } catch (TransientException) {
            return false;
        }

        return $response->getStatusCode() < 300;
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws OAuthException
     */
    private function post(string $path, array $body): ResponseInterface
    {
        try {
            return $this->http->post($this->url($path), $body);
        } catch (TransientException $exception) {
            throw new OAuthException('Could not reach Swiggy: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
