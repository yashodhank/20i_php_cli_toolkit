#!/usr/bin/env php
<?php
/**
 * Offline probes for the package-name helpers: classification, last-name
 * and primary guards, and injectable membership verification.
 *
 * No API key or network access is required: everything here runs against
 * synthetic data. All groups run; the exit status is non-zero when any
 * assertion failed.
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

use function SoftwareWrap\TwentyI\classifyDomainForDetach;
use function SoftwareWrap\TwentyI\classifyDomainForMove;
use function SoftwareWrap\TwentyI\getPackageById;
use function SoftwareWrap\TwentyI\packageHasDomain;
use function SoftwareWrap\TwentyI\packageWouldBeEmptyAfterRemoval;
use function SoftwareWrap\TwentyI\pickPrimaryAfterRemoval;
use function SoftwareWrap\TwentyI\planRemovalSequence;
use function SoftwareWrap\TwentyI\verifyDomainAbsentFromPackage;
use function SoftwareWrap\TwentyI\verifyDomainPresentOnPackage;

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

$packages = [
    ['id' => 100, 'names' => ['source-one.com', 'source-two.com', 'source-three.com']],
    ['id' => '200', 'names' => ['target-main.com']],
    ['id' => 300, 'names' => ['third-party.com']],
    ['id' => 400, 'names' => ['Shared.COM.', 'other-name.com']],
];

echo "# getPackageById / packageHasDomain\n";

$found = getPackageById($packages, '100');
check($found !== null && $found['id'] === 100, 'finds package by stringified numeric id');
checkSame(null, getPackageById($packages, '999'), 'unknown id yields null');
check(packageHasDomain($found, 'source-two.com'), 'membership check on plain name');
check(packageHasDomain(getPackageById($packages, '400'), 'shared.com'), 'membership normalizes case and trailing dot');
check(!packageHasDomain($found, 'target-main.com'), 'domain on another package is not a member');
check(!packageHasDomain(null, 'source-one.com'), 'null package never has a domain');

echo "# packageWouldBeEmptyAfterRemoval\n";

check(
    packageWouldBeEmptyAfterRemoval(['only.com'], ['only.com']),
    'removing the only name empties the package'
);
check(
    !packageWouldBeEmptyAfterRemoval(['a.com', 'b.com'], ['a.com']),
    'one of two removed leaves a survivor'
);
check(
    packageWouldBeEmptyAfterRemoval(['a.com', 'b.com'], ['a.com', 'b.com']),
    'cumulative batch removal of every name empties the package'
);
check(
    packageWouldBeEmptyAfterRemoval(['A.COM.'], ['a.com']),
    'normalization applies to current names'
);
check(
    packageWouldBeEmptyAfterRemoval(['a.com'], ['A.COM.']),
    'normalization applies to removals'
);
check(
    !packageWouldBeEmptyAfterRemoval(['a.com', 'b.com'], ['a.com', 'c.com']),
    'unrelated removal does not count toward emptiness'
);

echo "# pickPrimaryAfterRemoval\n";

checkSame(
    'b.com',
    pickPrimaryAfterRemoval(['a.com', 'b.com', 'c.com'], ['a.com']),
    'first remaining name survives when primary is removed'
);
checkSame(
    'a.com',
    pickPrimaryAfterRemoval(['a.com', 'b.com'], ['b.com']),
    'primary keeps its place when a later name is removed'
);
checkSame(
    null,
    pickPrimaryAfterRemoval(['a.com'], ['a.com']),
    'no survivor yields null'
);
checkSame(
    'b.com',
    pickPrimaryAfterRemoval(['A.COM.', 'B.com'], ['a.com']),
    'survivor pick normalizes names'
);

echo "# planRemovalSequence guards\n";

$plan = planRemovalSequence(
    ['a.com', 'b.com', 'c.com'],
    ['a.com', 'b.com']
);
checkSame(2, count($plan), 'two-step plan produced');
checkSame('a.com', $plan[0]['name'], 'first step removes a.com');
checkSame('b.com', $plan[0]['chg'], 'removing the primary carries the first survivor as chg');
checkSame('b.com', $plan[1]['name'], 'second step removes b.com');
checkSame(
    'c.com',
    $plan[1]['chg'],
    'b.com became primary after the first removal, so chg moves to c.com'
);

$plan = planRemovalSequence(['a.com', 'b.com', 'c.com'], ['b.com']);
checkSame(null, $plan[0]['chg'], 'non-primary removal sends chg null');

expectThrows(
    function (): void {
        planRemovalSequence(['only.com'], ['only.com']);
    },
    'without any names',
    'removing the last name is refused'
);
expectThrows(
    function (): void {
        planRemovalSequence(['a.com', 'b.com'], ['a.com', 'b.com']);
    },
    'without any names',
    'cumulative batch removal emptying the package is refused'
);
expectThrows(
    function (): void {
        planRemovalSequence(['a.com', 'b.com'], ['c.com']);
    },
    'not attached',
    'removing an unattached name is refused'
);
expectThrows(
    function (): void {
        planRemovalSequence(['a.com', 'b.com', 'c.com'], ['a.com', 'a.com']);
    },
    'not attached',
    'removing the same name twice is refused on the second step'
);

$plan = planRemovalSequence(['A.COM.', 'b.com'], ['a.com']);
checkSame('b.com', $plan[0]['chg'], 'plan normalizes current names before matching');

echo "# classifyDomainForDetach\n";

$classification = classifyDomainForDetach($packages, 'source-two.com', '100');
checkSame('on-source', $classification['status'], 'attached to source classifies on-source');
checkSame('100', $classification['packageId'], 'on-source carries the source package id');

$classification = classifyDomainForDetach($packages, 'missing.com', '100');
checkSame('not-attached', $classification['status'], 'unknown domain classifies not-attached');
checkSame(null, $classification['packageId'], 'not-attached carries no package id');

$classification = classifyDomainForDetach($packages, 'third-party.com', '100');
checkSame('on-other', $classification['status'], 'domain on another package classifies on-other');
checkSame('300', $classification['packageId'], 'on-other carries the holding package id');
checkSame('third-party.com', $classification['selector'], 'on-other carries the holder selector');

$classification = classifyDomainForDetach($packages, 'SOURCE-ONE.com', '100');
checkSame('on-source', $classification['status'], 'classification normalizes the domain');

$duplicated = [
    ['id' => 1, 'names' => ['dup.com', 'keep-1.com']],
    ['id' => 2, 'names' => ['dup.com', 'keep-2.com']],
];
$classification = classifyDomainForDetach($duplicated, 'dup.com', '2');
checkSame(
    'on-source',
    $classification['status'],
    'domain on source AND another package still classifies on-source'
);

echo "# classifyDomainForMove\n";

$classification = classifyDomainForMove($packages, 'source-three.com', '100', '200');
checkSame('on-source', $classification['status'], 'attached to source classifies on-source for move');

$classification = classifyDomainForMove($packages, 'target-main.com', '100', '200');
checkSame('on-target', $classification['status'], 'attached to target classifies on-target');
checkSame('200', $classification['packageId'], 'on-target carries the target package id');

$classification = classifyDomainForMove($packages, 'third-party.com', '100', '200');
checkSame('on-third', $classification['status'], 'attached elsewhere classifies on-third');
checkSame('300', $classification['packageId'], 'on-third carries the third package id');

$classification = classifyDomainForMove($packages, 'missing.com', '100', '200');
checkSame('not-attached', $classification['status'], 'unknown domain classifies not-attached for move');

$interrupted = [
    ['id' => 1, 'names' => ['both.com', 'keep.com']],
    ['id' => 2, 'names' => ['both.com', 'target-name.com']],
];
$classification = classifyDomainForMove($interrupted, 'both.com', '1', '2');
checkSame(
    'on-source',
    $classification['status'],
    'domain on BOTH source and target classifies on-source (resume semantics)'
);

echo "# verifyDomainAbsentFromPackage (injected probes)\n";

$makeSnapshots = function (array $nameLists): array {
    $snapshots = [];

    foreach ($nameLists as $names) {
        $snapshots[] = [['id' => 500, 'names' => $names]];
    }

    return $snapshots;
};

$snapshots = $makeSnapshots([
    ['gone.com', 'stay.com'],
    ['gone.com', 'stay.com'],
    ['stay.com'],
]);
$fetchCount = 0;
$sleepLog = [];
$fetch = function () use (&$snapshots, &$fetchCount): array {
    $fetchCount++;

    return array_shift($snapshots);
};
$sleeper = function (int $seconds) use (&$sleepLog): void {
    $sleepLog[] = $seconds;
};

check(
    verifyDomainAbsentFromPackage(null, 'gone.com', '500', 3, 1, $fetch, $sleeper),
    'absence verified on the third probe'
);
checkSame(3, $fetchCount, 'three probes issued');
checkSame([1, 1], $sleepLog, 'sleeps between probes only, at the configured delay');

$snapshots = $makeSnapshots([
    ['gone.com'],
    ['gone.com'],
    ['gone.com'],
]);
$fetchCount = 0;
$sleepLog = [];
check(
    !verifyDomainAbsentFromPackage(null, 'gone.com', '500', 3, 1, $fetch, $sleeper),
    'still-present domain fails verification after the probe budget'
);
checkSame(3, $fetchCount, 'probe budget respected on failure');
checkSame([1, 1], $sleepLog, 'no sleep after the final probe');

$snapshots = $makeSnapshots([
    [],
]);
$fetchCount = 0;
check(
    verifyDomainAbsentFromPackage(null, 'gone.com', '500', 3, 1, $fetch, $sleeper),
    'immediate absence returns on the first probe'
);
checkSame(1, $fetchCount, 'no extra probes after success');

$moveWindow = [
    [
        ['id' => 'src', 'names' => ['moving.com', 'anchor.com']],
        ['id' => 'dst', 'names' => ['moving.com', 'dst-name.com']],
    ],
];
check(
    !verifyDomainAbsentFromPackage(
        null,
        'moving.com',
        'src',
        1,
        1,
        function () use ($moveWindow): array {
            return $moveWindow[0];
        },
        $sleeper
    ),
    'absence check is package-specific: domain on both packages is still present on source'
);

echo "# verifyDomainPresentOnPackage (injected probes)\n";

$snapshots = $makeSnapshots([
    ['other.com'],
    ['other.com', 'newly-added.com'],
]);
$fetchCount = 0;
$sleepLog = [];
check(
    verifyDomainPresentOnPackage(null, 'newly-added.com', '500', 3, 1, $fetch, $sleeper),
    'presence verified on the second probe'
);
checkSame(2, $fetchCount, 'presence probing stops at first success');
checkSame([1], $sleepLog, 'one sleep before the successful probe');

$snapshots = $makeSnapshots([
    ['other.com'],
    ['other.com'],
    ['other.com'],
]);
$fetchCount = 0;
check(
    !verifyDomainPresentOnPackage(null, 'newly-added.com', '500', 3, 1, $fetch, $sleeper),
    'never-appearing domain fails presence verification'
);

check(
    verifyDomainPresentOnPackage(
        null,
        'moving.com',
        'dst',
        1,
        1,
        function () use ($moveWindow): array {
            return $moveWindow[0];
        },
        $sleeper
    ),
    'presence check is package-specific: domain on both packages is present on target'
);

expectThrows(
    function (): void {
        verifyDomainAbsentFromPackage(null, 'a.com', '1');
    },
    'fetchPackages',
    'absence verification without a client or a fetcher is refused'
);
expectThrows(
    function (): void {
        verifyDomainPresentOnPackage(null, 'a.com', '1');
    },
    'fetchPackages',
    'presence verification without a client or a fetcher is refused'
);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
