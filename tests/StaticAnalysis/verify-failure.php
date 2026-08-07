<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$arguments = [
    PHP_BINARY,
    $projectRoot.'/vendor/bin/phpstan',
    'analyse',
    '--configuration='.__DIR__.'/phpstan.neon',
    '--no-progress',
    __DIR__.'/Fixtures/InvalidReturnType.php',
];
$command = implode(' ', array_map('escapeshellarg', $arguments));

passthru($command, $exitCode);

if ($exitCode === 0) {
    fwrite(STDERR, "Expected static analysis to reject the invalid fixture.\n");
    exit(1);
}

fwrite(STDOUT, "Static-analysis failure regression passed with exit code {$exitCode}.\n");
