<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Exceptions;

/**
 * The tool ran but reported a business failure — out of stock, unserviceable
 * address, minimum order not met. The message is meant for the end user.
 *
 * `reportLink` is Swiggy's diagnostic link, when one was captured.
 */
class ToolException extends SwiggyException
{
    /**
     * @param  array<string, mixed>  $payload  the failed tool result, as Swiggy sent it
     */
    public function __construct(
        string $message,
        public readonly string $tool = '',
        public readonly ?string $reportLink = null,
        public readonly array $payload = [],
    ) {
        parent::__construct($message);
    }
}
