<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Exceptions;

/**
 * Swiggy answered HTTP 429. `retryAfter` carries the `Retry-After` header in
 * seconds, when Swiggy sent one.
 */
class RateLimitedException extends SwiggyException
{
    public function __construct(public readonly ?int $retryAfter = null)
    {
        parent::__construct(sprintf('Swiggy rate limit hit; retry after %d seconds.', $retryAfter ?? 60));
    }
}
