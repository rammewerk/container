<?php

require __DIR__ . '/vendor/autoload.php';

$container = new \Rammewerk\Component\Container\Container();

$class = $container->create( \Rammewerk\Component\Container\Tests\TestData\TestClassC::class, ['Environment is running'] );

echo $class->value;

#phpinfo();