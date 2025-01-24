<?php

declare(strict_types=1);

namespace Rammewerk\Component\Container;

use Psr\Container\ContainerInterface;

class PsrContainer extends Container implements ContainerInterface {



    /**
     * @template T of object
     * @param class-string<T> $id
     *
     * @return T
     */
    public function get(string $id) {
        return $this->instances[$id] ?? $this->create($id);
    }



    /**
     * PSR-11 Implementation.
     * As this container will automatically resolve class-strings it will
     * result in true as long as binding is defined and class exist.
     */
    public function has(string $id): bool {
        if (isset($this->bindings[$id])) {
            return true;
        }
        // Return false if $id is an interface, because binding does not exist.
        if (interface_exists($id)) {
            return false;
        }
        if (class_exists($id)) {
            return true;
        }
        return false;
    }


}
