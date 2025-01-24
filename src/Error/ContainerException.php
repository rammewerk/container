<?php

declare(strict_types=1);

namespace Rammewerk\Component\Container\Error;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

class ContainerException extends RuntimeException implements ContainerExceptionInterface {}