Rammewerk Container
======================

A simple, yet powerful dependency injection container for PHP.

Getting Started
---------------

```php
use Rammewerk\Component\Container\Container;

$container = new \Rammewerk\Component\Container\Container();

$container->share('class_name');
$container->create('class_name');

```