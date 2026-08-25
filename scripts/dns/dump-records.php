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
 * stdout carries machine-consumable JSON Lines. The vendored 20i client and
 * PHP runtime can raise notices and warnings mid-run (for example the
 * client's "404 on <url>" notice); with CLI defaults those interleave into
 * stdout and corrupt the stream. Deprecations from the vendored client are
 * dropped outright; every other diagnostic is routed to STDERR.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 'stderr');
set_error_handler(static function (
    int $severity,
    string $message,
    string $file,
    int $line
): bool {
    if ((error_reporting() & $severity) === 0) {
        return true;
    }

    fwrite(STDERR, "[php] {$message} in {$file}:{$line}\n");

    return true;
});

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/cli.php';
require_once __DIR__ . '/../../lib/dns.php';
require_once __DIR__ . '/../../lib/package.php';
require_once __DIR__ . '/../../lib/zone-records.php';

use function SoftwareWrap\TwentyI\Cli\emitDomainLine;
use function SoftwareWrap\TwentyI\Cli\fail;
use function SoftwareWrap\TwentyI\Cli\readLinesFromStdin;
use function SoftwareWrap\TwentyI\Dns\getStackDnsRecords;
use function SoftwareWrap\TwentyI\findPackageByDomain;
use function SoftwareWrap\TwentyI\getPackageDomains;
use function SoftwareWrap\TwentyI\getPackageId;
use function SoftwareWrap\TwentyI\getPackages;
use function SoftwareWrap\TwentyI\isValidDomain;
use function SoftwareWrap\TwentyI\isValidQueryName;
use function SoftwareWrap\TwentyI\normalizeDomain;
use function SoftwareWrap\TwentyI\responseToArray;

use function SoftwareWrap\TwentyI\Cli\sanitizeApiError;
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
  api   The 20i stored zone (GET /package/{id}/dns). Exports every record
        type the zone holds, including SRV, wildcard, and subhost entries,
        unless narrowed with --types. Reflects zone config even before
        StackDNS publication completes.
  dns   Live authoritative StackDNS queries for the requested --types only.
        The ground truth for "did this record publish yet?"
  both  Merge api + dns; every record carries a "source" tag. Default.

Options:
  --source <s>    api, dns, or both. Default: both.
  --types <list>  Comma-separated record types. Default for dns queries:
                  A,AAAA,CNAME,MX,NS,SOA,TXT,SRV. Without --types the api
                  source is not filtered at all; with it, both sources are
                  narrowed to the list.
  --all           Dump every domain attached to the package identified by
                  the positional <package-domain>. Standard input is
                  ignored in this mode.
  --help, -h      Display this help text.

Query names may use leading underscores (_dmarc.example.com,
_sip._tcp.example.com) for TXT and SRV owner checks.

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
 * Fetch the zone map for a package, with a per-process cache.
 *
 * GET /package/{id}/dns returns an entry per zone root on the package.
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
            $attempts[] = "package {$packageId}: "
                . sanitizeApiError($exception);
            fwrite(
                STDERR,
                "\n[php] package {$packageId}: " . $exception->getMessage() . "\n"
            );

            continue;
        }

        $zone = \SoftwareWrap\TwentyI\Dns\findZoneForDomain($zoneMap, $domain);

        if ($zone !== null) {
            return [
                'zone' => normalizeDomain((string) $zone),
                'records' => \SoftwareWrap\TwentyI\Dns\getApiRecordsForDomain(
                    $zoneMap,
                    $domain,
                    $typeFilter
                ),
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
$typesExplicit = false;
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
        $typesExplicit = true;

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
 * The api source exports the zone verbatim unless the operator narrows it
 * with an explicit --types. The dns source always needs concrete types;
 * its default is the packet-layer set.
 */
$apiTypeFilter = $typesExplicit ? $typeNames : [];

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
    foreach ($typeNames as $typeName) {
        try {
            \SoftwareWrap\TwentyI\Dns\recordTypeCode($typeName);
        } catch (Throwable $unused) {
            fwrite(
                STDERR,
                "[warn] type '{$typeName}' is outside the known packet-layer"
                    . " set; verify the spelling.\n"
            );
        }
    }

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

        if (!isValidQueryName($domain)) {
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

        if (!isValidQueryName($domain)) {
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

        if (!isValidQueryName($domain)) {
            $failureCount++;
            fwrite(STDERR, "ERROR\n");

            emitDomainLine([
                'domain' => $domain,
                'ok' => false,
                'packageId' => $packageId,
                'apiZone' => null,
                'sources' => (object) [],
                'records' => [],
                'errors' => ['invalid' => 'invalid query name'],
            ]);

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
                    $apiTypeFilter,
                    $zoneCache
                );

                $records = array_merge($records, $apiResult['records']);
                $apiZone = $apiResult['zone'];
                $sources['api'] = true;
            } catch (Throwable $exception) {
                $errors['api'] = sanitizeApiError($exception);
                fwrite(
                    STDERR,
                    "\n[php] api detail: " . $exception->getMessage() . "\n"
                );
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
        fwrite(
            STDERR,
            $anySucceeded
                ? "OK (" . count($records) . " records)\n"
                : "ERROR\n"
        );

        /*
         * A degraded row (encode fallback) counts as a failure only when
         * the domain was otherwise answered; an unanswered domain is
         * counted once regardless of how its row came out.
         */
        $rowClean = emitDomainLine([
            'domain' => $domain,
            'ok' => $anySucceeded,
            'packageId' => $packageId,
            'apiZone' => $apiZone,
            'sources' => (object) $sources,
            'records' => $records,
            'errors' => (object) $errors,
        ]);

        if (!$anySucceeded || !$rowClean) {
            if (!$rowClean && $anySucceeded) {
                fwrite(STDERR, "[warn] row degraded during encode\n");
            }

            $failureCount++;
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
