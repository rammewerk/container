<?php

declare(strict_types=1);

namespace Rammewerk\Component\Container;

use Closure;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionException;
use ReflectionNamedType;
use Rammewerk\Component\Container\Error\ContainerException;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * A lightweight Dependency Injection Container.
 *
 * - Allows binding interfaces to concrete classes or factories.
 * - Supports singleton/shared registrations.
 * - Caches reflection data for better performance.
 * - Worker-mode safe: Use fork() to create isolated instances while preserving reflection cache.
 *
 * @author Kristoffer Follestad <kristoffer@bonsy.no>
 */
class Container {

    /**
     * Reflection cache (shared across instances for performance)
     */
    protected ReflectionCache $cache;

    /**
     * Singleton instances
     *
     * @var array<class-string, object>
     */
    protected array $instances = [];

    /**
     * List of registered singletons.
     *
     * @var array<class-string, true>
     */
    protected array $shared = [];

    /**
     * List of bindings mapping abstract types to concrete implementations or factory closures.
     *
     * @var array<class-string, class-string|Closure>
     */
    protected array $bindings = [];

    /**
     * Constructor.
     */
    public function __construct(?ReflectionCache $cache = null) {
        $this->cache = $cache ?? new ReflectionCache();
    }



    /**
     * Registers class names as singletons (immutable).
     *
     * @param class-string[] $classes
     *
     * @return static
     * @immutable
     */
    public function share(array $classes): static {
        $c = clone $this;
        foreach ($classes as $name) {
            $c->shared[$name] = true;
            $c->cache->remove($name);
        }
        return $c;
    }



    /**
     * Defines a binding between an abstract type and its concrete implementation.
     *
     * The abstract type can be an interface, abstract class, or concrete class.
     * The concrete implementation can be either a class-string representing the implementation
     * or a closure that returns an instance of the abstract type.
     *
     * Returns an immutable instance with the updated bindings.
     *
     * @param class-string $abstract        The abstract type to bind (interface, abstract class, or class).
     * @param class-string|object $concrete The concrete implementation or factory closure.
     *
     * @return static
     * @immutable
     */
    public function bind(string $abstract, string|object $concrete): static {
        return $this->bindings([$abstract => $concrete]);
    }



    /**
     * Defines multiple bindings between abstract types and their concrete implementations at once.
     *
     * Each binding maps an abstract type (interface, abstract class, or class) to a concrete
     * implementation. The concrete implementation can be either a class-string representing the
     * implementation or a closure that returns an instance of the abstract type.
     *
     * This is a batch version of the {@see bind()} method, allowing multiple bindings to be defined
     * in a single call. Returns an immutable instance with the updated bindings.
     *
     * @param array<class-string, class-string|Closure(static):object|object> $bindings An array mapping abstract types to concrete implementations or factory closures.
     *
     * @return static Immutable instance with updated bindings.
     * @immutable
     */
    public function bindings(array $bindings): static {
        $c = clone $this;
        foreach ($bindings as $a => $concrete) {
            $c->bindings[$a] = (is_string($concrete) || $concrete instanceof Closure)
                ? $concrete
                : static fn() => $concrete;
            $c->cache->remove($a);
            unset($c->instances[$a]);
        }
        return $c;
    }



    /**
     * Flushes all instantiated shared singletons.
     *
     * Clears the in-memory cache of created shared class instances.
     * On the next creation request, new instances will be created again.
     *
     * @return void
     */
    public function flushInstances(): void {
        $this->instances = [];
    }

    /**
     * Creates a new Container instance with fresh instances but shared reflection cache.
     *
     * This method is designed for worker mode environments (like FrankenPHP) where
     * you need to isolate instances between requests while preserving the expensive
     * reflection cache for performance.
     *
     * The returned container will:
     * - Share the same reflection cache (for performance)
     * - Have empty instances array (for isolation)
     * - Keep the same configuration (shared, bindings)
     *
     * @return static Fresh container instance with a shared cache
     */
    public function fork(): static {
        return clone $this;
    }

    /**
     * Magic clone method that preserves the cache but flushes instances.
     *
     * This enables worker-mode safe cloning where the expensive reflection
     * cache is preserved, but instances are isolated between requests.
     */
    public function __clone(): void {
        $this->instances = [];
    }



    /**
     * Creates a fully constructed instance of the specified class, optionally using the provided
     * array of arguments for the class constructor.
     *
     * @template T of object
     * @param class-string<T> $name The fully qualified name of the class to instantiate.
     * @param mixed[] $args         Optional array of arguments to pass to the class constructor.
     *
     * @return T A fully constructed instance of the specified class.
     */
    public function create(string $name, array $args = []) {

        /** Return a shared instance (singleton) if exist  */
        if (isset($this->instances[$name])) {
            return $this->instances[$name]; // @phpstan-ignore-line
        }

        if ($this->cache->has($name)) {
            $closure = $this->cache->get($name);
            assert($closure !== null); // has() ensures this is not null
            $instance = $closure($args);
            return isset($this->shared[$name]) ? $this->instances[$name] = $instance : $instance; // @phpstan-ignore-line
        }

        /** Handle binding if defined */
        if (isset($this->bindings[$name])) {
            /** @var T $instance */
            $instance = is_string($this->bindings[$name])
                ? $this->create($this->bindings[$name], $args)
                : $this->bindings[$name]($this);

            return isset($this->shared[$name]) ? $this->instances[$name] = $instance : $instance;
        }

        try {
            $class = new ReflectionClass($name);

            if ($class->isInterface()) {
                throw new ContainerException(
                    "Cannot instantiate interface '{$class->getName()}' without a concrete implementation or binding in the container.",
                );
            }

            $params = $class->getConstructor()?->getParameters();

            $closure = empty($params) ? static fn() => new $name() : $this->getClosure($class, $params);

            $this->cache->set($name, $closure);

            if (isset($this->shared[$name])) {
                return $this->instances[$name] = $closure($args);
            }

            return $closure($args);

        } catch (ReflectionException $e) {
            throw new ContainerException("Unable to reflect class: $name", $e->getCode(), $e);
        }

    }



    /**
     * Returns a closure to instantiate $class
     *
     * @template T of object
     * @param ReflectionClass<T> $class
     * @param ReflectionParameter[] $params
     *
     * @return Closure(mixed[]):T
     */
    private function getClosure(ReflectionClass $class, array $params): Closure {
        $paramClosure = $this->resolveParams($this->parseParameters($params));
        /** @phpstan-ignore-next-line */
        return static fn(array $a) => $class->newLazyProxy(static fn() => $class->newInstance(...$paramClosure($a)));
    }



    /**
     * Extracts and caches detailed information about constructor parameters, including class types,
     * built-in types, union types, and intersection types.
     *
     * This method uses closures to cache reflection data, minimizing the performance overhead
     * of repeatedly calling reflection APIs. It handles various parameter types such as:
     * - Named classes
     * - Built-in types
     * - Union types (multiple types separated by `|`)
     * - Intersection classes (multiple classes combined by `&`)
     *
     * Each parameter's information is returned as an array with the following structure:
     * - [0]: `string|null` — The class-string if it's a single class type, otherwise `null`.
     * - [1]: `string[]` — An array of built-in types (e.g., `'int'`, `'string'`).
     * - [2]: `string[]` — An array of class-string from union types.
     * - [3]: `string[]` — An array of class-string from intersection types.
     * - [4]: `bool` — Whether the parameter is nullable.
     * - [5]: `mixed` — The default value of the parameter, if available.
     *
     * @param ReflectionParameter[] $parameters An array of reflection parameters from a constructor.
     *
     * @return array<array{0: string|null, 1: string[], 2: string[], 3: string[], 4: bool, 5: mixed}>
     */
    private function parseParameters(array $parameters): array {

        $paramsInfo = [];

        foreach ($parameters as $parameter) {

            $info = [null, [], [], [], $parameter->allowsNull(), $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null];

            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType) {
                if (!$type->isBuiltIn()) {
                    $info[0] = $type->getName();
                } else {
                    $info[1][] = $type->getName();
                }
            } else if ($type instanceof ReflectionUnionType) {
                foreach ($type->getTypes() as $unionType) {
                    if ($unionType instanceof ReflectionIntersectionType) {
                        foreach ($unionType->getTypes() as $i) {
                            /** @var ReflectionNamedType $i */
                            $info[3][] = $i->getName();
                        }
                    } else if ($unionType->isBuiltIn()) {
                        $info[1][] = $unionType->getName();
                    } else {
                        $info[2][] = $unionType->getName();
                    }
                }
            } else if ($type instanceof ReflectionIntersectionType) {
                foreach ($type->getTypes() as $i) {
                    /** @var ReflectionNamedType $i */
                    $info[3][] = $i->getName();
                }
            }

            $paramsInfo[] = $info;

        }

        return $paramsInfo;

    }



    /**
     * Creates a closure that resolves constructor parameters using $args and container bindings.
     *
     * @param array<array{0: string|null, 1: string[], 2: string[], 3: string[], 4: bool, 5: mixed}> $paramInfo
     *
     * @return Closure(array<mixed>): array<mixed>
     */
    private function resolveParams(array $paramInfo): Closure {

        # Return a closure that uses the cached information to generate the arguments for the method
        return function (array $args) use ($paramInfo): array {
            return array_map(function ($info) use (&$args) {

                /**
                 * @var class-string $className
                 * @var string[] $builtInTypes
                 * @var class-string[] $unionClasses
                 * @var class-string[] $intersects
                 * @var bool $nullable
                 * @var mixed $default
                 */
                [$className, $builtInTypes, $unionClasses, $intersects, $nullable, $default] = $info;

                if ($className) {

                    // Return argument matching the class type or null if allowed
                    foreach ($args as $i => $arg) {
                        if ($arg instanceof $className || ($arg === null && $nullable)) {
                            return array_splice($args, $i, 1)[0];
                        }
                    }

                    // Return binding if a closure is defined for the class
                    // Use closure if defined, even if the parameter is nullable
                    if (isset($this->bindings[$className]) && $this->bindings[$className] instanceof Closure) {
                        return $this->bindings[$className]($this);
                    }

                    // Return container instance if the class is this container
                    if ($className === static::class) {
                        return $this;
                    }

                    // Create an instance of the class or return null if the parameter allows null
                    return $nullable ? null : $this->create($className);
                }

                // Match and return the first argument that matches a built-in type
                foreach ($builtInTypes as $type) {
                    $checkFn = 'is_' . $type;
                    foreach ($args as $i => $arg) {
                        if (function_exists($checkFn) && $checkFn($arg)) {
                            return array_splice($args, $i, 1)[0];
                        }
                    }
                }


                // Match and return the first argument that matches any class in the union type or null if allowed
                foreach ($unionClasses as $unionClassName) {
                    foreach ($args as $i => $arg) {
                        if ($arg instanceof $unionClassName || ($arg === null && $nullable)) {
                            return array_splice($args, $i, 1)[0];
                        }
                    }
                }


                // Handle intersection types by finding and returning the first argument that implements all required
                // classes or interfaces in the intersection type
                foreach ($args as $i => $arg) {
                    foreach ($intersects as $ic) {
                        if (!($arg instanceof $ic)) {
                            continue 2;
                        }
                    }
                    return array_splice($args, $i, 1)[0];
                }

                // Serve the next argument as-is, since the parameter may be untyped
                // Or serve the default value if available, otherwise return null (no arguments left)
                return $args ? array_shift($args) : $default;

            }, $paramInfo);
        };
    }



}
