<?php

require __DIR__ . '/vendor/autoload.php';

$container = new \Rammewerk\Component\Container\Container( true );



$class = $container->create( \Rammewerk\Component\Container\Tests\TestData\TestParamA::class, ['the variable is here'] );

echo 'Let us inspect the container<br>';

#echo $class->get();

#echo $class->get();


#phpinfo();