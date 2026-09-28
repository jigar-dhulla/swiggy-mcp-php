<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Exceptions;

/**
 * A step of the OAuth flow failed: client registration, a callback that does
 * not match the pending authorization, or the code-for-token exchange.
 */
class OAuthException extends SwiggyException {}
