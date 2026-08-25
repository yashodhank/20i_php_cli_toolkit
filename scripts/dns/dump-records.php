#!/usr/bin/env php
<?php
/**
 * Read-only DNS dump: print public records for domains attached to
 * 20i packages, resolved from authoritative StackDNS nameservers.
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

/*
 * stdout carries machine-consumable JSON Lines. PHP 8.5 prints deprecation
 * notices from the vendored 20i client onto stdout, which would corrupt the
 * stream, so they are disabled here. Human-facing scripts may keep them.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/cli.php';
require_once __DIR__ . '/../../lib/dns.php';
require_once __DIR__ . '/../../lib/package.php';

use function SoftwareWrap\TwentyI\Cli\fail;
use function SoftwareWrap\TwentyI\Cli\readLinesFromStdin;
use function SoftwareWrap\TwentyI\Dns\getStackDnsRecords;
use function SoftwareWrap\TwentyI\Dns\recordTypeCode;
use function SoftwareWrap\TwentyI\findPackageByDomain;
use function SoftwareWrap\TwentyI\getPackageDomains;
use function SoftwareWrap\TwentyI\getPackageId;
use function SoftwareWrap\TwentyI\getPackages;
use function SoftwareWrap\TwentyI\isValidDomain;
use function SoftwareWrap\TwentyI\normalizeDomain;

use const SoftwareWrap\TwentyI\Cli\EXIT_ERROR;
use const SoftwareWrap\TwentyI\Cli\EXIT_PARTIAL_FAILURE;
use const SoftwareWrap\TwentyI\Cli\EXIT_SUCCESS;

const DEFAULT_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'SOA', 'TXT'];

/**
 * Display usage information.
 */
function usage(int $exitCode = EXIT_SUCCESS): void
{
    $script = basename($_SERVER['argv'][0]);
    $stream = $exitCode === EXIT_SUCCESS ? STDOUT : STDERR;

    fwrite($stream, <<<EOT
Usage:
  {$script} [--types <list>] <domain> [<domain> ...]
  {$script} [--types <list>] < domains.txt
  {$script} [--types <list>] --all <package-domain>

Read-only. No DNS or package state is modified.

Options:
  --types <list>  Comma-separated record types. Default: A,AAAA,CNAME,MX,NS,SOA,TXT.
  --all           Dump every domain attached to the package identified by
                  the positional <package-domain>.
  --help, -h      Display this help text.

Output:
  One JSON object per domain on stdout (JSON Lines):
  {"domain":"example.com","ok":true,"packageId":"123","records":[{"owner":"...","type":"A","ttl":3600,"rdata":"203.0.113.10"}]}
  On failure for a domain, ok is false and "error" carries the message.
  Progress is written to stderr.

Exit status:
  0  All domains answered
  1  Usage, validation, or configuration error
  3  One or more domains failed

EOT
    );

    exit($exitCode);
}

/**
 * Dump all requested record types for one domain.
 *
 * @param array<int,int> $typeCodes
 * @return array<int,array{owner:string,type:string,ttl:int,rdata:string}>
 */
function dumpDomainRecords(string $domain, array $typeCodes): array
{
    $records = [];

    foreach ($typeCodes as $typeCode) {
        foreach (getStackDnsRecords($domain, $typeCode) as $record) {
            $records[] = $record;
        }
    }

    return $records;
}

/*
 * Parse options and positional arguments.
 */
$allDomains = false;
$types = DEFAULT_TYPES;
$arguments = [];

for ($index = 1; $index < $argc; $index++) {
    $argument = $argv[$index];

    if ($argument === '--help' || $argument === '-h') {
        usage(EXIT_SUCCESS);
    }

    if ($argument === '--all') {
        $allDomains = true;
        continue;
    }

    if ($argument === '--types') {
        $index++;

        if ($index >= $argc) {
            fail("Option '--types' requires a value.");
        }

        $types = [];

        foreach (explode(',', $argv[$index]) as $typeName) {
            $typeName = strtoupper(trim($typeName));

            if ($typeName !== '') {
                $types[] = $typeName;
            }
        }

        if ($types === []) {
            fail('The --types option must list at least one record type.');
        }

        continue;
    }

    if (strpos($argument, '-') === 0) {
        fail("Unknown option '{$argument}'.");
    }

    $arguments[] = $argument;
}

try {
    $typeCodes = array_map(
        'SoftwareWrap\\TwentyI\\Dns\\recordTypeCode',
        $types
    );
} catch (Throwable $exception) {
    fail($exception->getMessage());
}

if ($allDomains) {
    if (count($arguments) !== 1) {
        fail('The --all option requires exactly one positional package domain.');
    }

    $selectorDomain = normalizeDomain($arguments[0]);
    $requestedDomains = null;
} elseif (count($arguments) >= 1) {
    $selectorDomain = null;

    $unique = [];

    foreach ($arguments as $domain) {
        $domain = normalizeDomain($domain);

        if (!isValidDomain($domain)) {
            fail("Invalid domain '{$domain}'.");
        }

        $unique[$domain] = true;
    }

    $requestedDomains = array_keys($unique);
} else {
    $selectorDomain = null;
    $requestedDomains = [];

    foreach (readLinesFromStdin() as $line) {
        $domain = normalizeDomain($line);

        if (!isValidDomain($domain)) {
            fail("Invalid domain '{$domain}'.");
        }

        $requestedDomains[$domain] = true;
    }

    $requestedDomains = array_keys($requestedDomains);

    if ($requestedDomains === []) {
        fail('No domains were provided.');
    }
}

try {
    $servicesApi = new \TwentyI\API\Services($api_key);
    $packages = getPackages($servicesApi);
    $domains = [];

    if ($allDomains) {
        if (!isValidDomain($selectorDomain)) {
            fail("Invalid package domain '{$selectorDomain}'.");
        }

        $package = findPackageByDomain($packages, $selectorDomain);

        if ($package === null) {
            fail("No package contains '{$selectorDomain}'.");
        }

        $packageId = getPackageId($package);

        foreach (getPackageDomains($package) as $domain) {
            $domains[$domain] = $packageId;
        }

        if ($domains === []) {
            fail("Package '{$packageId}' does not contain any usable domains.");
        }
    } else {
        foreach ($requestedDomains as $domain) {
            $package = findPackageByDomain($packages, $domain);
            $domains[$domain] = $package === null ? null : getPackageId($package);
        }
    }

    $failureCount = 0;
    $position = 0;
    $total = count($domains);

    foreach ($domains as $domain => $packageId) {
        $position++;

        fwrite(STDERR, "[{$position}/{$total}] {$domain} ... ");
        fflush(STDERR);

        if (!isValidDomain($domain)) {
            $failureCount++;
            fwrite(STDERR, "ERROR\n");

            echo json_encode([
                'domain' => $domain,
                'ok' => false,
                'packageId' => $packageId,
                'error' => 'invalid domain',
            ], JSON_UNESCAPED_SLASHES) . "\n";

            continue;
        }

        try {
            $records = dumpDomainRecords($domain, $typeCodes);

            echo json_encode([
                'domain' => $domain,
                'ok' => true,
                'packageId' => $packageId,
                'records' => $records,
            ], JSON_UNESCAPED_SLASHES) . "\n";

            fwrite(STDERR, "OK (" . count($records) . " records)\n");
        } catch (Throwable $exception) {
            $failureCount++;
            fwrite(STDERR, "ERROR\n");

            echo json_encode([
                'domain' => $domain,
                'ok' => false,
                'packageId' => $packageId,
                'error' => $exception->getMessage(),
            ], JSON_UNESCAPED_SLASHES) . "\n";
        }
    }

    fwrite(
        STDERR,
        "Dump complete: " . ($total - $failureCount) . " ok, "
            . "{$failureCount} failed\n"
    );

    exit($failureCount === 0 ? EXIT_SUCCESS : EXIT_PARTIAL_FAILURE);
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
