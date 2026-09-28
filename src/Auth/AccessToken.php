<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Auth;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use SensitiveParameter;

/**
 * A Swiggy access token. It lasts 5 days and cannot be refreshed: when it
 * expires, run the OAuth flow again.
 */
final class AccessToken
{
    public readonly DateTimeImmutable $expiresAt;

    public function __construct(
        #[SensitiveParameter] public readonly string $value,
        public readonly int $expiresIn,
        public readonly string $scope = '',
        ?DateTimeInterface $issuedAt = null,
    ) {
        $this->expiresAt = DateTimeImmutable::createFromInterface($issuedAt ?? new DateTimeImmutable)
            ->add(new DateInterval('PT'.max(0, $expiresIn).'S'));
    }

    /**
     * Whether the token has expired, or will within `$leewaySeconds`. Swiggy
     * advises treating a token as expired a minute early.
     */
    public function isExpired(int $leewaySeconds = 60, ?DateTimeInterface $now = null): bool
    {
        $now = DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable);

        return $this->expiresAt->getTimestamp() - $leewaySeconds <= $now->getTimestamp();
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
