<?php
/**
 * Shared helpers for locating and inspecting 20i hosting packages.
 *
 * This file is part of a software project licensed under the
 * GNU General Public License v3.0.
 *
 * Copyright (C) 2026 Stephen Amerige
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * Original Author: Stephen Amerige, Raleigh, North Carolina
 * Created: July 11, 2026
 */

declare(strict_types=1);

namespace SoftwareWrap\TwentyI;

use RuntimeException;
use TwentyI\API\Services;

/**
 * Normalize a domain name for lookup and comparison.
 */
function normalizeDomain(string $domain): string
{
    return strtolower(rtrim(trim($domain), '.'));
}

/**
 * Determine whether a value is a syntactically valid domain name.
 */
function isValidDomain(string $domain): bool
{
    if ($domain === '' || strlen($domain) > 253 || strpos($domain, '.') === false) {
        return false;
    }

    return filter_var(
        $domain,
        FILTER_VALIDATE_DOMAIN,
        FILTER_FLAG_HOSTNAME
    ) !== false;
}

/**
 * Determine whether a value is a valid DNS query name.
 *
 * Record owner names may carry leading underscores
 * (_dmarc.example.com, _sip._tcp.example.com), which hostname validation
 * rejects. Each label follows hostname shape otherwise, except that a
 * leading underscore is permitted so SRV and TXT owners can be queried.
 */
function isValidQueryName(string $domain): bool
{
    if ($domain === '' || strlen($domain) > 253 || strpos($domain, '.') === false) {
        return false;
    }

    foreach (explode('.', $domain) as $label) {
        if ($label === '' || strlen($label) > 63) {
            return false;
        }

        if (filter_var($label, FILTER_VALIDATE_REGEXP, [
            'options' => ['regexp' => '/^(?!-)[A-Za-z0-9_-]+(?<!-)$/'],
        ]) === false) {
            return false;
        }
    }

    return true;
}

/**
 * Convert a response returned by the 20i client to an array.
 *
 * @param mixed $response
 * @return array<mixed>
 */
function responseToArray($response): array
{
    $json = json_encode($response);

    if ($json === false) {
        throw new RuntimeException('Unable to encode the 20i API response as JSON.');
    }

    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('The 20i API returned an unexpected response.');
    }

    return $decoded;
}

/**
 * Retrieve all hosting packages visible to the API key.
 *
 * @return array<mixed>
 */
function getPackages(Services $servicesApi): array
{
    return responseToArray(
        $servicesApi->getWithFields('/package')
    );
}

/**
 * Find the package containing the supplied domain name.
 *
 * The returned package is the complete package entry from GET /package.
 * A null return value means that the domain is not attached to any package
 * visible to the current API key.
 *
 * @param array<mixed> $packages
 * @return array<string,mixed>|null
 */
function findPackageByDomain(array $packages, string $domain): ?array
{
    $domain = normalizeDomain($domain);

    foreach ($packages as $package) {
        if (!is_array($package) || !isset($package['names']) || !is_array($package['names'])) {
            continue;
        }

        foreach ($package['names'] as $name) {
            if (is_string($name) && normalizeDomain($name) === $domain) {
                return $package;
            }
        }
    }

    return null;
}

/**
 * Retrieve the account package containing the supplied domain name.
 *
 * This convenience function performs one GET /package request and scans the
 * returned names. For checking many domains, call getPackages() once and then
 * call findPackageByDomain() repeatedly instead.
 *
 * @return array<string,mixed>|null
 */
function getPackageByDomain(Services $servicesApi, string $domain): ?array
{
    return findPackageByDomain(
        getPackages($servicesApi),
        $domain
    );
}

/**
 * Return a usable package ID.
 *
 * @param array<string,mixed> $package
 */
function getPackageId(array $package): string
{
    if (
        !isset($package['id'])
        || (!is_int($package['id']) && !is_string($package['id']))
    ) {
        throw new RuntimeException(
            'The package does not contain a usable package ID.'
        );
    }

    return (string) $package['id'];
}

/**
 * Return a helpful domain selector for a package.
 *
 * The first valid domain name in the package's names array is returned.
 * If the package contains no usable names, "unknown" is returned.
 *
 * @param array<string,mixed> $package
 */
function getPackageSelector(array $package): string
{
    foreach (getPackageDomains($package) as $domain) {
        return $domain;
    }

    return 'unknown';
}

/**
 * Return the normalized domain names attached to a package.
 *
 * Duplicate names are removed while preserving their original order.
 *
 * @param array<string,mixed> $package
 * @return array<int,string>
 */
function getPackageDomains(array $package): array
{
    if (!isset($package['names']) || !is_array($package['names'])) {
        return [];
    }

    $domains = [];

    foreach ($package['names'] as $name) {
        if (!is_string($name)) {
            continue;
        }

        $domain = normalizeDomain($name);

        if ($domain === '') {
            continue;
        }

        $domains[$domain] = true;
    }

    return array_keys($domains);
}
