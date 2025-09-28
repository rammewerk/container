Rammewerk Container
======================

Rammewerk Container is an elegant, high-performance **dependency injection container** for PHP, designed to simplify
development with minimal to no configuration. Built exclusively for PHP 8.4+, it embraces modern coding standards and
avoids legacy baggage.

With its fully-cached Reflection mechanism and
[native lazy objects](https://www.php.net/manual/en/language.oop5.lazy-objects.php), it resolves complex
dependencies efficiently while deferring initialization to boost performance. Proven to be one of the fastest DI
containers in benchmarks, Rammewerk Container offers a smart, streamlined solution for modern PHP projects.

#### Key features include:

* **Easy to Use**: Zero-config for basic functions.
* **Lightweight**: A single PHP file with less than 300 lines of code, no dependencies.
* **Immutable Config**: Fluent and predictable setups.
* **Autowire Dependencies**: Less boilerplate, clearer code.
* **Highly Performant**: Caches reflection data for speed, proven well in benchmarks
* **Built-In Lazy Loading** Objects only initialize on demand.
* **IDE & Tools Friendly**: Thorough docblocks and strict PHPStan checks let IDEs (e.g. PhpStorm) accurately
  autocomplete and hint returned classes.
* **[PSR-11 Support](#psr-11-support)**: PSR-11 ContainerInterface support through an extended adapter.

Installation
---------------
Install this package using [Composer](https://getcomposer.org):

```shell
composer require rammewerk/container
```

*Requires PHP 8.4 or above.*

Container API
---------------
Rammewerk Container provides three essential methods for managing dependencies:

### Create

```php
$config = $container->create(Config::class);
```

Instantiates and auto-wires a fully resolved class. Can also create instances with arguments.

```php
$config = $container->create(Template::class, [TEMPLATE_DIR]);
```

### Share (singleton)

Registers shared (singleton) classes, ensuring the same instance is returned on every request. By default,
instances are not shared unless explicitly defined.

```php
$container = $container->share([
    Logger::class, 
    Config::class
]);
```

### Bind

`bind` / `bindings`: Maps interfaces or abstract types to concrete implementations, offering fine-grained control over
dependency resolution.

```php
// Let the container instantiate the concrete class
$container = $container->bind(LoggerInterface::class, FileLogger::class);

// Bind an already instantiated class
$container = $container->bind(LoggerInterface::class, new Logger());

// Add a list of bindings
$container = $container->bindings([
    CacheInterface::class => RedisCache::class,
    QueueInterface::class => ClosureQueue::class,
]);

// Bind a closure to instantiate a class
$container = $container->bind(TemplateResponse::class, static function(Container $c) {
    return $c->create(TwigTemplate::class, [TEMPLATE_DIR])
})


```

*To use PSR-11 container interface, see details at the bottom of this README*

Basic Usage
---------------
Rammewerk Container automatically resolves and instantiates class dependencies. If PaymentGateway needs
PaymentProcessor, and PaymentProcessor needs Logger, the container figures it all out with no extra setup.

Consider these 3 classes:

```php
class Logger {
    public function log(string $message): void {
        echo "Log: $message";
    }
}

class PaymentProcessor {
    
    public function __construct(
        private Logger $logger
    ) {}

    public function processPayment(float $amount): void {
        $this->logger->log("Processing payment of $$amount.");
    }
    
}

class PaymentGateway {
    
    public function __construct(
        private PaymentProcessor $processor
    ) {}

    public function pay(float $amount): void {
        $this->processor->processPayment($amount);
    }
}

```

**Without** a DI container:

```php
  $logger = new Logger();
  $process = new PaymentProcessor($logger);
  $gateway = new PaymentGateway($process);

  $gateway->pay(10); // Log: Processing payment of $10
```

**With** Rammewerk Container:

```php
  $container = new Container();
  $gateway = $container->create(PaymentGateway::class);
  $gateway->pay(20) // Log: Processing payment of $20
```

All dependencies are automatically resolved — no extra wiring required.

Non-Class Parameters
---------------

Not all dependencies are classes. Sometimes you need to pass other values (like strings, numbers or arrays). Here’s how:

```php
class PaymentGateway {
    public function __construct(
        private PaymentProcessor $processor,
        private string $processName // Newly added string parameter
    ) {}
}
```

The DI container will require a value for $processName. Provide it during creation:

```php
$container = new Container();
$gateway = $container->create(PaymentGateway::class, ['PayPal']);
$gateway->pay(20); // Log: Processing payment of $20
```

`create()` accepts two arguments:

1. The class name (string).
2. An optional array of constructor parameters.

Here, "PayPal" automatically satisfies the string parameter. You don’t need to manually specify PaymentProcessor — the
container still resolves that class for you.

Understanding the Lazy Object Feature
---------------

By default, **Rammewerk Container**
creates [lazy objects](https://www.php.net/manual/en/language.oop5.lazy-objects.php), meaning classes aren’t initialized
until you actually use them.

Let's look at an example:

```php
class ClassA {
    public function __construct() {
        echo 'Class A initialized'
    }
    public function hello(): void {
        echo 'Class A says hello'
    }
}

class ClassB {
    public function __construct() {
        echo 'Class B initialized'
    }
}

class ClassC {
    public function __construct( 
        public ClassA $a,
        private ClassB $b
    ) {
        echo 'Class C initialized';
    }
}
```

**Without Lazy Loading**

```php
// If the DI does not support lazy proxy:
$classC = $container->get(ClassC::class);
echo 'Here we go:';
$classC->a->hello();
```

Output:

```html
Class A initialized
Class B initialized
Class C initialized
Here we go:
Class A says hello
```

**With Lazy Loading**

```php
$container = new Container();
$classC = $container->create(ClassC::class);
echo 'Here we go:';
$classC->a->hello();
```

Output:

```html
Here we go:
Class A initialized
Class C initialized
Class A says hello
```

This example shows that ClassC doesn’t initialize until you actually use it. Likewise, ClassB remains uninitialized
unless it’s needed—even though it’s declared in ClassC. This boosts efficiency by loading only what’s truly required.

Shared Instances
---------------

By default, each call to create() returns a new instance:

```php
$instance1 = $container->create(ClassA::class);
$instance2 = $container->create(ClassA::class);

var_dump($instance1 === $instance2); // false
```

To make instances shared (singletons), class name must be defined as shared:

```php
// Returns a new container to preserve immutability
$container = $container->share([ClassA::class]); 

$instance1 = $container->create(ClassA::class);
$instance2 = $container->create(ClassA::class);

var_dump($instance1 === $instance2); // true
```

share() takes an array of class names and ensures you get the same instance each time. Because the container is
immutable, calling share() returns a new container, preventing unwanted side effects.

Bindings / Implementations
---------------
Not all classes have concrete type-hints. Some might depend on interfaces or abstract classes, while others accept
scalars or arrays. In these cases, you can guide the container by binding specific implementations or values to those
parameters.

For example, suppose you have a NewsMailer interface and a MailChimp class that implements it:

```php
interface NewsMailer {}

class MailChimp implements NewsMailer {}

class Newsletter {
    public function __construct(private readonly NewsMailer $mailer) {}
}
```

To tell Rammewerk Container which concrete class to use for NewsMailer, do:

```php 
// Returns a new container instance to maintain immutability
$container = $container->bind(NewsMailer::class, MailChimp::class);
```

Now, whenever the container encounters NewsMailer, it will use MailChimp.

### Multiple Bindings at Once

You can also define multiple bindings together:

```php 
$container = $container->bindings([
  NewsMailer::class => MailChimp::class,
  Mailer::class     => Gmail::class,
]);
```

This is more performant and keeps your setup concise.

### Closure for More Custom Setup

Sometimes, you need extra configuration before returning a class. In these cases, Rammewerk Container lets you pass a
\Closure as the implementation:

```php
$container = $container->bind(
    \Twig\Loader\LoaderInterface::class,
    static function (Container $container) {
        return $container->create(\Twig\Loader\FilesystemLoader::class, [TEMPLATE_DIR]);
    }
);
```

Here, FilesystemLoader needs a template directory (TEMPLATE_DIR), so we define a closure. The container is passed in
automatically, letting you create or retrieve other dependencies as needed. This way, whenever the container encounters
Twig\Loader\LoaderInterface, it returns a fully configured FilesystemLoader.

How the Container Resolves Dependencies
---------------

The container resolves dependencies by processing each constructor parameter in order. It tries to automatically resolve
parameters when possible, using class types, built-in types, or default values. If needed, you can pass an array of
arguments to the create() method, and the container will intelligently match each argument to the first compatible
parameter.

For example, given a class with dependencies:

```php
public function __construct(
    ClassA $a,
    ?int $b,
    string $c
) {}
```

```php
$container->create(ServiceA::class, ['string-value']);
```

The container will:

1. Resolve ClassA automatically.
2. Assign null to the nullable int.
3. Use 'string-value' for the string parameter.

This is especially useful with bindings defined as closures. Since the closure receives the container, it can call
create() with custom arguments:

```php
$container->bind(ClassOrInterface::class, fn(Container $c) => $c->create(Implementation::class, ['string-value', 20]));
```

This allows custom values to be injected while relying on the container for automatic resolution of other dependencies,
significantly reducing the amount of logic needed to set up the system. Unlike many other DI containers, which often
require multiple bindings, factories, or definitions for similar functionality, this approach simplifies the process by
minimizing the need for extensive manual setup.

This is the processing order that the container uses to resolve dependencies:

1. [Class types](#class-dependencies) (ClassA, ClassB, etc.)
2. [Built-in types](#built-in-types) (int, string, array, etc.)
3. [Union types](#union-types) (ClassA|ClassB, ClassC|ClassD, etc.)
4. [Intersection types](#intersection-types) (ClassA&ClassB, ClassC&ClassD, etc.)
5. Any [leftover arguments](#unresolved-arguments) are passed as-is, as it may be untyped or not resolved by the
   container.
6. [Default values](#default-values) (if available and not anymore arguments)

#### Class dependencies

If the constructor parameter is a class type and an argument matching the type is provided, the container will use that
argument. If no such argument is provided:

- If a closure binding is defined for the class, the closure will be used to resolve the dependency.
- If the class type is the container itself, the container instance is returned.
- Otherwise, an instance of the class will be created unless the parameter allows null, in which case null is returned.

#### Built-in types

If the parameter is a built-in type (int, string, array etc.), the container will search for the first argument that
matches the built-in type and use it. If no matching argument is found, it continues to the next resolution strategy.

#### Union types

The container handles union types (ClassA|string, ClassB|ClassC, etc.) by searching through the provided arguments and
returning the first argument that matches any type in the union. This includes both class types and built-in types.

- If the argument matches a class type in the union (e.g., ClassA), it will return that class instance.
- If the argument matches a built-in type in the union (e.g., string, int), it will return the argument as-is.
- If no matching argument is found, and the parameter allows null, it will return null.

#### Intersection types

The container will search for the first argument that implements all the required classes or interfaces in the
intersection type. If no such argument is found, it continues to the next resolution strategy. Intersection types are
not autowired; use the bind() method to handle them properly.

#### Unresolved arguments

If the parameter has no type hint, the container will use the next available argument as-is.

#### Default values:

If no argument is provided and none of the above strategies resolve the parameter, the container will return the default
value of the parameter if available. If no default value is defined, null will be returned. This may provoke an error
if the parameter is required and no argument is provided and isn't nullable. THe container will not be able to resolve
the dependency.

This approach ensures that the container can handle a wide range of parameter types, including built-in types, union
types, and intersection types, while offering flexibility for custom bindings through closures.

Exception Handling
---------------

The Rammewerk Component Container library uses `Rammewerk\Component\Container\Error\ContainerException` for exceptions
thrown during the execution. The exceptions provide information about issues such as failing to reflect a class or
instantiate an interface.

A Note on Container Caching
---------------
Rammewerk Container uses Reflection the first time it encounters a class to map its dependencies. While reflection has a
cost, it’s still very efficient — and the container caches those results. Next time you request the same class (directly
or indirectly), Rammewerk container reuses the cached data. This also extends to lazy objects, making it one of the
fastest DI containers available.

For benchmark results see: https://github.com/rammewerk/php-di-container-benchmarks

Worker Mode Support (FrankenPHP)
---------------

Rammewerk Container is optimized for worker mode environments like FrankenPHP, where the application stays in memory between requests. The container provides a `fork()` method that creates isolated instances while preserving the expensive reflection cache for optimal performance.

### The Challenge

In worker mode, shared singleton instances can leak between requests, potentially causing data contamination. Traditional solutions like `flushInstances()` clear all instances but also lose the performance benefits of cached reflection data.

### The Solution

The `fork()` method creates a new container that:
- **Preserves reflection cache** for fast instantiation
- **Isolates instances** between requests
- **Maintains configuration** (bindings, shared classes)

```php
// Set up your container (typically once at startup)
$container = new Container();
$container = $container->share([Logger::class, Database::class]);

// For each request in worker mode:
$requestContainer = $container->fork();

// Use the forked container for the request
$service = $requestContainer->create(UserService::class);
```

### How It Works

PHP's `clone` operator performs a shallow clone, which means:
- **Reflection cache is shared** (good for performance)
- **Instance arrays are copied and flushed** (good for isolation)
- **Configuration is preserved** (bindings, shared settings)

This approach gives you the best of both worlds: worker-mode safety with maximum performance.

For benchmark results see: https://github.com/rammewerk/php-di-container-benchmarks

PSR-11 Support
---------------
The container supports PSR-11: ContainerInterface through an extended implementation called `PsrContainer`. Since PSR-11
only defines `get()` and `has()` methods and does not dictate how dependencies should be resolved, we chose not to make
it
the default implementation. Including PSR-11 as the default could lead to confusion about how to properly use the
`create()` method, which offers greater flexibility in resolving dependencies by allowing additional arguments to be
passed during instantiation ([read more on this below](#how-the-container-resolves-dependencies)).

If you prefer to use the PSR-11 interface, you can do so by using `PsrContainer`, which provides standard `has()` and
`get()` methods. However, note that this approach requires more explicit setup using the `bind()` method to define how
dependencies should be resolved.

Note that the `has()` method will most likely always return `true`, since the container doesn't require you to define
a factory/binding for every class. I would recommend you to view the source code of `PsrContainer` to see how it works.

Both `PsrContainer` and the base `Container` are fully extendable, allowing you to implement your own strategies for
setting up and managing dependencies as needed.

Contribution
---------------
If you have any issues or would like to contribute to the development of this library, feel free to open an issue or
pull request.

License
---------------
The Rammewerk Container is open-sourced software licensed under
the [MIT license](http://opensource.org/licenses/MIT).
