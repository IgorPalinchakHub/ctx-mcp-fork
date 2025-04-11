<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace\Infrastructure\Cache;

/**
 * LRU (Least Recently Used) Cache implementation
 * Used to limit memory usage for parsed files and other large data structures
 */
class LRUCache
{
    /**
     * The actual cache data
     *
     * @var array<string, mixed>
     */
    private array $cache = [];

    /**
     * Tracks usage timestamps for each key
     *
     * @var array<string, float>
     */
    private array $usage = [];

    /**
     * Maximum number of items to keep in cache
     */
    private readonly int $capacity;

    /**
     * @param int $capacity Maximum number of items to store
     */
    public function __construct(int $capacity = 100)
    {
        $this->capacity = max(1, $capacity);
    }

    /**
     * Get an item from the cache
     *
     * @param string $key Cache key
     * @return mixed The cached value or null if not found
     */
    public function get(string $key): mixed
    {
        if (!isset($this->cache[$key])) {
            return null;
        }

        // Update usage timestamp
        $this->usage[$key] = microtime(true);
        return $this->cache[$key];
    }

    /**
     * Store an item in the cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     */
    public function put(string $key, mixed $value): void
    {
        // Evict least recently used item if at capacity and key doesn't exist
        if (count($this->cache) >= $this->capacity && !isset($this->cache[$key])) {
            $this->evictLeastRecentlyUsed();
        }

        $this->cache[$key] = $value;
        $this->usage[$key] = microtime(true);
    }

    /**
     * Check if a key exists in the cache
     */
    public function has(string $key): bool
    {
        return isset($this->cache[$key]);
    }

    /**
     * Remove an item from the cache
     */
    public function remove(string $key): void
    {
        unset($this->cache[$key]);
        unset($this->usage[$key]);
    }

    /**
     * Get the number of items in the cache
     */
    public function count(): int
    {
        return count($this->cache);
    }

    /**
     * Clear the entire cache
     */
    public function clear(): void
    {
        $this->cache = [];
        $this->usage = [];
    }

    /**
     * Get the maximum capacity of the cache
     */
    public function getCapacity(): int
    {
        return $this->capacity;
    }

    /**
     * Evict the least recently used item from the cache
     */
    private function evictLeastRecentlyUsed(): void
    {
        if (empty($this->usage)) {
            return;
        }

        $leastUsedKey = array_keys($this->usage)[0];
        foreach ($this->usage as $k => $time) {
            if ($time < $this->usage[$leastUsedKey]) {
                $leastUsedKey = $k;
            }
        }

        unset($this->cache[$leastUsedKey]);
        unset($this->usage[$leastUsedKey]);
    }
}
