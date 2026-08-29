#!/usr/bin/env php
<?php
/**
 * Offline probes for the pre-detach zone snapshot helpers: state
 * directory resolution, payload shape, and JSON Lines file writing with
 * restrictive permissions.
 *
 * No API key or network access is required. Temporary files are written
 * under the system temp directory and removed afterwards.
 *
 * This file is part of a software project licensed under the
 * GNU General Public License v3.0.
 *
 * Copyright (C) 2026
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

error_reporting(E_ALL);

require_once __DIR__ . '/../../lib/package.php';
require_once __DIR__ . '/../../lib/zone-records.php';

use function SoftwareWrap\TwentyI\buildZoneSnapshotPayload;
use function SoftwareWrap\TwentyI\resolveStateDirectory;
use function SoftwareWrap\TwentyI\writeZoneSnapshotFile;

$failures = 0;
$checks = 0;

/**
 * Assert one expectation.
 */
function check(bool $condition, string $label): void
{
    global $failures, $checks;
    $checks++;

    if ($condition) {
        echo "ok {$checks} - {$label}\n";

        return;
    }

    $failures++;
    echo "FAIL {$checks} - {$label}\n";
}

/**
 * Assert two values are identical.
 *
 * @param mixed $expected
 * @param mixed $actual
 */
function checkSame($expected, $actual, string $label): void
{
    check(
        $expected === $actual,
        "{$label} (" . var_export($expected, true) . ' === '
            . var_export($actual, true) . ')'
    );
}

/**
 * Assert a callable throws with a message containing the needle.
 */
function expectThrows(callable $fn, string $needle, string $label): void
{
    try {
        $fn();
        check(false, "{$label} (no exception thrown)");
    } catch (Throwable $exception) {
        check(
            strpos($exception->getMessage(), $needle) !== false,
            "{$label} (message: {$exception->getMessage()})"
        );
    }
}

/**
 * Remove a test directory tree.
 */
function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $entries = scandir($path);

    if ($entries !== false) {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($child)) {
                removeTree($child);
            } else {
                @unlink($child);
            }
        }
    }

    @rmdir($path);
}

echo "# resolveStateDirectory\n";

$originalXdg = getenv('XDG_STATE_HOME');
$originalHome = getenv('HOME');

putenv('XDG_STATE_HOME=/custom/state');
checkSame(
    '/custom/state' . DIRECTORY_SEPARATOR . '20i-cli',
    resolveStateDirectory(),
    'XDG_STATE_HOME wins when set'
);

putenv('XDG_STATE_HOME= ');
putenv('HOME=/home/probe');
checkSame(
    '/home/probe' . DIRECTORY_SEPARATOR . '.local'
        . DIRECTORY_SEPARATOR . 'state'
        . DIRECTORY_SEPARATOR . '20i-cli',
    resolveStateDirectory(),
    'blank XDG_STATE_HOME falls back to HOME/.local/state'
);

putenv('XDG_STATE_HOME=/trailing/slash/');
checkSame(
    '/trailing/slash' . DIRECTORY_SEPARATOR . '20i-cli',
    resolveStateDirectory(),
    'trailing separator is trimmed'
);

/*
 * Restore the environment for later groups.
 */
putenv($originalXdg === false ? 'XDG_STATE_HOME' : "XDG_STATE_HOME={$originalXdg}");
putenv($originalHome === false ? 'HOME' : "HOME={$originalHome}");

echo "# buildZoneSnapshotPayload\n";

$zoneMap = [
    'Example.COM.' => [
        'records' => [
            ['host' => 'example.com', 'type' => 'A', 'ip' => '203.0.113.10', 'ref' => 101],
            ['host' => 'www.example.com', 'type' => 'CNAME', 'target' => 'example.com.', 'ref' => 102],
            ['host' => 'example.com', 'type' => 'TXT', 'txt' => 'v=spf1 -all', 'ref' => 103],
        ],
    ],
];

$payload = buildZoneSnapshotPayload($zoneMap, 'Example.COM', '716033');
checkSame('example.com', $payload['domain'], 'payload domain is normalized');
checkSame(true, $payload['ok'], 'payload reports ok');
checkSame('716033', $payload['packageId'], 'payload carries the package id');
checkSame('example.com', $payload['apiZone'], 'payload zone is normalized');
checkSame(['api' => true], $payload['sources'], 'payload declares the api source');
checkSame(3, count($payload['records']), 'every zone record is captured');
checkSame(
    101,
    $payload['records'][0]['fields']['ref'],
    'raw fields including ref survive in the snapshot'
);
checkSame('A', $payload['records'][0]['type'], 'records are dump-normalized');
check($payload['errors'] instanceof stdClass, 'errors encodes as an empty object');

$subPayload = buildZoneSnapshotPayload($zoneMap, 'www.example.com', '716033');
checkSame(
    1,
    count($subPayload['records']),
    'subdomain snapshot narrows to records owned by the subdomain'
);

expectThrows(
    function () use ($zoneMap): void {
        buildZoneSnapshotPayload($zoneMap, 'unrelated.org', '716033');
    },
    'No DNS zone covers',
    'uncovered domain refuses to produce an empty snapshot'
);

echo "# writeZoneSnapshotFile\n";

$testRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . '20i-domain-snapshot-test-' . getmypid();
removeTree($testRoot);
$snapshotDir = $testRoot . DIRECTORY_SEPARATOR . 'snapshots';

$path = writeZoneSnapshotFile(
    $snapshotDir,
    'Example.COM',
    $payload,
    '20260830T120000Z'
);

checkSame(
    $snapshotDir . DIRECTORY_SEPARATOR . 'example.com-20260830T120000Z.jsonl',
    $path,
    'file name is <normalized-domain>-<utcstamp>.jsonl'
);
check(is_file($path), 'snapshot file exists');
checkSame(
    '0700',
    substr(sprintf('%o', fileperms($snapshotDir)), -4),
    'snapshot directory is 0700'
);
checkSame(
    '0600',
    substr(sprintf('%o', fileperms($path)), -4),
    'snapshot file is 0600'
);

$contents = (string) file_get_contents($path);
checkSame("\n", substr($contents, -1), 'file ends with a newline (JSON Lines)');
checkSame(1, substr_count($contents, "\n"), 'exactly one line per snapshot file');

$decoded = json_decode(trim($contents), true);
check(is_array($decoded), 'snapshot line is valid JSON');
checkSame('example.com', $decoded['domain'], 'decoded snapshot keeps the domain');
checkSame([], $decoded['errors'], 'stdClass errors decode as an empty set');
checkSame(
    101,
    $decoded['records'][0]['fields']['ref'],
    'decoded snapshot preserves raw record refs'
);
checkSame(
    'v=spf1 -all',
    $decoded['records'][2]['rdata'],
    'decoded snapshot preserves record rdata'
);

$defaultStampPath = writeZoneSnapshotFile($snapshotDir, 'example.com', $payload);
check(
    preg_match(
        '/example\.com-\d{8}T\d{6}Z\.jsonl$/',
        $defaultStampPath
    ) === 1,
    'default stamp is a UTC timestamp'
);

expectThrows(
    function () use ($snapshotDir): void {
        $resource = fopen('php://memory', 'r');
        try {
            writeZoneSnapshotFile(
                $snapshotDir,
                'bad.example.com',
                ['unencodable' => $resource],
                '20260830T120001Z'
            );
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    },
    'Unable to encode',
    'unencodable payload fails loudly instead of writing garbage'
);
check(
    !file_exists($snapshotDir . DIRECTORY_SEPARATOR . 'bad.example.com-20260830T120001Z.jsonl'),
    'failed encode leaves no partial snapshot file'
);

removeTree($testRoot);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
