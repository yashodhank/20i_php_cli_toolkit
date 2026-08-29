#!/usr/bin/env php
<?php
/**
 * Offline probes for the email forward pure helpers.
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
require_once __DIR__ . '/../../lib/email.php';

use function SoftwareWrap\TwentyI\Email\assertApiResponse;
use function SoftwareWrap\TwentyI\Email\buildCreateForwardPayload;
use function SoftwareWrap\TwentyI\Email\buildDeleteForwardPayload;
use function SoftwareWrap\TwentyI\Email\distinctRemotes;
use function SoftwareWrap\TwentyI\Email\emitForwarderLine;
use function SoftwareWrap\TwentyI\Email\extractForwardersForDomain;
use function SoftwareWrap\TwentyI\Email\findForwarders;
use function SoftwareWrap\TwentyI\Email\forwarderIds;
use function SoftwareWrap\TwentyI\Email\isCatchAllSubject;
use function SoftwareWrap\TwentyI\Email\isWildcardSubject;
use function SoftwareWrap\TwentyI\Email\parseForwardSpec;
use function SoftwareWrap\TwentyI\Email\parseRemoteAddress;
use function SoftwareWrap\TwentyI\Email\sameAddress;

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
 * Assert that a callable throws, optionally matching the message.
 */
function checkThrows(callable $probe, string $label, string $needle = ''): void
{
    try {
        $probe();
    } catch (Throwable $exception) {
        check(
            $needle === '' || stripos($exception->getMessage(), $needle) !== false,
            "{$label} (threw: " . $exception->getMessage() . ')'
        );

        return;
    }

    check(false, "{$label} (did not throw)");
}

echo "# parseForwardSpec: valid\n";

checkSame(
    ['local' => 'info', 'domain' => 'example.com'],
    parseForwardSpec('Info@Example.COM'),
    'lowercases local and domain'
);
checkSame(
    ['local' => 'first.last+tag_1%x', 'domain' => 'sub.example.co.uk'],
    parseForwardSpec('first.last+tag_1%x@sub.example.co.uk'),
    'accepts dots, plus, underscore, percent in local'
);
checkSame(
    ['local' => 'a', 'domain' => 'example.com'],
    parseForwardSpec(' a@example.com. '),
    'trims whitespace and trailing dot on domain'
);

echo "# parseForwardSpec: invalid and edge\n";

checkThrows(static function (): void {
    parseForwardSpec('');
}, 'empty spec refused');
checkThrows(static function (): void {
    parseForwardSpec('no-at-sign');
}, 'spec without @ refused', 'local@domain');
checkThrows(static function (): void {
    parseForwardSpec('user@');
}, 'empty domain refused');
checkThrows(static function (): void {
    parseForwardSpec('user@nodots');
}, 'dotless domain refused', 'valid');
checkThrows(static function (): void {
    parseForwardSpec('we ird@example.com');
}, 'space in local refused', 'unsupported');
checkThrows(static function (): void {
    parseForwardSpec('.dot@example.com');
}, 'leading dot in local refused', 'unsupported');
checkThrows(static function (): void {
    parseForwardSpec('dot.@example.com');
}, 'trailing dot in local refused', 'unsupported');

echo "# catch-all and wildcard refusal\n";

checkSame(true, isCatchAllSubject(''), 'empty local is catch-all');
checkSame(true, isCatchAllSubject('   '), 'whitespace local is catch-all');
checkSame(false, isCatchAllSubject('info'), 'named local is not catch-all');
checkSame(true, isWildcardSubject('*'), 'star local is wildcard');
checkSame(true, isWildcardSubject('a*b'), 'embedded star is wildcard');
checkSame(false, isWildcardSubject('ab'), 'plain local is not wildcard');

checkThrows(static function (): void {
    parseForwardSpec('@example.com');
}, 'catch-all subject refused loudly', 'catch-all');
checkThrows(static function (): void {
    parseForwardSpec('*@example.com');
}, 'wildcard subject refused loudly', 'wildcard');
checkThrows(static function (): void {
    parseForwardSpec('sales*@example.com');
}, 'partial wildcard subject refused loudly', 'wildcard');

echo "# parseRemoteAddress\n";

checkSame(
    'User@dest.com',
    parseRemoteAddress(' User@Dest.COM '),
    'preserves local case, lowercases domain'
);
checkThrows(static function (): void {
    parseRemoteAddress('');
}, 'empty destination refused');
checkThrows(static function (): void {
    parseRemoteAddress('not-an-email');
}, 'non-email destination refused', 'invalid destination');
checkThrows(static function (): void {
    parseRemoteAddress('star*@dest.com');
}, 'wildcard destination refused', 'wildcard');

echo "# sameAddress\n";

checkSame(true, sameAddress('A@B.com', 'a@b.COM'), 'case-insensitive match');
checkSame(false, sameAddress('a@b.com', 'c@b.com'), 'different locals differ');

echo "# buildCreateForwardPayload\n";

checkSame(
    ['new' => ['forward' => ['local' => 'info', 'remote' => 'team@dest.com']]],
    buildCreateForwardPayload('info', 'team@dest.com'),
    'create payload matches the proven live shape'
);

echo "# buildDeleteForwardPayload (CONFIRM-LIVE primary candidate)\n";

checkSame(
    ['delete' => ['forward' => [42, '7']]],
    buildDeleteForwardPayload([42, '7']),
    'delete payload is {"delete":{"forward":[id,...]}}'
);
checkSame(
    ['delete' => ['forward' => [3]]],
    buildDeleteForwardPayload([2 => 3]),
    'delete payload reindexes IDs'
);
checkThrows(static function (): void {
    buildDeleteForwardPayload([]);
}, 'empty ID list refused', 'without forwarder IDs');
checkThrows(static function (): void {
    buildDeleteForwardPayload([null]);
}, 'null ID refused', 'integers or strings');
checkThrows(static function (): void {
    buildDeleteForwardPayload(['  ']);
}, 'blank string ID refused', 'empty forwarder ID');

echo "# extractForwardersForDomain\n";

$allForwarders = [
    'Example.COM.' => [
        ['id' => 1, 'local' => 'info', 'remote' => 'a@dest.com'],
        ['id' => '2', 'local' => 'Sales', 'remote' => 'b@dest.com'],
        ['local' => 'no-id', 'remote' => 'c@dest.com'],
        ['id' => 4, 'local' => 5, 'remote' => 'bad-local@dest.com'],
        'not-an-entry',
    ],
    'other.com' => [
        ['id' => 9, 'local' => 'info', 'remote' => 'other@dest.com'],
    ],
    0 => [['id' => 10, 'local' => 'x', 'remote' => 'y@dest.com']],
];

$extracted = extractForwardersForDomain($allForwarders, 'example.com');

checkSame(3, count($extracted), 'keeps well-formed entries for the domain only');
checkSame(
    ['id' => 1, 'local' => 'info', 'remote' => 'a@dest.com'],
    $extracted[0],
    'first entry preserved'
);
checkSame(
    ['id' => null, 'local' => 'no-id', 'remote' => 'c@dest.com'],
    $extracted[2],
    'entry without ID kept with null ID'
);
checkSame(
    [],
    extractForwardersForDomain($allForwarders, 'absent.com'),
    'unknown domain yields empty list'
);
checkSame(
    [],
    extractForwardersForDomain(['example.com' => 'oops'], 'example.com'),
    'non-array domain value yields empty list'
);

echo "# findForwarders: 0 / 1 / many and remote disambiguation\n";

$forwarders = [
    ['id' => 1, 'local' => 'info', 'remote' => 'a@dest.com'],
    ['id' => 2, 'local' => 'info', 'remote' => 'B@dest.com'],
    ['id' => 3, 'local' => 'sales', 'remote' => 'a@dest.com'],
    ['id' => null, 'local' => 'ghost', 'remote' => 'x@dest.com'],
];

checkSame(
    [],
    findForwarders($forwarders, 'missing'),
    'no match yields empty list'
);
checkSame(
    [['id' => 3, 'local' => 'sales', 'remote' => 'a@dest.com']],
    findForwarders($forwarders, 'SALES'),
    'single match, local case-insensitive'
);
checkSame(
    2,
    count(findForwarders($forwarders, 'info')),
    'multiple destinations all matched without remote'
);
checkSame(
    [['id' => 2, 'local' => 'info', 'remote' => 'B@dest.com']],
    findForwarders($forwarders, 'info', 'b@DEST.com'),
    'remote disambiguates case-insensitively'
);
checkSame(
    [],
    findForwarders($forwarders, 'info', 'c@dest.com'),
    'remote with no match yields empty list'
);

echo "# distinctRemotes and forwarderIds\n";

checkSame(
    ['a@dest.com', 'B@dest.com'],
    distinctRemotes(findForwarders($forwarders, 'info')),
    'distinct destinations listed once'
);
checkSame(
    ['a@dest.com'],
    distinctRemotes([
        ['id' => 1, 'local' => 'x', 'remote' => 'a@dest.com'],
        ['id' => 2, 'local' => 'x', 'remote' => 'A@DEST.com'],
    ]),
    'case-variant duplicates collapse'
);
checkSame(
    [1, 2],
    forwarderIds(findForwarders($forwarders, 'info')),
    'IDs extracted in order'
);
checkThrows(static function () use ($forwarders): void {
    forwarderIds(findForwarders($forwarders, 'ghost'));
}, 'missing ID refused rather than guessed', 'no server-assigned ID');

echo "# assertApiResponse: swallowed 404 handling\n";

checkThrows(static function (): void {
    assertApiResponse(null, 'allMailForwarders on package 1');
}, 'null API return is an error, never empty success', 'swallows HTTP 404');
checkSame(
    ['example.com' => []],
    assertApiResponse(['example.com' => []], 'probe'),
    'verified-live empty forwarder map passes through'
);

$objectResponse = new stdClass();
$objectResponse->{'example.com'} = [];

checkSame(
    ['example.com' => []],
    assertApiResponse($objectResponse, 'probe'),
    'stdClass response decodes to an array'
);

echo "# emitForwarderLine\n";

ob_start();
$clean = emitForwarderLine([
    'domain' => 'example.com',
    'ok' => true,
    'packageId' => '123',
    'forwarders' => [['id' => 1, 'local' => 'info', 'remote' => 'a@dest.com']],
    'errors' => (object) [],
]);
$line = ob_get_clean();
$decoded = json_decode(trim((string) $line), true);

checkSame(true, $clean, 'clean payload reports success');
check(is_array($decoded), 'clean payload emits valid JSON');
checkSame('example.com', is_array($decoded) ? $decoded['domain'] : null, 'domain survives');
checkSame(1, is_array($decoded) ? count($decoded['forwarders']) : null, 'forwarders survive');

ob_start();
$clean = emitForwarderLine([
    'domain' => 'example.com',
    'ok' => true,
    'packageId' => '123',
    'forwarders' => [NAN],
    'errors' => ['api' => 'kept'],
]);
$line = ob_get_clean();
$decoded = json_decode(trim((string) $line), true);

checkSame(false, $clean, 'unencodable payload reports degradation');
check(is_array($decoded), 'degraded payload still emits valid JSON');
checkSame([], is_array($decoded) ? $decoded['forwarders'] : null, 'degraded row drops forwarders');
checkSame(
    'kept',
    is_array($decoded) ? ($decoded['errors']['api'] ?? null) : null,
    'degraded row preserves prior errors'
);
check(
    is_array($decoded) && isset($decoded['errors']['encode']),
    'degraded row records the encode error'
);

echo "\n{$checks} checks, {$failures} failures\n";

exit($failures === 0 ? 0 : 1);
