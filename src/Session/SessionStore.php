<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Session;

/**
 * Where MCP session ids live between requests. Swiggy counts every
 * `initialize` as an auth event, so long-running apps should share sessions
 * across processes with {@see CacheSessionStore}.
 *
 * An empty string is a valid session id: the server runs stateless.
 */
interface SessionStore
{
    public function get(string $key): ?string;

    public function put(string $key, string $sessionId): void;

    public function forget(string $key): void;
}
