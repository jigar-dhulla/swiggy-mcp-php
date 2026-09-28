<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Session;

use Psr\SimpleCache\CacheInterface;

/**
 * Keeps session ids in any PSR-16 cache (Laravel, Symfony, …) so every
 * worker and request reuses the same MCP session.
 */
final class CacheSessionStore implements SessionStore
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $ttlSeconds = 43_200,
        private readonly string $prefix = 'swiggy-mcp.session.',
    ) {}

    public function get(string $key): ?string
    {
        $sessionId = $this->cache->get($this->prefix.$key);

        return is_string($sessionId) ? $sessionId : null;
    }

    public function put(string $key, string $sessionId): void
    {
        $this->cache->set($this->prefix.$key, $sessionId, $this->ttlSeconds);
    }

    public function forget(string $key): void
    {
        $this->cache->delete($this->prefix.$key);
    }
}
