#!/usr/bin/env php
<?php
/**
 * Offline probes for the forward update state machine.
 *
 * The frozen ordering (create new -> verify -> delete old) is exercised
 * with injected failures at every stage. No API key or network access is
 * required. The exit status is non-zero when any assertion failed.
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
require_once __DIR__ . '/../../lib/cli.php';
require_once __DIR__ . '/../../lib/email.php';

use function SoftwareWrap\TwentyI\Email\runUpdateStateMachine;

use const SoftwareWrap\TwentyI\Email\UPDATE_CREATE_FAILED;
use const SoftwareWrap\TwentyI\Email\UPDATE_NEEDS_MANUAL_DELETE;
use const SoftwareWrap\TwentyI\Email\UPDATE_UPDATED;
use const SoftwareWrap\TwentyI\Email\UPDATE_VERIFY_FAILED;

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
 * Build an instrumented run of the state machine.
 *
 * @param bool $createFails Whether the create step throws.
 * @param bool|string $verifyOutcome True/false, or 'throw'.
 * @param bool $deleteFails Whether the delete step throws.
 * @return array{result:array{status:string,message:string},calls:array<int,string>}
 */
function runMachine($createFails, $verifyOutcome, $deleteFails): array
{
    $calls = [];

    $result = runUpdateStateMachine(
        static function () use (&$calls, $createFails): void {
            $calls[] = 'create';

            if ($createFails) {
                throw new RuntimeException('injected create failure');
            }
        },
        static function () use (&$calls, $verifyOutcome): bool {
            $calls[] = 'verify';

            if ($verifyOutcome === 'throw') {
                throw new RuntimeException('injected verify failure');
            }

            return $verifyOutcome === true;
        },
        static function () use (&$calls, $deleteFails): void {
            $calls[] = 'delete';

            if ($deleteFails) {
                throw new RuntimeException('injected delete failure');
            }
        }
    );

    return ['result' => $result, 'calls' => $calls];
}

echo "# happy path\n";

$run = runMachine(false, true, false);

checkSame(UPDATE_UPDATED, $run['result']['status'], 'all steps succeed');
checkSame(
    ['create', 'verify', 'delete'],
    $run['calls'],
    'frozen ordering: create new, verify, then delete old'
);

echo "# create fails: nothing changed\n";

$run = runMachine(true, true, false);

checkSame(UPDATE_CREATE_FAILED, $run['result']['status'], 'create failure reported');
checkSame(
    ['create'],
    $run['calls'],
    'verify and delete never run after a failed create'
);
check(
    stripos($run['result']['message'], 'nothing changed') !== false,
    'message states nothing changed'
);
check(
    stripos($run['result']['message'], 'injected create failure') !== false,
    'message carries the create error detail'
);

echo "# verify fails: old destination is kept\n";

$run = runMachine(false, false, false);

checkSame(UPDATE_VERIFY_FAILED, $run['result']['status'], 'verify=false reported');
checkSame(
    ['create', 'verify'],
    $run['calls'],
    'delete never runs when the new destination is unverified'
);
check(
    stripos($run['result']['message'], 'NOT deleted') !== false,
    'message states the old destination was not deleted'
);

$run = runMachine(false, 'throw', false);

checkSame(UPDATE_VERIFY_FAILED, $run['result']['status'], 'verify exception reported');
checkSame(
    ['create', 'verify'],
    $run['calls'],
    'delete never runs when verification throws'
);

echo "# delete fails after create: both destinations remain\n";

$run = runMachine(false, true, true);

checkSame(
    UPDATE_NEEDS_MANUAL_DELETE,
    $run['result']['status'],
    'late delete failure reported as needs-manual-delete'
);
checkSame(
    ['create', 'verify', 'delete'],
    $run['calls'],
    'create and verify completed before the failing delete'
);
check(
    strpos($run['result']['message'], 'NEEDS MANUAL DELETE (old destination)') === 0,
    'message leads with NEEDS MANUAL DELETE (old destination)'
);
check(
    stripos($run['result']['message'], 'injected delete failure') !== false,
    'message carries the delete error detail'
);
check(
    stripos($run['result']['message'], 'created and verified') !== false,
    'message confirms the new destination stays (never rolled back)'
);

echo "\n{$checks} checks, {$failures} failures\n";

exit($failures === 0 ? 0 : 1);
