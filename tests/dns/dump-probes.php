#!/usr/bin/env php
<?php
/**
 * Offline probes for the DNS dump pure helpers and packet decoding.
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
require_once __DIR__ . '/../../lib/cli.php';
require_once __DIR__ . '/../../lib/dns.php';
require_once __DIR__ . '/../../lib/zone-records.php';

use function SoftwareWrap\TwentyI\Cli\emitDomainLine;
use function SoftwareWrap\TwentyI\Cli\sanitizeApiError;
use function SoftwareWrap\TwentyI\Dns\findZoneForDomain;
use function SoftwareWrap\TwentyI\Dns\getApiRecordsForDomain;
use function SoftwareWrap\TwentyI\Dns\normalizeApiRecord;
use function SoftwareWrap\TwentyI\isValidDomain;
use function SoftwareWrap\TwentyI\isValidQueryName;

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
 */
function checkSame(mixed $expected, mixed $actual, string $label): void
{
    check(
        $expected === $actual,
        "{$label} (" . var_export($expected, true) . ' === '
            . var_export($actual, true) . ')'
    );
}

echo "# findZoneForDomain\n";

$zoneMap = [
    'Example.COM.' => ['records' => [['host' => 'example.com', 'type' => 'A', 'ip' => '203.0.113.1']]],
];

checkSame(
    'Example.COM.',
    findZoneForDomain($zoneMap, 'example.com'),
    'returns original map key for case-differing zone'
);
checkSame(
    'Example.COM.',
    findZoneForDomain($zoneMap, 'www.example.com'),
    'subdomain resolves to parent zone key'
);
checkSame(null, findZoneForDomain([], 'example.com'), 'empty map yields null');
checkSame(
    null,
    findZoneForDomain($zoneMap, 'example.org'),
    'unrelated domain yields null'
);
checkSame(
    null,
    findZoneForDomain($zoneMap, 'com'),
    'zone longer than domain does not match'
);

$tieMap = [
    'WWW.Example.COM.' => ['records' => []],
    'www.example.com' => ['records' => []],
];

checkSame(
    'www.example.com',
    findZoneForDomain($tieMap, 'www.example.com'),
    'tie prefers key equal to normalized domain'
);

$nestedMap = [
    'example.com' => ['records' => []],
    'shop.example.com' => ['records' => []],
];

checkSame(
    'shop.example.com',
    findZoneForDomain($nestedMap, 'api.shop.example.com'),
    'longest suffix wins over shorter ancestor'
);

echo "# getApiRecordsForDomain\n";

$fullZone = [
    'example.com' => [
        'records' => [
            ['host' => 'example.com', 'type' => 'SOA', 'mname' => 'ns1.stackdns.com.', 'rname' => 'admin.example.com.', 'serial' => 5, 'refresh' => 3600, 'retry' => 600, 'expire' => 604800, 'minimum-ttl' => 3600],
            ['host' => 'example.com', 'type' => 'A', 'ip' => '203.0.113.10'],
            ['host' => '*.example.com', 'type' => 'A', 'ip' => '203.0.113.11'],
            ['host' => '_sip._tcp.example.com', 'type' => 'SRV', 'pri' => 5, 'weight' => 0, 'port' => 5269, 'target' => 'xmpp.example.com.'],
            ['host' => 'chunked.example.com', 'type' => 'TXT', 'txt' => ['par', 't1', 'part2']],
            ['host' => 'legacy.example.com', 'type' => 'TXT', 'txt' => 'plain'],
        ],
    ],
    '*.other.example.com' => ['records' => []],
];

$allRecords = getApiRecordsForDomain($fullZone, 'example.com', []);
checkSame(6, count($allRecords), 'zone root returns every record unfiltered');
checkSame('part1part2', $allRecords[4]['rdata'], 'chunked TXT concatenates');
checkSame('plain', $allRecords[5]['rdata'], 'scalar TXT passes through');
checkSame(
    'ns1.stackdns.com admin.example.com 5 3600 600 604800 3600',
    (string) preg_replace('/\s+/', ' ', trim($allRecords[0]['rdata'])),
    'SOA rdata shape'
);
checkSame(
    '5 0 5269 xmpp.example.com',
    $allRecords[3]['rdata'],
    'SRV rdata shape with target dot stripped'
);

$filtered = getApiRecordsForDomain($fullZone, 'example.com', ['A']);
checkSame(2, count($filtered), 'explicit type filter narrows zone root');

$sub = getApiRecordsForDomain($fullZone, 'foo.example.com', []);
checkSame(1, count($sub), 'one-label subdomain inherits covering wildcard (no exact record)');

$deep = getApiRecordsForDomain($fullZone, 'a.b.example.com', []);
checkSame(0, count($deep), 'two-label subdomain is not covered by *.zone (RFC 4592)');

$coveredApex = getApiRecordsForDomain($fullZone, 'other.example.com', []);
checkSame(
    1,
    count($coveredApex),
    'other.example.com resolves via example.com zone wildcard, not the *.other key'
);

$hijackZone = [
    '*.other.example.com' => [
        'records' => [['host' => '*.other.example.com', 'type' => 'A', 'ip' => '203.0.113.99']],
    ],
];
expectThrows(
    static fn () => getApiRecordsForDomain($hijackZone, 'other.example.com', []),
    'No DNS zone covers',
    'wildcard zone key cannot cover its own apex'
);
checkSame(
    null,
    findZoneForDomain($hijackZone, 'other.example.com'),
    'wildcard-prefixed keys are skipped during zone resolution'
);

echo "# shape guards\n";

function expectThrows(callable $fn, string $needle, string $label): void
{
    try {
        $fn();
        check(false, "{$label} (no exception thrown)");
    } catch (Throwable $exception) {
        check(str_contains($exception->getMessage(), $needle), $label);
    }
}

expectThrows(
    static fn () => getApiRecordsForDomain([
        'example.com' => ['records' => ['A' => [['host' => 'example.com']]]],
    ], 'example.com', []),
    'Unexpected record shape',
    'type-grouped map shape throws loudly'
);
expectThrows(
    static fn () => getApiRecordsForDomain([
        'example.com' => ['records' => ['not-a-record']],
    ], 'example.com', []),
    'Unexpected record shape',
    'non-array record entry throws loudly'
);
expectThrows(
    static fn () => getApiRecordsForDomain([
        'example.com' => ['records' => 'bogus'],
    ], 'example.com', []),
    'Unexpected record shape',
    'scalar records value throws loudly'
);
expectThrows(
    static fn () => getApiRecordsForDomain(['example.com' => null], 'example.com', []),
    'Unexpected zone shape',
    'null zone entry throws loudly'
);
expectThrows(
    static fn () => getApiRecordsForDomain(['example.com' => 'bogus'], 'example.com', []),
    'Unexpected zone shape',
    'scalar zone entry throws loudly'
);

echo "# normalizeApiRecord missing fields\n";

$mxMissingPri = normalizeApiRecord(['host' => 'example.com', 'type' => 'MX', 'target' => 'mx.example.com']);
checkSame('mx.example.com', $mxMissingPri['rdata'], 'MX without pri collapses cleanly');

$srvMissing = normalizeApiRecord(['host' => 'x', 'type' => 'SRV', 'target' => 't']);
checkSame('t', $srvMissing['rdata'], 'SRV without pri/weight/port collapses to target');

echo "# isValidQueryName vs isValidDomain\n";

check(isValidQueryName('_dmarc.example.com'), 'underscore TXT owner accepted');
check(isValidQueryName('_sip._tcp.example.com'), 'multi-level underscore SRV owner accepted');
check(isValidQueryName('example.com'), 'plain hostname accepted');
check(!isValidQueryName('-bad.example.com'), 'leading hyphen rejected');
check(!isValidQueryName('bad-.example.com'), 'trailing-hyphen label rejected');
check(!isValidQueryName('nodots'), 'single label rejected');
check(!isValidQueryName(''), 'empty rejected');
check(isValidDomain('example.com'), 'isValidDomain still hostname-only');
check(!isValidDomain('_dmarc.example.com'), 'isValidDomain rejects underscores unchanged');

echo "# SRV wire decode (compressed target)\n";

/**
 * Build a minimal DNS response carrying one SRV answer whose target is a
 * compression pointer to the question name.
 */
function buildSrvResponse(string $qname, int $pri, int $weight, int $port): string
{
    $header = pack('nnnnnn', 0x1234, 0x8180, 1, 1, 0, 0);

    $question = '';
    foreach (explode('.', $qname) as $label) {
        $question .= chr(strlen($label)) . $label;
    }
    $question .= "\x00";
    $question .= pack('nn', 33, 1); // SRV, IN

    $answer = "\xc0\x0c"; // name pointer to question at offset 12
    $answer .= pack('nnNn', 33, 1, 300, 7); // type, class, ttl, rdlength
    $answer .= pack('nnn', $pri, $weight, $port) . "\xc0\x0c";

    return $header . $question . $answer;
}

$response = buildSrvResponse('_sip._tcp.example.com', 5, 0, 5269);
$header = ['qdcount' => 1, 'ancount' => 1];
$decoded = \SoftwareWrap\TwentyI\Dns\parseResourceRecords($response, $header);

checkSame(1, count($decoded), 'one SRV record decoded');
checkSame(
    '5 0 5269 _sip._tcp.example.com',
    $decoded[0]['rdata'] ?? null,
    'compressed SRV target resolves through pointer'
);
checkSame('SRV', $decoded[0]['type'] ?? null, 'type name mapped');

echo "# isValidQueryName boundaries\n";

check(isValidQueryName(str_repeat('a', 63) . '.com'), '63-char label accepted');
check(!isValidQueryName(str_repeat('a', 64) . '.com'), '64-char label rejected');
check(
    isValidQueryName(implode('.', [str_repeat('a', 63), str_repeat('b', 63), str_repeat('c', 63), str_repeat('d', 61)])),
    '253-byte name accepted'
);
check(
    !isValidQueryName(implode('.', [str_repeat('a', 63), str_repeat('b', 63), str_repeat('c', 63), str_repeat('d', 62)])),
    '254-byte name rejected'
);
check(isValidQueryName('EXAMPLE.COM'), 'uppercase accepted');
check(isValidQueryName('xn--nxasmq6b.example.com'), 'punycode label accepted');

echo "# sanitizeApiError\n";

$fakeHttp = new class extends RuntimeException {
    public function __construct()
    {
        parent::__construct(
            'HTTP error 400 on https://api.20i.com/package/42/dns: {"error":"secret detail"}'
        );
    }
};
$sanitized = sanitizeApiError($fakeHttp);
checkSame(
    'HTTP error 400 on https://api.20i.com/package/42/dns',
    $sanitized,
    'HTTP error reduced to status + endpoint'
);
check(
    !str_contains($sanitized, 'secret detail'),
    'response body never survives sanitization'
);
checkSame(
    'plain failure',
    sanitizeApiError(new RuntimeException('plain failure')),
    'non-HTTP message passes through unchanged'
);

echo "# emitDomainLine degraded rows\n";

$resource = fopen('php://memory', 'r');
ob_start();
$rowClean = emitDomainLine([
    'domain' => 'example.com',
    'ok' => true,
    'packageId' => '7',
    'apiZone' => 'example.com',
    'sources' => (object) ['api' => true],
    'records' => [['unencodable' => $resource]],
    'errors' => (object) [],
]);
$row = ob_get_clean();
fclose($resource);

check(!$rowClean, 'unencodable record reports a degraded row');
checkSame(1, substr_count($row, "\n"), 'degraded output stays single-line');
$decodedRow = json_decode($row, true);
check($decodedRow !== null, 'degraded row is valid JSON');
check($decodedRow['domain'] === 'example.com', 'degraded row keeps domain');
check($decodedRow['ok'] === true, 'degraded row keeps truthful ok state');
check($decodedRow['sources']['api'] === true, 'degraded row keeps sources');
check(
    isset($decodedRow['errors']['encode']),
    'degraded row explains the degradation'
);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
