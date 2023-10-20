<?php

namespace Rammewerk\Component\Container\Tests\TestData;

class TestClassE implements TestClassEInterface {

    public function get(): bool {
        return true;
    }
}