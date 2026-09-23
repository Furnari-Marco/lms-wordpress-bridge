<?php
/**
 * Test runner: php tests/run.php [filter]
 *
 * Loads every tests/*.test.php file, runs each test() in a fresh
 * environment and prints a summary. Exit code 1 on any failure.
 */
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';
foreach (glob(__DIR__ . '/*.test.php') as $file) {
    if ($filter && strpos(basename($file), $filter) === false) continue;
    require_once $file;
}

$pass = 0; $fail = 0;
foreach ($GLOBALS['lwb_tests'] as [$name, $fn]) {
    lwb_test_reset();
    try {
        $fn();
        $pass++;
        echo "  ok   $name\n";
    } catch (LwbAssertionFailed $e) {
        $fail++;
        echo "  FAIL $name\n       " . $e->getMessage() . "\n";
    } catch (Throwable $e) {
        $fail++;
        echo "  FAIL $name\n       " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
