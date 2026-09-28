<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Exceptions;

/**
 * Swiggy refused the request itself: an unknown tool, missing or invalid
 * arguments (HTTP 4xx or a JSON-RPC error). Fix the call; do not retry.
 */
class InvalidRequestException extends SwiggyException {}
