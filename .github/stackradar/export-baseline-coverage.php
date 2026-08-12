<?php

declare(strict_types=1);

if ($argc !== 7) {
    fwrite(STDERR, "Expected autoload, coverage, repository, SHA, run ID, and output path.\n");
    exit(2);
}

[$script, $autoload, $coveragePath, $repository, $baseSha, $runId, $outputPath] = $argv;

require $autoload;

$coverage = require $coveragePath;
$lock = json_decode((string) file_get_contents('composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$packageRoots = [];
$packages = [];

foreach (['packages', 'packages-dev'] as $section) {
    foreach ($lock[$section] ?? [] as $package) {
        $dependency = $package['name'] ?? null;

        if (! is_string($dependency)) {
            continue;
        }

        $packageRoot = realpath('vendor/'.$dependency);

        if (! is_string($packageRoot)) {
            continue;
        }

        $source = $package['source'] ?? [];
        $packageRoots[rtrim(str_replace('\\', '/', $packageRoot), '/').'/'] = $dependency;
        $packages[$dependency] = [
            'version' => $package['version'] ?? null,
            'source_ref' => is_array($source) ? ($source['reference'] ?? null) : null,
            'line_tests' => [],
        ];
    }
}

uksort($packageRoots, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

foreach ($coverage->getData()->lineCoverage() as $absolutePath => $lines) {
    $resolvedPath = realpath($absolutePath);
    $normalizedPath = str_replace(
        '\\',
        '/',
        is_string($resolvedPath) ? $resolvedPath : $absolutePath,
    );
    $packageRoot = null;
    $dependency = null;

    foreach ($packageRoots as $candidateRoot => $candidateDependency) {
        if (str_starts_with($normalizedPath, $candidateRoot)) {
            $packageRoot = $candidateRoot;
            $dependency = $candidateDependency;
            break;
        }
    }

    if (! is_string($packageRoot) || ! is_string($dependency)) {
        continue;
    }

    $relativePath = substr($normalizedPath, strlen($packageRoot));

    foreach ($lines as $line => $testIds) {
        if ($testIds === null) {
            continue;
        }

        $key = $relativePath.':'.$line;
        $packages[$dependency]['line_tests'][$key] = array_values(array_unique($testIds));
        sort($packages[$dependency]['line_tests'][$key]);
    }
}

ksort($packages);

foreach ($packages as &$package) {
    ksort($package['line_tests']);
}
unset($package);

$payload = [
    'schema_version' => 2,
    'repository' => $repository,
    'base_sha' => $baseSha,
    'run_id' => (int) $runId,
    'created_at' => gmdate(DATE_ATOM),
    'collector' => 'phpunit-xdebug-per-test-composer-baseline',
    'packages' => $packages,
];

$outputDirectory = dirname($outputPath);

if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0777, true) && ! is_dir($outputDirectory)) {
    fwrite(STDERR, "Could not create output directory.\n");
    exit(2);
}

file_put_contents(
    $outputPath,
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
);
