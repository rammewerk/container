<?php

use Rammewerk\Component\Container\Container;

require __DIR__ . '/vendor/autoload.php';

$container = new Container();

// Start time
$startTime = hrtime(true);

# Garbage collector
gc_collect_cycles();

// Perform the loop
$loops = 100000;

$info = [];

for ($i = 0; $i < $loops; $i++) {
    $class = $container->create(\Rammewerk\Component\Container\Tests\TestData\TestClassF::class, ['test']);
}

// End time
$endTime = hrtime(true);

// Calculate elapsed time in milliseconds
$elapsedTimeMs = ($endTime - $startTime) / 1e6;
$elapsedTimeMs = round($elapsedTimeMs);

// Get peak memory usage in kilobytes
$peakMemoryKb = memory_get_peak_usage(true) / 1024;
$peakMemoryKb = number_format($peakMemoryKb, 0, ',', ' ');

echo "Elapsed Time: {$elapsedTimeMs} ms\n";
echo "Peak Memory Usage: {$peakMemoryKb} KB\n";

#phpinfo();