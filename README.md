Rammewerk Container
======================

Rammewerk Container is a minimalist yet powerful dependency injection (DI) container for PHP. It uses an efficient,
fully-cached Reflection approach to recursively resolve complex dependencies with minimal configuration.

Designed for PHP 8.4+, it also
leverages [native lazy objects](https://www.php.net/manual/en/language.oop5.lazy-objects.php) to defer initialization
until absolutely necessary — boosting performance and resource efficiency.

#### Key features include:

* **Easy to Use**: Zero-config for basic functions.
* **Lightweight**: A single PHP file under 200 lines of code, no dependencies.
* **Immutable Config**: Fluent and predictable setups.
* **Auto Dependency Resolution**: Less boilerplate, clearer code.
* **Highly Performant**: Caches reflection data for speed.
* **Built-In Lazy Loading** Objects only initialize on demand.
* **IDE & Tools Friendly**: Thorough docblocks and strict PHPStan checks let IDEs (e.g. PhpStorm) accurately
  autocomplete and hint returned classes.

Installation
---------------
Install this package using [Composer](https://getcomposer.org):

```shell
composer require rammewerk/container
```
Requires PHP 8.4 or above.

Container API - using the container
---------------
Rammewerk Container offers 3 core methods for handling dependencies:

* **create**: Builds a fully resolved class (auto-wiring), returning a lazy object that initializes only on first use.
* **share**: Defines shared (singleton) classes, returning the same instance each time. By default, without defining a
  class as shared it will give you a different instance each time.
* **bind/bindings**: Maps interfaces or custom setups to specific implementations, allowing fine-tuned control over
  class loading.

Here's the API in code:

```php
    // Returns fully resolved class of string $name.
    public function create(string $name, array $args = []);
    
    // Define classes that should share instances - immutable
    public function share(array $classes): static;
    
    // Define how a class should be loaded or be substituted
    public function bind(string $interface, string|Closure $implementation): static;
    
    // Same as bind(), but as an array of interface => implementation.
    public function bindings(array $bindings): static;
```

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
until you actually use them. You can disable lazy loading like this:

```php
$container = new Container(false);
```

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

**Without Lazy Loading** (`new Container(false)`):

```php
$container = new Container(false);
$classC = $container->create(ClassC::class);
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

**With Lazy Loading** (the default)

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

By default, each call to create() returns a new object:

```php
$instance1 = $container->create(ClassA::class);
$instance2 = $container->create(ClassA::class);

var_dump($instance1 === $instance2); // false
```

To make instances shared (singletons), call share():

```php
// Returns a new container to preserve immutability
$container = $container->share([ClassA::class]); 

$instance1 = $container->create(ClassA::class);
$instance2 = $container->create(ClassA::class);

var_dump($instance1 === $instance2); // true
```

share() takes an array of class names and ensures you get the same instance each time. Because the container is
immutable, calling share() returns a new container, preventing unwanted side effects.

--

A Note on Container Caching
---------------
Rammewerk Container uses Reflection the first time it encounters a class to map its dependencies. While reflection has a
cost, it’s still very efficient — and the container caches those results. Next time you request the same class (directly
or indirectly), Rammewerk container reuses the cached data. This also extends to lazy objects, making it one of the
fastest DI containers available.

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

Exception Handling
---------------

The Rammewerk Component Container library uses `Rammewerk\Component\Container\Error\ContainerException` for exceptions
thrown during the execution. The exceptions provide information about issues such as failing to reflect a class or
instantiate an interface.

Contribution
---------------
If you have any issues or would like to contribute to the development of this library, feel free to open an issue or
pull request.

License
---------------
The Rammewerk Container is open-sourced software licensed under
the [MIT license](http://opensource.org/licenses/MIT).
