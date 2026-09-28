<?php

declare(strict_types=1);

use JigarDhulla\SwiggyMcp\Exceptions\ToolException;
use JigarDhulla\SwiggyMcp\Exceptions\TransientException;
use JigarDhulla\SwiggyMcp\RetryPolicy;

it('backs off exponentially up to the cap', function () {
    $policy = new RetryPolicy(baseDelayMs: 500, maxDelayMs: 2000, jitter: false);

    expect([$policy->delayFor(0), $policy->delayFor(1), $policy->delayFor(2), $policy->delayFor(3)])
        ->toBe([500, 1000, 2000, 2000]);
});

it('keeps jitter within 20 percent', function () {
    $policy = new RetryPolicy(baseDelayMs: 1000);

    foreach (range(1, 50) as $ignored) {
        expect($policy->delayFor(0))->toBeGreaterThanOrEqual(800)->toBeLessThanOrEqual(1200);
    }
});

it('sleeps between retries and only retries retryable failures', function () {
    $slept = [];
    $policy = new RetryPolicy(retries: 2, jitter: false, sleep: function (int $ms) use (&$slept) {
        $slept[] = $ms;
    });

    expect(fn () => $policy->run(fn () => throw new TransientException('down')))->toThrow(TransientException::class);
    expect($slept)->toBe([500, 1000]);

    $slept = [];
    expect(fn () => $policy->run(fn () => throw new ToolException('out of stock')))->toThrow(ToolException::class);
    expect($slept)->toBe([]);
});

it('does not retry when disabled', function () {
    $attempts = 0;
    $operation = function () use (&$attempts) {
        $attempts++;

        throw new TransientException('down');
    };

    expect(fn () => RetryPolicy::none()->run($operation))->toThrow(TransientException::class);

    expect($attempts)->toBe(1);
});
