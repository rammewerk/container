<?php

declare(strict_types=1);

namespace Rammewerk\Component\Container;

use Closure;

/**
 * Reflection cache for storing compiled closures for dependency injection.
 *
 * This class is designed to be shared across Container instances to preserve
 * reflection performance optimizations while allowing instance isolation
 * in worker mode environments like FrankenPHP.
 */
class ReflectionCache {

    /**
     * Reflection cache storing compiled closures for creating instances.
     *
     * @var array<class-string, Closure(mixed[]):object>
     */
    private array $cache = [];



    /**
     * Stores a compiled closure for a given class.
     *
     * @param class-string $className
     * @param Closure(mixed[], Container):object $closure
     */
    public function set(string $className, Closure $closure): void {
        $this->cache[$className] = $closure;
    }



    /**
     * Retrieves a compiled closure for a given class.
     *
     * @param class-string $className
     *
     * @return Closure(mixed[], Container):object|null
     */
    public function get(string $className): ?Closure {
        return $this->cache[$className] ?? null;
    }



    /**
     * Checks if a closure exists for the given class.
     *
     * @param class-string $className
     */
    public function has(string $className): bool {
        return isset($this->cache[$className]);
    }



    /**
     * Removes a closure from the cache.
     *
     * @param class-string $className
     */
    public function remove(string $className): void {
        unset($this->cache[$className]);
    }



    /**
     * Clears all cached closures.
     */
    public function clear(): void {
        $this->cache = [];
    }


}