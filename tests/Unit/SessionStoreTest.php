<?php

declare(strict_types=1);

use JigarDhulla\SwiggyMcp\Session\CacheSessionStore;
use JigarDhulla\SwiggyMcp\Session\InMemorySessionStore;
use Psr\SimpleCache\CacheInterface;

it('keeps sessions in memory', function () {
    $store = new InMemorySessionStore;

    $store->put('key', '');
    expect($store->get('key'))->toBe('');

    $store->forget('key');
    expect($store->get('key'))->toBeNull();
});

it('keeps sessions in a PSR-16 cache with a TTL', function () {
    $cache = new class implements CacheInterface
    {
        public array $items = [];

        public array $ttls = [];

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->items[$key] ?? $default;
        }

        public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
        {
            $this->items[$key] = $value;
            $this->ttls[$key] = $ttl;

            return true;
        }

        public function delete(string $key): bool
        {
            unset($this->items[$key]);

            return true;
        }

        public function clear(): bool
        {
            return true;
        }

        public function getMultiple(iterable $keys, mixed $default = null): iterable
        {
            return [];
        }

        public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
        {
            return true;
        }

        public function deleteMultiple(iterable $keys): bool
        {
            return true;
        }

        public function has(string $key): bool
        {
            return isset($this->items[$key]);
        }
    };

    $store = new CacheSessionStore($cache, ttlSeconds: 60);
    $store->put('key', 'session-1');

    expect($store->get('key'))->toBe('session-1')
        ->and($cache->ttls['swiggy-mcp.session.key'])->toBe(60);

    $store->forget('key');
    expect($store->get('key'))->toBeNull();
});
