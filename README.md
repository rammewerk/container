Rammewerk Container
======================

The Rammewerk Container is a minimalist dependency injection container, which aims to resolve complex dependencies in a
simple, intuitive way.

This PHP library allows developers to handle dependency injection, and handle shared instances, bindings, and parameters
in an object-oriented manner.

* **Easy to Use**: Designed to be intuitive and easy to get started with. No configuration needed for basic functions.
* **Lightweight**: A single class of approximately 120 lines of code.
* **Immutable Configuration**: Configurations are defined in a fluent, immutable manner, allowing for safe and
  predictable setups.
* **Automatic Dependency Resolution**: The container resolves dependencies automatically, reducing boilerplate and
  improving readability.
* **Highly Performant**: By caching reflection results, the library ensures optimal performance, making it suitable for
  demanding applications.

*Requires PHP 8.4 or higher.*

Installation
---------------

Install this package using Composer:

```shell
composer require rammewerk/container
```

## Features

* Allows definition of classes which are shared instances (Singleton)
* Provides bindings/substitutions for classes
* Ability to create a fully constructed instance of a class
* Supports caching of class constructors for optimized performance

## Usage

Here is an example of how you can use the Rammewerk Container:

```php
<?php

require 'vendor/autoload.php';

use Rammewerk\Component\Container\Container;

$container = new Container;

// Mark classes as shared (Singleton)
$container = $container->share([
    \Some\Shared\Class::class,
    'Another\Shared\Class',
]);

// Define a bindings/substitutions for classes
$container = $container->bind('Some\Interface', 'Some\Implementation');

// Or define a list of bindings
$container = $container->bindings([
    'Another\Interface' => 'Another\Implementation',
    'Some\Interface' => 'Some\Implementation',
    'YetAnother\Interface' => function( Container $container) {
        // Create object
        return $container->create(\YetAnother\Implementation::class, ['first_argument'])
    }
]);

// Create a fully constructed instance of a class
$instance = $container->create('Some\Class');

```

### Define shared classes

Use the `share` method to define which classes should be shared instances (Singleton). The method accepts an array of
class names.

```php
$container = $container->share([
    Request::class,
    Auth\Auth::class,
]);
```

### Define bindings / substitutions for classes

The `bind` method allows you to define a binding or substitution for a specific interface/class to a concrete class. This
method accepts two parameters: the interface/class name and the concrete implementation.

```php
$container = $container->bind('Some\Interface', 'Some\Implementation');
```

To define a list of bindings, you can use the `bindings` method. This method accepts an array of key-value pairs, where
the key is the interface and the value is the concrete implementation.

```php
$container = $container->bindings([
    'Another\Interface' => 'Another\Implementation',
    'YetAnother\Interface' => function() {
        // Create object
        return new YetAnotherImplementation;
    },
]);
```

### Create a fully constructed instance of a class

The `create` method is used to create a fully constructed instance of a class. This method accepts two parameters: the
class name and an optional array of arguments to be passed to the class constructor.

```php
$instance = $container->create('Some\Class');
```

## Exception Handling

The Rammewerk Component Container library uses `Rammewerk\Component\Container\Error\ContainerException` for exceptions
thrown during the execution. The exceptions provide information about issues such as failing to reflect a class or
instantiate an interface.

## Contribution

If you have any issues or would like to contribute to the development of this library, feel free to open an issue or
pull request.

## License

The Rammewerk Container is open-sourced software licensed under
the [MIT license](http://opensource.org/licenses/MIT).
