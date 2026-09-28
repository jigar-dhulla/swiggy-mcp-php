<?php

declare(strict_types=1);

use JigarDhulla\SwiggyMcp\RetryPolicy;
use JigarDhulla\SwiggyMcp\Swiggy;
use JigarDhulla\SwiggyMcp\Tests\Fakes\FakeHttpClient;
use JigarDhulla\SwiggyMcp\Tests\Fakes\SpyLogger;

/**
 * A Swiggy client wired to a fake HTTP client, with retries that never sleep.
 */
function swiggy(FakeHttpClient $http, ?SpyLogger $logger = null, string $token = 'secret-token'): Swiggy
{
    return new Swiggy(
        $token,
        httpClient: $http,
        retry: new RetryPolicy(sleep: fn (int $ms) => null),
        logger: $logger ?? new SpyLogger,
    );
}
