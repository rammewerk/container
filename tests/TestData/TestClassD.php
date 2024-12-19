<?php

namespace Rammewerk\Component\Container\Tests\TestData;

readonly class TestClassD {

    public function __construct(
        private TestClassEInterface $classE
    ) {
    }

    public function getE(): bool {
        return $this->classE->get();
    }

}