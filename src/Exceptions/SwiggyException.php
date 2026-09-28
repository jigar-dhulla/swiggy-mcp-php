<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Exceptions;

use RuntimeException;

/**
 * Base class for every failure this package throws. Catch it to handle all
 * Swiggy errors at once, or catch a subclass to react to one bucket.
 *
 * @see https://mcp.swiggy.com/builders/docs/reference/errors
 */
abstract class SwiggyException extends RuntimeException
{
    /**
     * Whether repeating the same call may succeed.
     */
    public function isRetryable(): bool
    {
        return false;
    }
}
