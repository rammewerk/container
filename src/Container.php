<?php

namespace Rammewerk\Component\Container;

use Closure;
use ReflectionClass;
use ReflectionMethod;
use ReflectionException;
use ReflectionNamedType;
use InvalidArgumentException;
use Rammewerk\Component\Container\Error\ContainerException;

class Container {

    /**
     * A cache of closures based on class name so each class is only reflected once
     *
     * @var array<class-string, Closure> $cache
     */
    private array $cache = [];

    /**
     * @var array<class-string, object> $instances Stores any instances marked as 'shared' so create() can return the same instance
     */
    private array $instances = [];

    /** @var class-string[] $shared List of shared classes */
    private array $shared = [];

    /** @var array<class-string, class-string|\Closure> $bindings */
    private array $bindings = [];




    /**
     * Define classes which are share instances (singleton) - Immutable
     *
     * @param class-string[] $classes
     *
     * @return $this
     */
    public function share(array $classes): self {
        $container = clone $this;
        foreach( $classes as $class ) {
            unset( $container->instances[$class], $container->cache[$class] );
            $container->shared[] = $class;
        }
        return $container;
    }




    /**
     * Define bindings / substitutions for classes - Immutable
     *
     * @param class-string $interface
     * @param class-string|\Closure $implementation
     *
     * @return $this
     */
    public function bind(string $interface, string|Closure $implementation): self {
        return $this->bindings( [ $interface => $implementation ] );
    }




    /**
     * Define a list of bindings / substitutions for classes - Immutable
     *
     * @param array<class-string, class-string|\Closure> $bindings
     *
     * @return $this
     */
    public function bindings(array $bindings): self {
        $container = clone $this;
        foreach( $bindings as $target => $implementation ) {
            $container->bindings[$target] = $implementation;
        }
        return $container;
    }




    /**
     * Create a fully constructed instance of a class. Using the $args array as class constructor arguments (optional).
     *
     * @template T of object
     *
     * @param class-string<T> $name   The name of the class to instantiate
     * @param array<int, mixed> $args An array of arguments to be passed to the constructor of class
     *
     * @return T A fully constructed class instance
     */
    public function create(string $name, array $args = []): object {

        if( isset( $this->bindings[$name] ) && is_string( $this->bindings[$name] ) ) {
            /** @phpstan-ignore-next-line - Get the binding instead, if registered */
            return $this->create( $this->bindings[$name], $args );
        }

        /**
         * Return shared instance if set.
         *
         * @phpstan-ignore-next-line
         */
        if( ! empty( $this->instances[$name] ) ) return $this->instances[$name];

        if( empty( $this->cache[$name] ) ) try {

            $class = new ReflectionClass( $name );

            # Use the class name from ReflectionClass to normalize use of name
            $name = $class->name;

            if( isset( $this->bindings[$name] ) && is_string( $this->bindings[$name] ) ) {
                /** @phpstan-ignore-next-line - Get the binding instead, if registered */
                return $this->create( $this->bindings[$name], $args );
            }

            /** Redo new check for shared instances
             *
             * @phpstan-ignore-next-line
             */
            if( ! empty( $this->instances[$name] ) ) return $this->instances[$name];


            # Create a closure for creating the object
            if( empty( $this->cache[$name] ) ) $this->cache[$name] = $this->getClosure( $class );

        } catch ( ReflectionException $e ) {
            throw new ContainerException( "Unable to reflect class: $name", $e->getCode(), $e );
        }

        // Return a fully constructed object of $name
        return $this->cache[$name]( $args );

    }




    /**
     * Create a closure for creating the class. Caching reflection objects for better performance.
     *
     * @template T of object
     *
     * @param \ReflectionClass<T> $class
     *
     * @return \Closure(array<int, mixed>):T
     */
    private function getClosure(ReflectionClass $class): Closure {

        # Create parameter generating function in order to cache reflection on the parameters.
        # This way $reflect->getParameters() only ever gets called once
        $params = $this->getParams( $class->getConstructor() );

        # Make PHP throw an exception instead of a fatal error
        if( $class->isInterface() ) $closure = static function () {
            throw new InvalidArgumentException( 'Cannot instantiate an interface' );
        };

        # Get a closure based on the type of object being created: Shared, normal or without constructor
        # If class has dependencies, call the $params closure to generate them based on $args
        else $closure = static function (array $args) use ($class, $params) {
            return $class->newInstance( ...$params( $args ) );
        };

        if( ! in_array( $class->name, $this->shared, true ) ) return $closure;

        return $this->createSharedInstance( $class->name, $closure );

    }




    /**
     * @template T of object
     *
     * @param class-string<T> $name
     * @param \Closure(array<int, mixed>):T $closure
     *
     * @return \Closure(array<int, mixed>):T
     */
    private function createSharedInstance(string $name, Closure $closure): Closure {
        return function (array $args) use ($name, $closure) {
            return $this->instances[$name] = $closure( $args );
        };
    }




    /**
     * @param \ReflectionParameter[] $parameters
     *
     * @return array<array{string|null, \ReflectionParameter, string|null}>
     */
    private function getParamInfo(array $parameters): array {
        return array_map( static function ($param) {

            $type = $param->getType();

            $class = $type instanceof ReflectionNamedType && ! $type->isBuiltIn() ? $type->getName() : null;
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

            return [ $class, $param, $typeName ];

        }, $parameters );
    }




    /**
     * Returns a closure that generates arguments for $method based on $rule and any $args passed into the closure
     *
     * @param \ReflectionMethod|null $method
     *
     * @return \Closure(array<int, mixed>):array<int, mixed>
     */
    private function getParams(?ReflectionMethod $method): Closure {

        # Cache some information about the parameter in $paramInfo so (slow) reflection isn't needed every time
        $paramInfo = $this->getParamInfo( $method instanceof ReflectionMethod ? $method->getParameters() : [] );

        # Return a closure that uses the cached information to generate the arguments for the method
        return function (array $args) use ($paramInfo): array {

            return array_map( function ($paramInfo) use ($args) {

                [ $class, $param, $type ] = $paramInfo;

                # Resolve if parameter is a class
                if( $class ) {

                    # Loop through $args and see whether each value can match the current parameter based on type hint
                    # If the argument matched, add to param and remove it from $args, so it won't wrongly match another parameter
                    foreach( $args as $i => $arg ) {
                        if( $arg instanceof $class || ( $arg === null && $param->allowsNull() ) ) {
                            return array_splice( $args, $i, 1 )[0];
                        }
                    }

                    # If class is this DI Container, return self!
                    if( $class === static::class ) return $this;

                    if( isset( $this->bindings[$class] ) ) {
                        if( $this->bindings[$class] instanceof Closure ) {
                            return $this->bindings[$class]( $this );
                        }
                        return ! $param->allowsNull() && class_exists( $this->bindings[$class] ) ? $this->create( $this->bindings[$class] ) : null;
                    }

                    # Create and assign class to parameters
                    return ! $param->allowsNull() && class_exists( $class ) ? $this->create( $class ) : null;

                }

                if( $args && $type ) {
                    # Find a match in $args for scalar types
                    foreach( $args as $i => $arg ) {
                        $scalar_check_function = "is_$type";
                        if( function_exists( $scalar_check_function ) && $scalar_check_function( $arg ) ) {
                            return array_splice( $args, $i, 1 )[0];
                        }
                    }
                }

                # Resolve if args
                if( $args ) return array_shift( $args );

                return $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;

            }, $paramInfo );

        };

    }

}