#!/usr/bin/env php
<?php
/**
 * Offline probes for the frozen move-ordering state machine.
 *
 * runMoveSequence() is exercised with injected step callables so every
 * failure mode is simulated without network access: add-fail must change
 * nothing, and any failure after a successful add must surface the
 * NEEDS MANUAL DETACH FROM SOURCE message without ever touching the
 * target again.
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

use function SoftwareWrap\TwentyI\runMoveSequence;

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
 * Run one simulated move.
 *
 * Behaviors per step: 'ok' succeeds, 'fail' throws, and for the two
 * verification steps 'false' returns false.
 *
 * @param array<string,string> $behavior
 * @return array{result:array{status:string,message:string},log:array<int,string>}
 */
function simulateMove(array $behavior): array
{
    $log = [];

    $step = function (string $name) use (&$log, $behavior): void {
        $log[] = $name;

        if (($behavior[$name] ?? 'ok') === 'fail') {
            throw new RuntimeException("simulated {$name} failure");
        }
    };

    $verify = function (string $name) use (&$log, $behavior): bool {
        $log[] = $name;
        $mode = $behavior[$name] ?? 'ok';

        if ($mode === 'fail') {
            throw new RuntimeException("simulated {$name} failure");
        }

        return $mode !== 'false';
    };

    $result = runMoveSequence(
        'src-77',
        function () use ($step): void {
            $step('add');
        },
        function () use ($verify): bool {
            return $verify('verifyPresent');
        },
        function () use ($step): void {
            $step('remove');
        },
        function () use ($verify): bool {
            return $verify('verifyAbsent');
        }
    );

    return ['result' => $result, 'log' => $log];
}

const MANUAL_DETACH = 'NEEDS MANUAL DETACH FROM SOURCE (package src-77)';

echo "# happy path\n";

$run = simulateMove([]);
checkSame('moved', $run['result']['status'], 'all steps succeeding yields moved');
checkSame(
    ['add', 'verifyPresent', 'remove', 'verifyAbsent'],
    $run['log'],
    'frozen ordering: add, verify present, remove, verify absent'
);

echo "# add fails: nothing changed\n";

$run = simulateMove(['add' => 'fail']);
checkSame('failed-clean', $run['result']['status'], 'add failure is a clean per-item failure');
checkSame(['add'], $run['log'], 'no step runs after a failed add');
check(
    strpos($run['result']['message'], 'nothing changed') !== false,
    'clean failure message states nothing changed'
);
check(
    strpos($run['result']['message'], 'NEEDS MANUAL DETACH') === false,
    'clean failure never claims a manual detach is needed'
);
check(
    strpos($run['result']['message'], 'simulated add failure') !== false,
    'clean failure carries the underlying error'
);

echo "# verify-present fails after successful add: BOTH state\n";

$run = simulateMove(['verifyPresent' => 'false']);
checkSame('needs-manual-detach', $run['result']['status'], 'unverified target presence needs manual detach');
checkSame(['add', 'verifyPresent'], $run['log'], 'removal is never attempted without target verification');
check(
    strpos($run['result']['message'], MANUAL_DETACH) !== false,
    'message carries the frozen manual-detach phrase with the source package id'
);

$run = simulateMove(['verifyPresent' => 'fail']);
checkSame('needs-manual-detach', $run['result']['status'], 'target verification exception needs manual detach');
checkSame(['add', 'verifyPresent'], $run['log'], 'verification exception stops the sequence');
check(
    strpos($run['result']['message'], MANUAL_DETACH) !== false,
    'verification exception message carries the frozen phrase'
);

echo "# remove fails after successful add: BOTH state\n";

$run = simulateMove(['remove' => 'fail']);
checkSame('needs-manual-detach', $run['result']['status'], 'failed source removal needs manual detach');
checkSame(
    ['add', 'verifyPresent', 'remove'],
    $run['log'],
    'nothing runs after the failed removal (no auto-remove from target)'
);
check(
    strpos($run['result']['message'], MANUAL_DETACH) !== false,
    'removal failure message carries the frozen phrase'
);
check(
    strpos($run['result']['message'], 'simulated remove failure') !== false,
    'removal failure carries the underlying error'
);

echo "# verify-absent fails after successful remove\n";

$run = simulateMove(['verifyAbsent' => 'false']);
checkSame('needs-manual-detach', $run['result']['status'], 'unverified source absence needs manual detach');
checkSame(
    ['add', 'verifyPresent', 'remove', 'verifyAbsent'],
    $run['log'],
    'full sequence ran before the unverified absence'
);
check(
    strpos($run['result']['message'], MANUAL_DETACH) !== false,
    'unverified absence message carries the frozen phrase'
);

$run = simulateMove(['verifyAbsent' => 'fail']);
checkSame('needs-manual-detach', $run['result']['status'], 'source verification exception needs manual detach');
check(
    strpos($run['result']['message'], MANUAL_DETACH) !== false,
    'source verification exception carries the frozen phrase'
);

echo "# the sequence never removes from the target\n";

/*
 * Structural guarantee: runMoveSequence() accepts exactly four steps and
 * none of them is a target-removal. Simulate every failure mode and
 * assert the log never contains anything beyond the four known steps.
 */
$modes = [
    [],
    ['add' => 'fail'],
    ['verifyPresent' => 'false'],
    ['verifyPresent' => 'fail'],
    ['remove' => 'fail'],
    ['verifyAbsent' => 'false'],
    ['verifyAbsent' => 'fail'],
];
$known = ['add', 'verifyPresent', 'remove', 'verifyAbsent'];
$unknownSeen = false;

foreach ($modes as $mode) {
    $run = simulateMove($mode);

    foreach ($run['log'] as $entry) {
        if (!in_array($entry, $known, true)) {
            $unknownSeen = true;
        }
    }
}

check(!$unknownSeen, 'no failure mode triggers any step outside the frozen four');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
