<?php

namespace Rammewerk\Component\Container;

use Closure;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionException;
use ReflectionNamedType;
use Rammewerk\Component\Container\Error\ContainerException;
use ReflectionParameter;

/**
 * A lightweight Dependency Injection Container.
 *
 * - Allows binding interfaces to concrete classes or factories.
 * - Supports singleton/shared registrations.
 * - Caches reflection data for better performance.
 *
 * @author Kristoffer Follestad <kristoffer@bonsy.no>
 */
class Container {

    /**
     * Reflection cache
     *
     * @var array<class-string, Closure(mixed[]):object>
     */
    protected array $cache = [];

    /**
     * Singleton instances
     *
     * @var array<class-string, object>
     */
    protected array $instances = [];

    /**
     * List of registered singletons.
     *
     * @var array<class-string, int>
     */
    protected array $shared = [];

    /**
     * List of bindings mapping abstract types to concrete implementations or factory closures.
     *
     * @var array<class-string, class-string|Closure>
     */
    protected array $bindings = [];



    /**
     * Registers class names as singletons (immutable).
     *
     * @param class-string[] $classes
     *
     * @return static
     * @immutable
     */
    public function share(array $classes): static {
        $container = clone $this;
        $container->shared = array_merge( $container->shared, array_flip( $classes ) );
        foreach( $classes as $key ) {
            unset( $container->cache[$key] );
        }
        return $container;
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
     * @param class-string $abstract                        The abstract type to bind (interface, abstract class, or class).
     * @param class-string|Closure(static):object $concrete The concrete implementation or factory closure.
     *
     * @return static
     * @immutable
     */
    public function bind(string $abstract, string|Closure $concrete): static {
        return $this->bindings( [$abstract => $concrete] );
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
     * @param array<class-string, class-string|Closure(static):object> $bindings An array mapping abstract types to concrete implementations or factory closures.
     *
     * @return static Immutable instance with updated bindings.
     * @immutable
     */
    public function bindings(array $bindings): static {
        $container = clone $this;
        $container->bindings = array_merge( $container->bindings, $bindings );
        // Remove any cached reflection for updated keys
        foreach( array_keys( $bindings ) as $key ) {
            unset( $container->cache[$key], $container->instances[$key] );
        }
        return $container;
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

        /** Return shared instance (singleton) if exist  */
        if( isset( $this->instances[$name] ) ) {
            return $this->instances[$name]; // @phpstan-ignore-line
        }

        if( isset( $this->cache[$name] ) ) {
            return $this->cache[$name]( $args ); // @phpstan-ignore-line
        }

        /** Handle binding if defined */
        if( isset( $this->bindings[$name] ) ) {
            /** @var T $instance */
            $instance = is_string( $this->bindings[$name] )
                ? $this->create( $this->bindings[$name], $args )
                : $this->bindings[$name]( $this );

            return isset( $this->shared[$name] )
                ? $this->instances[$name] = $instance
                : $instance;
        }


        try {
            $class = new ReflectionClass( $name );

            if( $class->isInterface() ) {
                throw new ContainerException( 'Cannot instantiate an interface without bindings' );
            }

            /** @var Closure(mixed[]):T $cache */
            $cache = $this->getClosure( $class );

            if( isset( $this->shared[$name] ) ) {
                return $this->instances[$name] = $cache( $args );
            }

            $this->cache[$name] = $cache;
            return $cache( $args );

        } catch( ReflectionException $e ) {
            throw new ContainerException( "Unable to reflect class: $name", $e->getCode(), $e );
        }

    }



    /**
     * Returns a closure to instantiate $class
     *
     * @template T of object
     * @param ReflectionClass<T> $class
     *
     * @return Closure
     */
    private function getClosure(ReflectionClass $class): Closure {

        $constructor = $class->getConstructor();

        if( is_null( $constructor ) ) {
            return static fn() => $class->newInstance();
        }

        $param = $this->getParameters( $constructor );
        return static fn(array $a) => $class->newLazyProxy( static fn() => $class->newInstance( ...$param( $a ) ) );

    }



    /**
     * Creates a closure that resolves constructor parameters using $args and container bindings.
     *
     * @param ReflectionMethod $method
     *
     * @return Closure(array<mixed>): array<mixed>
     */
    private function getParameters(ReflectionMethod $method): Closure {

        $paramInfo = $this->parseParameterInfo( $method->getParameters() );

        # Return a closure that uses the cached information to generate the arguments for the method
        return function(array $args) use ($paramInfo): array {
            return array_map( function($info) use (&$args) {

                /**
                 * @var ReflectionParameter $parameter
                 * @var class-string $className
                 * @var string[] $builtInTypes
                 * @var class-string[] $unionClasses
                 * @var class-string[] $interSectionClasses
                 */
                [$parameter, $className, $builtInTypes, $unionClasses, $interSectionClasses] = $info;

                if( $className ) {

                    // Return argument matching the class type or null if allowed
                    foreach( $args as $i => $arg ) {
                        if( $arg instanceof $className || ($arg === null && $parameter->allowsNull()) ) {
                            return array_splice( $args, $i, 1 )[0];
                        }
                    }

                    // Return binding if a closure is defined for the class
                    // Use closure if defined, even if the parameter is nullable
                    if( isset( $this->bindings[$className] ) && $this->bindings[$className] instanceof Closure ) {
                        return $this->bindings[$className]( $this );
                    }

                    // Return container instance if the class is this container
                    if( $className === static::class ) {
                        return $this;
                    }

                    // Create an instance of the class or return null if the parameter allows null
                    return $parameter->allowsNull() ? null : $this->create( $className );
                }

                // Match and return the first argument that matches a built-in type
                foreach( $builtInTypes as $type ) {
                    $checkFn = 'is_' . $type;
                    foreach( $args as $i => $arg ) {
                        if( function_exists( $checkFn ) && $checkFn( $arg ) ) {
                            return array_splice( $args, $i, 1 )[0];
                        }
                    }
                }


                // Match and return the first argument that matches any class in the union type or null if allowed
                foreach( $unionClasses as $unionClassName ) {
                    foreach( $args as $i => $arg ) {
                        if( $arg instanceof $unionClassName || ($arg === null && $parameter->allowsNull()) ) {
                            return array_splice( $args, $i, 1 )[0];
                        }
                    }
                }


                // Handle intersection types by finding and returning the first argument that implements all required
                // classes or interfaces in the intersection type
                foreach( $args as $i => $arg ) {
                    $matchesAll = true; // Check if $arg implements all intersection type classes
                    foreach( $interSectionClasses as $interSectionClassName ) {
                        if( !($arg instanceof $interSectionClassName) ) {
                            $matchesAll = false;
                            break;
                        }
                    }
                    if( $matchesAll ) {
                        return array_splice( $args, $i, 1 )[0];
                    }
                }

                // Serve the next argument as-is, since the parameter may be untyped
                if( $args ) {
                    return array_shift( $args );
                }

                // Serve the default value if available, otherwise return null (no arguments left)
                return $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;

            }, $paramInfo );
        };
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
     * - [0]: The original `ReflectionParameter` instance.
     * - [1]: `string|null` — The class-string if it's a single class type, otherwise `null`.
     * - [2]: `string[]` — An array of built-in types (e.g., `'int'`, `'string'`).
     * - [3]: `string[]` — An array of class-string from union types.
     * - [4]: `string[]` — An array of class-string from intersection types.
     *
     * @param ReflectionParameter[] $parameters An array of reflection parameters from a constructor.
     *
     * @return array<array{0: ReflectionParameter, 1: string|null, 2: string[], 3: string[], 4: string[]}>
     */
    private function parseParameterInfo(array $parameters): array {
        return array_map(
            static function(ReflectionParameter $parameter): array {

                $reflectionType = $parameter->getType();

                // Return early if the parameter is a single class
                if( $reflectionType instanceof ReflectionNamedType && !$reflectionType->isBuiltIn() ) {
                    return [$parameter, $reflectionType->getName(), [], [], []];
                }

                // Return early if the parameter is a single built-in type
                if( $reflectionType instanceof ReflectionNamedType ) {
                    return [$parameter, null, [$reflectionType->getName()], [], []];
                }

                // Handle union types and intersection types
                $builtInTypes = [];
                $unionClasses = [];
                $interSectionClasses = [];

                $reflectionIntersectionTypes = [];

                if( $reflectionType instanceof \ReflectionUnionType ) {
                    foreach( $reflectionType->getTypes() as $reflectionUnionType ) {
                        if( $reflectionUnionType instanceof ReflectionIntersectionType ) {
                            $reflectionIntersectionTypes = array_merge( $reflectionUnionType->getTypes() );
                        } else if( $reflectionUnionType->isBuiltIn() ) {
                            $builtInTypes[] = $reflectionUnionType->getName();
                        } else {
                            $unionClasses[] = $reflectionUnionType->getName();
                        }
                    }
                }

                if( $reflectionType instanceof ReflectionIntersectionType ) {
                    $reflectionIntersectionTypes = array_merge( $reflectionType->getTypes() );
                }

                if( $reflectionIntersectionTypes ) {
                    $interSectionClasses = array_map(
                        static fn(ReflectionNamedType $type) => $type->getName(), // @phpstan-ignore-line - is always a ReflectionNamedType
                        $reflectionIntersectionTypes
                    );
                }

                return [$parameter, null, $builtInTypes, $unionClasses, $interSectionClasses];

            }, $parameters );
    }



}
