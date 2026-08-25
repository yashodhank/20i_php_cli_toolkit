<?php
/**
 * Pure helpers that shape records from the 20i stored-zone API.
 *
 * These are unit-testable by design: they take already-decoded API data
 * and perform no I/O.
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

namespace SoftwareWrap\TwentyI\Dns;

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
            $txtValue = $record['txt'] ?? '';

            if (is_array($txtValue)) {
                /*
                 * Long TXT values (DKIM keys and similar) arrive chunked;
                 * the chunks concatenate in order.
                 */
                $txtValue = implode('', array_map('strval', $txtValue));
            }

            $rdata = (string) $txtValue;
            break;
        default:
            $rdata = json_encode(
                $record,
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ) ?: '';
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
 * comparisons are performed on normalized names. Ties prefer the key
 * whose normalized form equals the queried domain.
 *
 * @param array<string,mixed> $zoneMap
 */
function findZoneForDomain(array $zoneMap, string $domain): ?string
{
    $domain = \SoftwareWrap\TwentyI\normalizeDomain($domain);
    $best = null;
    $bestLength = -1;

    foreach ($zoneMap as $originalKey => $_) {
        $zone = \SoftwareWrap\TwentyI\normalizeDomain((string) $originalKey);

        /*
         * Wildcard names are record owners, never zone roots; a
         * wildcard-prefixed key would otherwise suffix-match and hijack
         * resolution away from the true zone.
         */
        if (strpos($zone, '*.') === 0) {
            continue;
        }

        if ($domain === $zone || substr($domain, -strlen('.' . $zone)) === '.' . $zone) {
            if (strlen($zone) > $bestLength
                || (strlen($zone) === $bestLength && $zone === $domain)
            ) {
                $best = (string) $originalKey;
                $bestLength = strlen($zone);
            }
        }
    }

    return $best;
}

/**
 * Records for one domain from the 20i zone API.
 *
 * Zone roots get every record in the zone. Subdomains get records owned by
 * that exact name plus one-label wildcard records that cover them
 * (RFC 4592). Unrecognized response shapes throw instead of degrading to an
 * empty list, because a silently empty zone would corrupt audits.
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
        throw new \RuntimeException(
            "No DNS zone covers '{$domain}' on this package."
        );
    }

    if (!array_key_exists($zone, $zoneMap) || !is_array($zoneMap[$zone])) {
        throw new \RuntimeException(
            "Unexpected zone shape for '{$zone}': expected an object, found "
            . gettype($zoneMap[$zone] ?? null) . '.'
        );
    }

    $zoneEntry = $zoneMap[$zone];
    $rawRecords = [];

    if (is_array($zoneEntry)) {
        $candidate = $zoneEntry['records'] ?? $zoneEntry;

        if (is_array($candidate)) {
            if (!array_is_list($candidate)) {
                throw new \RuntimeException(
                    "Unexpected record shape for zone '{$zone}': expected a"
                    . ' list of records, found a keyed map.'
                );
            }

            foreach ($candidate as $entry) {
                if (!is_array($entry)) {
                    throw new \RuntimeException(
                        "Unexpected record shape for zone '{$zone}': expected"
                        . ' records as objects, found '
                        . gettype($entry) . '.'
                    );
                }

                $rawRecords[] = $entry;
            }
        } elseif ($candidate !== null) {
            throw new \RuntimeException(
                "Unexpected record shape for zone '{$zone}': expected a list,"
                . ' found ' . gettype($candidate) . '.'
            );
        }
    }

    $domain = \SoftwareWrap\TwentyI\normalizeDomain($domain);
    $isZoneRoot = $domain === \SoftwareWrap\TwentyI\normalizeDomain((string) $zone);
    $records = [];

    foreach ($rawRecords as $record) {
        $host = \SoftwareWrap\TwentyI\normalizeDomain((string) ($record['host'] ?? ''));
        $type = strtoupper((string) ($record['type'] ?? ''));

        if (!$isZoneRoot) {
            $isExact = $host === $domain;

            /*
             * A wildcard owns exactly one label beneath its parent
             * (RFC 4592): *.zone covers a.zone but not a.b.zone.
             */
            $isCoveringWildcard = false;

            if (strpos($host, '*.') === 0) {
                $wildcardParent = substr($host, 1);

                if (substr($domain, -strlen($wildcardParent)) === $wildcardParent) {
                    $ownedLabels = substr(
                        $domain,
                        0,
                        strlen($domain) - strlen($wildcardParent)
                    );

                    $isCoveringWildcard = $ownedLabels !== ''
                        && $ownedLabels[0] !== '.'
                        && strpos($ownedLabels, '.') === false;
                }
            }

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
