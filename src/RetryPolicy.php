<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp;

use Closure;

/**
 * How transient failures are retried: exponential backoff with jitter,
 * starting at 500ms and doubling, as Swiggy's error guide recommends.
 *
 * Calls marked non-retryable (e.g. `checkout`) are never repeated.
 */
final class RetryPolicy
{
    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /**
     * @param  int  $retries  how many times to retry after the first attempt
     * @param  int  $baseDelayMs  the pause before the first retry
     * @param  int  $maxDelayMs  the longest pause between two attempts
     * @param  bool  $jitter  randomise each pause by up to ±20%
     * @param  (callable(int): void)|null  $sleep  pauses for the given milliseconds; swap it out in tests
     */
    public function __construct(
        public readonly int $retries = 3,
        public readonly int $baseDelayMs = 500,
        public readonly int $maxDelayMs = 8000,
        public readonly bool $jitter = true,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep !== null
            ? Closure::fromCallable($sleep)
            : static function (int $milliseconds): void {
                usleep($milliseconds * 1000);
            };
    }

    public static function none(): self
    {
        return new self(retries: 0);
    }

    /**
     * The pause before retry number `$attempt` (0-based), in milliseconds.
     */
    public function delayFor(int $attempt): int
    {
        $delay = min($this->maxDelayMs, $this->baseDelayMs * (2 ** $attempt));

        if (! $this->jitter || $delay === 0) {
            return $delay;
        }

        $spread = intdiv($delay, 5);

        return $delay + random_int(-$spread, $spread);
    }

    /**
     * Run `$operation`, retrying it while it throws a retryable failure.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @param  (callable(Exceptions\SwiggyException, int): void)|null  $onFailure  called with the final failure and the attempts made
     * @return T
     */
    public function run(callable $operation, bool $retryable = true, ?callable $onFailure = null): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $operation();
            } catch (Exceptions\SwiggyException $exception) {
                if (! $retryable || ! $exception->isRetryable() || $attempt >= $this->retries) {
                    if ($onFailure !== null) {
                        $onFailure($exception, $attempt + 1);
                    }

                    throw $exception;
                }

                ($this->sleep)($this->delayFor($attempt++));
            }
        }
    }
}
