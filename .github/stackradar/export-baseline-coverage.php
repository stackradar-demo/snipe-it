<?php

declare(strict_types=1);

if ($argc !== 8) {
    fwrite(STDERR, "Expected autoload, coverage, repository, SHA, run ID, dependency, and output directory.\n");
    exit(2);
}

[$script, $autoload, $coveragePath, $repository, $baseSha, $runId, $dependency, $outputDirectory] = $argv;

require $autoload;

$coverage = require $coveragePath;
$lock = json_decode((string) file_get_contents('composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$package = null;

foreach (['packages', 'packages-dev'] as $section) {
    foreach ($lock[$section] ?? [] as $candidate) {
        if (($candidate['name'] ?? null) === $dependency) {
            $package = $candidate;
            break 2;
        }
    }
}

if (! is_array($package)) {
    fwrite(STDERR, "Dependency {$dependency} was not found in composer.lock.\n");
    exit(2);
}

$packageRoot = realpath('vendor/'.$dependency);

if (! is_string($packageRoot)) {
    fwrite(STDERR, "Installed dependency {$dependency} was not found.\n");
    exit(2);
}

$packageRoot = rtrim(str_replace('\\', '/', $packageRoot), '/').'/';
$lineTests = [];

foreach ($coverage->getData()->lineCoverage() as $absolutePath => $lines) {
    $normalizedPath = str_replace('\\', '/', $absolutePath);

    if (! str_starts_with($normalizedPath, $packageRoot)) {
        continue;
    }

    $relativePath = substr($normalizedPath, strlen($packageRoot));

    foreach ($lines as $line => $testIds) {
        if ($testIds === null) {
            continue;
        }

        $lineTests[$relativePath.':'.$line] = array_values(array_unique($testIds));
        sort($lineTests[$relativePath.':'.$line]);
    }
}

ksort($lineTests);
$source = $package['source'] ?? [];
$payload = [
    'schema_version' => 1,
    'repository' => $repository,
    'base_sha' => $baseSha,
    'run_id' => (int) $runId,
    'created_at' => gmdate(DATE_ATOM),
    'dependency' => $dependency,
    'dependency_version' => $package['version'] ?? null,
    'dependency_source_ref' => is_array($source) ? ($source['reference'] ?? null) : null,
    'collector' => 'phpunit-xdebug-per-test-baseline',
    'line_tests' => $lineTests,
];

if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0777, true) && ! is_dir($outputDirectory)) {
    fwrite(STDERR, "Could not create output directory.\n");
    exit(2);
}

$fileName = str_replace('/', '--', $dependency).'.json';
file_put_contents(
    rtrim($outputDirectory, '/').'/'.$fileName,
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
);
