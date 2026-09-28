<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Exceptions;

/**
 * The access token is missing, expired or revoked (HTTP 401/419, JSON-RPC
 * -32001). Swiggy issues no refresh tokens: run the OAuth flow again.
 */
class AuthenticationException extends SwiggyException
{
    public static function rejected(): self
    {
        return new self('Swiggy rejected the access token. Run the OAuth flow again.');
    }
}
