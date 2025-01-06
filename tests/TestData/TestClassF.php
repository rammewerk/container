<?php

namespace Rammewerk\Component\Container\Tests\TestData;

class TestClassF implements TestClassEInterface {

    public ?string $init_value = null;



    public function __construct(
        private readonly TestClassB $classB,
        string $variable
    ) {
        $this->init_value = $variable;
    }



    public function get(): bool {
        return isset( $this->classB->classA ) && !empty( $this->init_value );
    }


}