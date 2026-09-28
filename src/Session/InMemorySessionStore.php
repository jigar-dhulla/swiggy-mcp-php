<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Session;

/**
 * Keeps session ids for the lifetime of the PHP process. The default.
 */
final class InMemorySessionStore implements SessionStore
{
    /** @var array<string, string> */
    private array $sessions = [];

    public function get(string $key): ?string
    {
        return $this->sessions[$key] ?? null;
    }

    public function put(string $key, string $sessionId): void
    {
        $this->sessions[$key] = $sessionId;
    }

    public function forget(string $key): void
    {
        unset($this->sessions[$key]);
    }
}
