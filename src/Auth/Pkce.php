<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Auth;

use SensitiveParameter;

/**
 * A PKCE code verifier and its S256 challenge (RFC 7636).
 */
final class Pkce
{
    public readonly string $challenge;

    public function __construct(#[SensitiveParameter] public readonly string $verifier)
    {
        $this->challenge = self::base64Url(hash('sha256', $verifier, true));
    }

    public static function generate(): self
    {
        return new self(self::base64Url(random_bytes(32)));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
