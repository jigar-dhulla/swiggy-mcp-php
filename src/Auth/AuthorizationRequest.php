<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Auth;

use JigarDhulla\SwiggyMcp\Exceptions\OAuthException;

/**
 * A login in progress: the URL to send the user to, plus what is needed to
 * finish it. Keep it (e.g. in the user's session) until Swiggy redirects
 * back; {@see toArray()} / {@see fromArray()} make that easy.
 */
final class AuthorizationRequest
{
    public function __construct(
        public readonly string $url,
        public readonly string $clientId,
        public readonly string $redirectUri,
        public readonly string $state,
        public readonly string $codeVerifier,
    ) {}

    /**
     * Pull the authorization code out of the URL Swiggy redirected to,
     * checking that it answers this request.
     *
     * @throws OAuthException
     */
    public function codeFrom(string $callbackUrl): string
    {
        parse_str((string) parse_url(trim($callbackUrl), PHP_URL_QUERY), $query);

        $state = $query['state'] ?? null;

        if (! is_string($state) || ! hash_equals($this->state, $state)) {
            throw new OAuthException('The callback does not belong to this login attempt (state mismatch).');
        }

        if (is_string($query['error'] ?? null) && $query['error'] !== '') {
            $description = $query['error_description'] ?? $query['error'];

            throw new OAuthException('Swiggy did not complete the login: '.(is_string($description) ? $description : $query['error']));
        }

        $code = $query['code'] ?? null;

        if (! is_string($code) || $code === '') {
            throw new OAuthException('The callback carries no authorization code.');
        }

        return $code;
    }

    /**
     * @return array{url: string, client_id: string, redirect_uri: string, state: string, code_verifier: string}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $this->state,
            'code_verifier' => $this->codeVerifier,
        ];
    }

    /**
     * @param  array{url: string, client_id: string, redirect_uri: string, state: string, code_verifier: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['url'], $data['client_id'], $data['redirect_uri'], $data['state'], $data['code_verifier']);
    }
}
