<?php

namespace Rammewerk\Component\Container\Tests\TestData;

readonly class TestClassB {

    public function __construct(public TestClassA $classA) {
    }

}