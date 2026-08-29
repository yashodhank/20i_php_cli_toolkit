#!/usr/bin/env php
<?php
/**
 * Offline probes for the DNS mutation helpers (delete/replace lane).
 *
 * No API key or network access is required: everything here runs against
 * synthetic data. All groups run; the exit status is non-zero when any
 * assertion failed. The file is PHP 7.4-compatible by construction.
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
require_once __DIR__ . '/../../lib/dns.php';
require_once __DIR__ . '/../../lib/zone-records.php';

use function SoftwareWrap\TwentyI\Dns\assertRecordsDeletable;
use function SoftwareWrap\TwentyI\Dns\buildDeletePayload;
use function SoftwareWrap\TwentyI\Dns\buildReplacePayload;
use function SoftwareWrap\TwentyI\Dns\buildSnapshotFilename;
use function SoftwareWrap\TwentyI\Dns\extractRecordRef;
use function SoftwareWrap\TwentyI\Dns\findMatchingApiRecords;
use function SoftwareWrap\TwentyI\Dns\getSnapshotDirectory;
use function SoftwareWrap\TwentyI\Dns\getStateDirectory;
use function SoftwareWrap\TwentyI\Dns\isListArray;
use function SoftwareWrap\TwentyI\Dns\normalizeApiRecord;
use function SoftwareWrap\TwentyI\Dns\normalizeRecordNameForDomain;
use function SoftwareWrap\TwentyI\Dns\rdataValuesEqual;
use function SoftwareWrap\TwentyI\Dns\readMutationJournal;
use function SoftwareWrap\TwentyI\Dns\requireDeletableRecordType;
use function SoftwareWrap\TwentyI\Dns\requireExactlyOneMatch;
use function SoftwareWrap\TwentyI\Dns\saveMutationJournalEntry;
use function SoftwareWrap\TwentyI\Dns\writeSnapshotFile;

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
            "{$label} (got: " . $exception->getMessage() . ')'
        );
    }
}

echo "# normalizeRecordNameForDomain (lifted)\n";

checkSame('@', normalizeRecordNameForDomain('example.com', '@'), 'at-sign is apex');
checkSame('@', normalizeRecordNameForDomain('example.com', ''), 'empty is apex');
checkSame('@', normalizeRecordNameForDomain('example.com', 'example.com'), 'zone domain is apex');
checkSame('@', normalizeRecordNameForDomain('example.com', 'Example.COM.'), 'trailing-dot zone domain is apex');
checkSame('www', normalizeRecordNameForDomain('example.com', 'www'), 'relative name passes');
checkSame('www', normalizeRecordNameForDomain('example.com', 'www.example.com'), 'in-zone FQDN relativized');
checkSame('_acme', normalizeRecordNameForDomain('example.com', '_acme.example.com.'), 'trailing-dot in-zone FQDN relativized');
expectThrows(
    static function () {
        normalizeRecordNameForDomain('example.com', 'www.example.net.');
    },
    'outside the target zone',
    'trailing-dot out-of-zone FQDN rejected'
);
expectThrows(
    static function () {
        normalizeRecordNameForDomain('example.com', '*.example.com');
    },
    'Wildcard',
    'wildcard owner rejected'
);

echo "# extractRecordRef\n";

checkSame('123', extractRecordRef(['fields' => ['ref' => 123]]), 'int ref under fields becomes string');
checkSame('456', extractRecordRef(['fields' => ['ref' => '456']]), 'string ref under fields passes');
checkSame('789', extractRecordRef(['ref' => 789]), 'raw record ref accepted');
checkSame(null, extractRecordRef(['fields' => ['ref' => null]]), 'null ref (SOA) yields null');
checkSame(null, extractRecordRef(['fields' => []]), 'missing ref yields null');
checkSame(null, extractRecordRef(['fields' => ['ref' => '  ']]), 'blank ref yields null');

$soaNormalized = normalizeApiRecord([
    'host' => 'example.com',
    'type' => 'SOA',
    'mname' => 'ns1.stackdns.com.',
    'rname' => 'admin.example.com.',
    'serial' => 1,
    'refresh' => 3600,
    'retry' => 600,
    'expire' => 604800,
    'minimum-ttl' => 3600,
    'ref' => null,
]);
checkSame(null, extractRecordRef($soaNormalized), 'normalized SOA record has null ref');

$txtNormalized = normalizeApiRecord([
    'host' => 'example.com',
    'type' => 'TXT',
    'txt' => 'hello',
    'ref' => 42,
]);
checkSame('42', extractRecordRef($txtNormalized), 'normalized TXT record preserves ref via fields');

echo "# rdataValuesEqual\n";

check(rdataValuesEqual('TXT', '  spf1  ', 'spf1'), 'TXT compares via normalization');
check(!rdataValuesEqual('TXT', 'SPF1', 'spf1'), 'TXT comparison stays case-sensitive');
check(rdataValuesEqual('CNAME', 'Target.Example.COM', 'target.example.com'), 'CNAME compares case-insensitively');
check(rdataValuesEqual('MX', '10 mail.example.com', '10 MAIL.example.com '), 'MX compares case-insensitively with trim');
check(!rdataValuesEqual('A', '203.0.113.1', '203.0.113.2'), 'A values differ');

echo "# findMatchingApiRecords\n";

$zoneRecords = [];
$rawZone = [
    ['host' => 'example.com', 'type' => 'SOA', 'mname' => 'ns1.stackdns.com.', 'rname' => 'a.b', 'serial' => 1, 'refresh' => 1, 'retry' => 1, 'expire' => 1, 'minimum-ttl' => 1, 'ref' => null],
    ['host' => 'example.com', 'type' => 'NS', 'target' => 'ns1.stackdns.com.', 'ref' => 1],
    ['host' => 'example.com', 'type' => 'NS', 'target' => 'ns2.stackdns.com.', 'ref' => 2],
    ['host' => 'example.com', 'type' => 'A', 'ip' => '203.0.113.10', 'ref' => 3],
    ['host' => 'example.com', 'type' => 'TXT', 'txt' => 'for sale', 'ref' => 4],
    ['host' => 'example.com', 'type' => 'TXT', 'txt' => 'keep me', 'ref' => 5],
    ['host' => '_acme.example.com', 'type' => 'TXT', 'txt' => 'token-1', 'ref' => 6],
    ['host' => '_acme.example.com', 'type' => 'TXT', 'txt' => 'token-2', 'ref' => 7],
    ['host' => 'sub.example.com', 'type' => 'NS', 'target' => 'other.example.net.', 'ref' => 8],
];

foreach ($rawZone as $raw) {
    $zoneRecords[] = normalizeApiRecord($raw);
}

$apexTxtAll = findMatchingApiRecords($zoneRecords, 'example.com', '@', 'TXT', null);
checkSame(2, count($apexTxtAll), 'null value matches every apex TXT record');

$apexTxtOne = findMatchingApiRecords($zoneRecords, 'example.com', '@', 'txt', 'for sale');
checkSame(1, count($apexTxtOne), 'lowercase type + exact value narrows to one');
checkSame('4', extractRecordRef($apexTxtOne[0]), 'matched record carries its ref');

$apexTxtWs = findMatchingApiRecords($zoneRecords, 'example.com', '@', 'TXT', '  for sale  ');
checkSame(1, count($apexTxtWs), 'TXT value matching tolerates surrounding whitespace');

$acmeRelative = findMatchingApiRecords($zoneRecords, 'example.com', '_acme', 'TXT', 'token-1');
checkSame(1, count($acmeRelative), 'relative owner matches subdomain record');

$acmeFqdn = findMatchingApiRecords($zoneRecords, 'example.com', '_acme.example.com.', 'TXT', 'token-1');
checkSame(1, count($acmeFqdn), 'in-zone FQDN owner matches the same record');

$zeroMatch = findMatchingApiRecords($zoneRecords, 'example.com', '@', 'TXT', 'absent');
checkSame([], $zeroMatch, 'zero matches classify as empty array');

$wrongOwner = findMatchingApiRecords($zoneRecords, 'example.com', 'www', 'A', null);
checkSame([], $wrongOwner, 'different owner never matches');

$typeMiss = findMatchingApiRecords($zoneRecords, 'example.com', '@', 'AAAA', null);
checkSame([], $typeMiss, 'type mismatch never matches');

$multiMatch = findMatchingApiRecords($zoneRecords, 'example.com', '_acme', 'TXT', null);
checkSame(2, count($multiMatch), 'multi-match classification returns every record');

echo "# requireDeletableRecordType guards\n";

checkSame('TXT', requireDeletableRecordType(' txt '), 'TXT accepted and normalized');
checkSame('A', requireDeletableRecordType('a'), 'A accepted');
checkSame('SRV', requireDeletableRecordType('SRV'), 'SRV accepted');
expectThrows(
    static function () {
        requireDeletableRecordType('SOA');
    },
    'SOA records can never be deleted',
    'SOA type refused'
);
expectThrows(
    static function () {
        requireDeletableRecordType('NS');
    },
    'NS record deletion is not supported',
    'NS type refused entirely'
);
expectThrows(
    static function () {
        requireDeletableRecordType('CAA');
    },
    'not supported for deletion',
    'unknown type refused'
);

echo "# assertRecordsDeletable guards\n";

expectThrows(
    static function () use ($zoneRecords) {
        assertRecordsDeletable([$zoneRecords[0]], 'example.com');
    },
    'Refusing to delete a SOA record',
    'SOA record blocked at guard'
);
expectThrows(
    static function () use ($zoneRecords) {
        assertRecordsDeletable([$zoneRecords[1]], 'example.com');
    },
    'zone apex',
    'apex NS record blocked at guard'
);

try {
    assertRecordsDeletable([$zoneRecords[8]], 'example.com');
    check(true, 'non-apex NS passes the apex guard');
} catch (Throwable $exception) {
    check(false, 'non-apex NS passes the apex guard');
}

try {
    assertRecordsDeletable([$zoneRecords[4], $zoneRecords[5]], 'example.com');
    check(true, 'ordinary TXT records pass the guard');
} catch (Throwable $exception) {
    check(false, 'ordinary TXT records pass the guard');
}

echo "# requireExactlyOneMatch (replace rule)\n";

expectThrows(
    static function () {
        requireExactlyOneMatch([], 'the old TXT value');
    },
    'No record matches',
    'zero matches throw'
);
expectThrows(
    static function () use ($multiMatch) {
        requireExactlyOneMatch($multiMatch, 'the old TXT value');
    },
    '2 records match',
    'multiple matches throw with a count'
);
checkSame(
    '4',
    extractRecordRef(requireExactlyOneMatch($apexTxtOne, 'the old TXT value')),
    'exactly one match returns that record'
);

echo "# buildDeletePayload\n";

$deletePayload = buildDeletePayload([4, '5']);
checkSame('reject', $deletePayload['conflictPolicy'], 'delete conflictPolicy is reject');
checkSame('append', $deletePayload['insertPolicy'], 'delete insertPolicy is append');
checkSame(['4', '5'], $deletePayload['delete'], 'refs are sent as strings');
checkSame(
    ['AAAA' => [], 'A' => [], 'CNAME' => [], 'MX' => [], 'TXT' => [], 'SRV' => []],
    $deletePayload['new'],
    'new member is the empty type map matching the add payload shape'
);
expectThrows(
    static function () {
        buildDeletePayload([]);
    },
    'at least one record ref',
    'empty ref list refused'
);
expectThrows(
    static function () {
        buildDeletePayload(['']);
    },
    'cannot be empty',
    'blank ref refused'
);
expectThrows(
    static function () {
        buildDeletePayload([null]);
    },
    'strings or integers',
    'null ref refused'
);

echo "# buildReplacePayload\n";

$replacePayload = buildReplacePayload('@', 'new value', '42');
checkSame('reject', $replacePayload['conflictPolicy'], 'replace conflictPolicy is reject');
checkSame('append', $replacePayload['insertPolicy'], 'replace insertPolicy is append');
checkSame(['42'], $replacePayload['delete'], 'replace deletes exactly the matched ref');
checkSame(1, count($replacePayload['new']['TXT']), 'replace adds exactly one TXT record');
checkSame(
    ['host' => '@', 'txt' => 'new value'],
    $replacePayload['new']['TXT'][0],
    'replace TXT record shape'
);
checkSame([], $replacePayload['new']['A'], 'replace leaves other type buckets empty');
expectThrows(
    static function () {
        buildReplacePayload('@', 'new value', '  ');
    },
    'ref cannot be empty',
    'blank replace ref refused'
);
expectThrows(
    static function () {
        buildReplacePayload('@', '', '42');
    },
    'cannot be empty',
    'empty replacement value refused'
);

echo "# atomicity of the replace diff\n";

check(
    isset($replacePayload['new']['TXT'][0]) && isset($replacePayload['delete'][0]),
    'one payload carries both the new record and the deletion'
);

echo "# state dir, snapshot filename, snapshot file\n";

$probeStateHome = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'dns-mutation-probes-' . getmypid() . '-' . bin2hex(random_bytes(4));
putenv('XDG_STATE_HOME=' . $probeStateHome);

checkSame(
    $probeStateHome . DIRECTORY_SEPARATOR . '20i-cli',
    getStateDirectory(),
    'XDG_STATE_HOME drives the state directory'
);
checkSame(
    $probeStateHome . DIRECTORY_SEPARATOR . '20i-cli' . DIRECTORY_SEPARATOR . 'snapshots',
    getSnapshotDirectory(),
    'snapshots live under the state directory'
);

$fixedStamp = gmmktime(12, 34, 56, 8, 30, 2026);
checkSame(
    'example.com-20260830T123456Z.jsonl',
    buildSnapshotFilename('Example.COM.', $fixedStamp),
    'snapshot filename is <domain>-<utcstamp>.jsonl'
);
expectThrows(
    static function () {
        buildSnapshotFilename('   ');
    },
    'cannot be empty',
    'empty snapshot domain refused'
);

$snapshotPath = getSnapshotDirectory() . DIRECTORY_SEPARATOR
    . buildSnapshotFilename('example.com', $fixedStamp);
$snapshotRow = [
    'domain' => 'example.com',
    'ok' => true,
    'packageId' => '716033',
    'apiZone' => 'example.com',
    'sources' => ['api' => true],
    'records' => $zoneRecords,
    'errors' => new stdClass(),
];
writeSnapshotFile($snapshotPath, [$snapshotRow]);

check(is_file($snapshotPath), 'snapshot file written');
checkSame(
    '0600',
    substr(sprintf('%o', fileperms($snapshotPath)), -4),
    'snapshot file mode is 0600'
);
checkSame(
    '0700',
    substr(sprintf('%o', fileperms(dirname($snapshotPath))), -4),
    'snapshot directory mode is 0700'
);

$snapshotLines = file($snapshotPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
checkSame(1, count($snapshotLines), 'snapshot holds one JSON line per row');
$decodedSnapshot = json_decode($snapshotLines[0], true);
check(is_array($decodedSnapshot), 'snapshot row is valid JSON');
checkSame('example.com', $decodedSnapshot['domain'], 'snapshot row keeps the domain');
checkSame(
    count($zoneRecords),
    count($decodedSnapshot['records']),
    'snapshot row keeps every zone record'
);
check(
    isset($decodedSnapshot['records'][4]['fields']['ref'])
        && $decodedSnapshot['records'][4]['fields']['ref'] === 4,
    'snapshot rows preserve raw fields including refs'
);
expectThrows(
    static function () use ($snapshotPath) {
        writeSnapshotFile($snapshotPath, []);
    },
    'at least one row',
    'empty snapshot refused'
);

echo "# mutation journal roundtrip\n";

$journalPath = getStateDirectory() . DIRECTORY_SEPARATOR . 'probe-journal.json';
$journal = readMutationJournal($journalPath, 3600);
checkSame([], $journal, 'missing journal reads as empty');

saveMutationJournalEntry($journalPath, $journal, 'key-fresh', [
    'operation' => 'delete',
    'domain' => 'example.com',
]);
check(is_file($journalPath), 'journal file written');
checkSame(
    '0600',
    substr(sprintf('%o', fileperms($journalPath)), -4),
    'journal file mode is 0600'
);

$reread = readMutationJournal($journalPath, 3600);
checkSame(1, count($reread), 'fresh entry survives the window');
check(
    isset($reread['key-fresh']['submittedAt']) && is_int($reread['key-fresh']['submittedAt']),
    'entry gained a submittedAt timestamp'
);

saveMutationJournalEntry($journalPath, $journal, 'key-stale', [
    'operation' => 'delete',
    'domain' => 'old.example.com',
    'submittedAt' => time() - 7200,
]);
$rereadAfterStale = readMutationJournal($journalPath, 3600);
checkSame(1, count($rereadAfterStale), 'stale entry expires past the window');
check(isset($rereadAfterStale['key-fresh']), 'fresh entry remains after expiry pass');

echo "# isListArray (PHP 7.4 array_is_list replacement)\n";

check(isListArray([]), 'empty array is a list');
check(isListArray(['a', 'b']), 'sequential array is a list');
check(!isListArray([1 => 'a']), 'offset array is not a list');
check(!isListArray(['k' => 'v']), 'keyed map is not a list');

/*
 * Cleanup: remove everything created under the probe state home.
 */
$cleanupPaths = [
    $snapshotPath,
    $journalPath,
    dirname($snapshotPath),
    dirname($journalPath),
    $probeStateHome,
];

foreach ($cleanupPaths as $path) {
    if (is_file($path)) {
        @unlink($path);
    } elseif (is_dir($path)) {
        @rmdir($path);
    }
}

putenv('XDG_STATE_HOME');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
