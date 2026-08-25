#!/usr/bin/env php
<?php
/**
 * Read-only DNS dump: print public records for domains attached to
 * 20i packages, from the 20i zone API and/or authoritative StackDNS.
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
use function SoftwareWrap\TwentyI\findPackageByDomain;
use function SoftwareWrap\TwentyI\getPackageDomains;
use function SoftwareWrap\TwentyI\getPackageId;
use function SoftwareWrap\TwentyI\getPackages;
use function SoftwareWrap\TwentyI\isValidDomain;
use function SoftwareWrap\TwentyI\normalizeDomain;
use function SoftwareWrap\TwentyI\responseToArray;

use const SoftwareWrap\TwentyI\Cli\EXIT_PARTIAL_FAILURE;
use const SoftwareWrap\TwentyI\Cli\EXIT_SUCCESS;

const DEFAULT_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'SOA', 'TXT', 'SRV'];
const SOURCES = ['api', 'dns', 'both'];

/**
 * Display usage information.
 */
function usage(int $exitCode = EXIT_SUCCESS): void
{
    $script = basename($_SERVER['argv'][0]);
    $stream = $exitCode === EXIT_SUCCESS ? STDOUT : STDERR;

    fwrite($stream, <<<EOT
Usage:
  {$script} [--source <api|dns|both>] [--types <list>] <domain> [<domain> ...]
  {$script} [--source <api|dns|both>] [--types <list>] < domains.txt
  {$script} [--source <api|dns|both>] [--types <list>] --all <package-domain>

Read-only. No DNS or package state is modified.

Sources:
  api   The 20i stored zone (GET /package/{id}/dns). Covers every record
        type the zone holds, including SRV, wildcard, and subhost entries.
        Reflects zone config even before StackDNS publication completes.
  dns   Live authoritative StackDNS queries for the requested --types only.
        The ground truth for "did this record publish yet?"
  both  Merge api + dns; every record carries a "source" tag. Default.

Options:
  --source <s>    api, dns, or both. Default: both.
  --types <list>  Comma-separated record types. Default: A,AAAA,CNAME,MX,NS,SOA,TXT,SRV.
                  For the api source this filters the zone read.
  --all           Dump every domain attached to the package identified by
                  the positional <package-domain>.
  --help, -h      Display this help text.

Output:
  One JSON object per domain on stdout (JSON Lines):
  {"domain":"example.com","ok":true,"packageId":"123",
   "sources":{"api":true,"dns":true},
   "records":[{"owner":"...","type":"A","ttl":3600,"rdata":"203.0.113.10","source":"dns"},
              {"owner":"*","type":"A","ttl":null,"rdata":"203.0.113.10","source":"api","fields":{...}}]}
  On failure, ok is false and "errors" carries per-source messages.
  Progress is written to stderr.

Exit status:
  0  All domains answered
  1  Usage, validation, or configuration error
  3  One or more domains failed all requested sources

EOT
    );

    exit($exitCode);
}

/**
 * Normalize one raw record from the 20i zone API.
 *
 * @param array<string,mixed> $record
 * @return array{owner:string,type:string,ttl:null,rdata:string,source:string,fields:array<string,mixed>}
 */
function normalizeApiRecord(array $record): array
{
    $host = (string) ($record['host'] ?? '');
    $type = strtoupper((string) ($record['type'] ?? ''));

    switch ($type) {
        case 'A':
            $rdata = (string) ($record['ip'] ?? '');
            break;
        case 'AAAA':
            $rdata = (string) ($record['ipv6'] ?? '');
            break;
        case 'CNAME':
        case 'NS':
            $rdata = rtrim((string) ($record['target'] ?? ''), '.');
            break;
        case 'MX':
            $rdata = ($record['pri'] ?? '') . ' '
                . rtrim((string) ($record['target'] ?? ''), '.');
            break;
        case 'SRV':
            $rdata = ($record['pri'] ?? '') . ' '
                . ($record['weight'] ?? '') . ' '
                . ($record['port'] ?? '') . ' '
                . rtrim((string) ($record['target'] ?? ''), '.');
            break;
        case 'SOA':
            $rdata = rtrim((string) ($record['mname'] ?? ''), '.') . ' '
                . rtrim((string) ($record['rname'] ?? ''), '.') . ' '
                . ($record['serial'] ?? '') . ' '
                . ($record['refresh'] ?? '') . ' '
                . ($record['retry'] ?? '') . ' '
                . ($record['expire'] ?? '') . ' '
                . ($record['minimum-ttl'] ?? '');
            break;
        case 'TXT':
            $rdata = (string) ($record['txt'] ?? '');
            break;
        default:
            $rdata = json_encode($record, JSON_UNESCAPED_SLASHES) ?: '';
            break;
    }

    return [
        'owner' => rtrim($host, '.'),
        'type' => $type,
        'ttl' => null,
        'rdata' => trim($rdata),
        'source' => 'api',
        'fields' => $record,
    ];
}

/**
 * Find the zone key in the API zone map for a domain name.
 *
 * The zone root is the longest map key that is the domain itself or a
 * suffix of it. Subdomains on a package share their parent zone.
 *
 * The original map key is returned so callers can look the entry up;
 * comparisons are performed on normalized names.
 *
 * @param array<string,mixed> $zoneMap
 */
function findZoneForDomain(array $zoneMap, string $domain): ?string
{
    $domain = normalizeDomain($domain);
    $best = null;
    $bestLength = -1;

    foreach ($zoneMap as $originalKey => $_) {
        $zone = normalizeDomain((string) $originalKey);

        if ($domain === $zone || substr($domain, -strlen('.' . $zone)) === '.' . $zone) {
            if (strlen($zone) > $bestLength) {
                $best = (string) $originalKey;
                $bestLength = strlen($zone);
            }
        }
    }

    return $best;
}

/**
 * Fetch the zone map for a package, with a per-process cache.
 *
 * GET /package/{id}/dns returns {"zone":{"records":[...]}} per zone.
 *
 * @param array<string,array<string,mixed>> $cache
 * @return array<string,mixed>
 */
function getPackageZoneMap(
    \TwentyI\API\Services $servicesApi,
    string $packageId,
    array &$cache
): array {
    if (isset($cache[$packageId])) {
        return $cache[$packageId];
    }

    $response = responseToArray(
        $servicesApi->getWithFields(
            '/package/' . rawurlencode($packageId) . '/dns'
        )
    );

    $cache[$packageId] = $response;

    return $response;
}

/**
 * Records for one domain from the 20i zone API.
 *
 * Zone roots get every record in the zone. Subdomains get records owned by
 * that exact name plus wildcard records from the zone that can cover them.
 *
 * @param array<string,mixed> $zoneMap
 * @param array<int,string> $typeFilter Uppercase types to keep; empty keeps all.
 * @return array<int,array<string,mixed>>
 */
function getApiRecordsForDomain(
    array $zoneMap,
    string $domain,
    array $typeFilter
): array {
    $zone = findZoneForDomain($zoneMap, $domain);

    if ($zone === null) {
        throw new RuntimeException(
            "No DNS zone covers '{$domain}' on this package."
        );
    }

    $zoneEntry = $zoneMap[$zone] ?? [];
    $rawRecords = [];

    if (is_array($zoneEntry)) {
        $candidate = $zoneEntry['records'] ?? $zoneEntry;

        if (is_array($candidate)) {
            $rawRecords = $candidate;
        }
    }

    $domain = normalizeDomain($domain);
    $isZoneRoot = $domain === normalizeDomain((string) $zone);
    $records = [];

    foreach ($rawRecords as $record) {
        if (!is_array($record)) {
            continue;
        }

        $host = normalizeDomain((string) ($record['host'] ?? ''));
        $type = strtoupper((string) ($record['type'] ?? ''));

        if (!$isZoneRoot) {
            $isExact = $host === $domain;
            $isCoveringWildcard = strpos($host, '*.') === 0
                && substr($domain, -strlen(substr($host, 1))) === substr($host, 1);

            if (!$isExact && !$isCoveringWildcard) {
                continue;
            }
        }

        if ($typeFilter !== [] && !in_array($type, $typeFilter, true)) {
            continue;
        }

        $records[] = normalizeApiRecord($record);
    }

    return $records;
}

/**
 * Resolve the API zone for a domain, walking ancestors across packages.
 *
 * A subdomain attached to one package often has its DNS in a parent zone
 * attached to another package. Try the domain's own package first, then
 * each ancestor name's package, until a zone covers the domain.
 *
 * @param array<string,mixed> $zoneCache
 * @param array<int,mixed> $packages
 * @return array{zone:string,records:array<int,array<string,mixed>>}
 */
function resolveApiRecordsAcrossPackages(
    \TwentyI\API\Services $servicesApi,
    array $packages,
    ?string $ownPackageId,
    string $domain,
    array $typeFilter,
    array &$zoneCache
): array {
    $candidates = [$domain];
    $labels = explode('.', $domain);

    for ($i = 1; $i < count($labels) - 1; $i++) {
        $candidates[] = implode('.', array_slice($labels, $i));
    }

    $tried = [];
    $attempts = [];

    foreach ($candidates as $candidate) {
        if ($candidate === $domain) {
            $packageId = $ownPackageId;
        } else {
            $package = findPackageByDomain($packages, $candidate);
            $packageId = $package === null ? null : getPackageId($package);
        }

        if ($packageId === null || isset($tried[$packageId])) {
            continue;
        }

        $tried[$packageId] = true;

        try {
            $zoneMap = getPackageZoneMap($servicesApi, $packageId, $zoneCache);
        } catch (Throwable $exception) {
            $attempts[] = "package {$packageId}: " . $exception->getMessage();

            continue;
        }

        $zone = findZoneForDomain($zoneMap, $domain);

        if ($zone !== null) {
            return [
                'zone' => normalizeDomain((string) $zone),
                'records' => getApiRecordsForDomain($zoneMap, $domain, $typeFilter),
            ];
        }
    }

    throw new RuntimeException(
        "No DNS zone covers '{$domain}' on any visible package."
        . ($attempts === [] ? '' : ' (' . implode(' | ', $attempts) . ')')
    );
}

/**
 * Dump all requested record types for one domain from StackDNS.
 *
 * @param array<int,int> $typeCodes
 * @return array<int,array<string,mixed>>
 */
function dumpDomainRecordsViaDns(string $domain, array $typeCodes): array
{
    $records = [];

    foreach ($typeCodes as $typeCode) {
        foreach (getStackDnsRecords($domain, $typeCode) as $record) {
            $record['source'] = 'dns';
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
$source = 'both';
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

    if ($argument === '--source') {
        $index++;

        if ($index >= $argc) {
            fail("Option '--source' requires a value.");
        }

        $source = strtolower(trim($argv[$index]));

        if (!in_array($source, SOURCES, true)) {
            fail("Invalid --source '{$source}'. Use api, dns, or both.");
        }

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

$typeNames = array_values(array_unique($types));

/*
 * The dns source can only query types the packet layer understands.
 */
if ($source !== 'api') {
    try {
        $typeCodes = array_map(
            'SoftwareWrap\\TwentyI\\Dns\\recordTypeCode',
            $typeNames
        );
    } catch (Throwable $exception) {
        fail($exception->getMessage());
    }
} else {
    $typeCodes = [];
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

    $wantApi = $source === 'api' || $source === 'both';
    $wantDns = $source === 'dns' || $source === 'both';
    $zoneCache = [];
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
                'sources' => (object) [],
                'records' => [],
                'errors' => ['invalid' => 'invalid domain'],
            ], JSON_UNESCAPED_SLASHES) . "\n";

            continue;
        }

        $records = [];
        $errors = [];
        $sources = [];
        $apiZone = null;

        if ($wantApi) {
            try {
                $apiResult = resolveApiRecordsAcrossPackages(
                    $servicesApi,
                    $packages,
                    $packageId,
                    $domain,
                    $typeNames,
                    $zoneCache
                );

                $records = array_merge($records, $apiResult['records']);
                $apiZone = $apiResult['zone'];
                $sources['api'] = true;
            } catch (Throwable $exception) {
                $errors['api'] = $exception->getMessage();
                $sources['api'] = false;
            }
        }

        if ($wantDns) {
            try {
                $dnsRecords = dumpDomainRecordsViaDns($domain, $typeCodes);

                $records = array_merge($records, $dnsRecords);
                $sources['dns'] = true;
            } catch (Throwable $exception) {
                $errors['dns'] = $exception->getMessage();
                $sources['dns'] = false;
            }
        }

        $anySucceeded = in_array(true, $sources, true);

        if (!$anySucceeded) {
            $failureCount++;
            fwrite(STDERR, "ERROR\n");
        } else {
            fwrite(STDERR, "OK (" . count($records) . " records)\n");
        }

        echo json_encode([
            'domain' => $domain,
            'ok' => $anySucceeded,
            'packageId' => $packageId,
            'apiZone' => $apiZone,
            'sources' => (object) $sources,
            'records' => $records,
            'errors' => (object) $errors,
        ], JSON_UNESCAPED_SLASHES) . "\n";
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
