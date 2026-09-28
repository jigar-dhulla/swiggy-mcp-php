<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Exceptions;

/**
 * A network failure, an upstream 5xx or a JSON-RPC internal error (-32603).
 * Safe tools are retried automatically before this reaches you.
 */
class TransientException extends SwiggyException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
