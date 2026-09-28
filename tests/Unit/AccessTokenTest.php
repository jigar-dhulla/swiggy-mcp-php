<?php

declare(strict_types=1);

use JigarDhulla\SwiggyMcp\Auth\AccessToken;
use JigarDhulla\SwiggyMcp\Auth\Pkce;

it('treats a token as expired a minute early', function () {
    $issuedAt = new DateTimeImmutable('2026-01-01 00:00:00');
    $token = new AccessToken('token', 3600, issuedAt: $issuedAt);

    expect($token->expiresAt->format('H:i'))->toBe('01:00')
        ->and($token->isExpired(now: $issuedAt->modify('+58 minutes')))->toBeFalse()
        ->and($token->isExpired(now: $issuedAt->modify('+59 minutes')))->toBeTrue()
        ->and((string) $token)->toBe('token');
});

it('derives the S256 challenge from the verifier', function () {
    // RFC 7636, appendix B.
    $pkce = new Pkce('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk');

    expect($pkce->challenge)->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM')
        ->and(Pkce::generate()->verifier)->toMatch('/^[A-Za-z0-9_-]{43}$/');
});
