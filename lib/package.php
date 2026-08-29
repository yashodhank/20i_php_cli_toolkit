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

/**
 * Find a package entry by its package ID.
 *
 * @param array<mixed> $packages
 * @return array<string,mixed>|null
 */
function getPackageById(array $packages, string $packageId): ?array
{
    foreach ($packages as $package) {
        if (!is_array($package) || !isset($package['id'])) {
            continue;
        }

        if ((string) $package['id'] === $packageId) {
            return $package;
        }
    }

    return null;
}

/**
 * Determine whether a package entry carries a domain name.
 *
 * A null package (not found) never carries any domain. Comparison uses
 * normalized names on both sides.
 *
 * @param array<string,mixed>|null $package
 */
function packageHasDomain(?array $package, string $domain): bool
{
    if ($package === null) {
        return false;
    }

    return in_array(
        normalizeDomain($domain),
        getPackageDomains($package),
        true
    );
}

/**
 * Retrieve the domain names mapped to a package via GET /package/{id}/names.
 *
 * The response shape is tolerated as either a plain list of names or an
 * object carrying a "names" list. Names are normalized and deduplicated
 * preserving order.
 *
 * @return array<int,string>
 */
function getPackageNames(Services $servicesApi, string $packageId): array
{
    $response = responseToArray(
        $servicesApi->getWithFields(
            '/package/' . rawurlencode($packageId) . '/names'
        )
    );

    $candidates = $response;

    if (isset($response['names']) && is_array($response['names'])) {
        $candidates = $response['names'];
    }

    $names = [];

    foreach ($candidates as $name) {
        if (!is_string($name)) {
            continue;
        }

        $normalized = normalizeDomain($name);

        if ($normalized === '') {
            continue;
        }

        $names[$normalized] = true;
    }

    return array_keys($names);
}

/**
 * Submit one names diff for a package.
 *
 * This is the single owner of POST /package/{id}/names. Every add and
 * every removal in this codebase must route through here so that the wire
 * shape {add, rem, chg} lives in exactly one place.
 *
 * @param array<int,string> $add
 * @param array<int,string> $rem
 */
function postPackageNames(
    Services $servicesApi,
    string $packageId,
    array $add,
    array $rem,
    ?string $chg
): void {
    $servicesApi->postWithFields(
        '/package/' . rawurlencode($packageId) . '/names',
        [
            'add' => array_values($add),
            'rem' => array_values($rem),
            'chg' => $chg,
        ]
    );
}

/**
 * Add domain names to a package.
 *
 * The API silently skips names already mapped to the package, so this
 * call is idempotent for repeated adds.
 *
 * @param array<int,string> $names
 */
function addNamesToPackage(
    Services $servicesApi,
    string $packageId,
    array $names
): void {
    postPackageNames($servicesApi, $packageId, $names, [], null);
}

/**
 * Remove one domain name from a package.
 *
 * When the removed name is the package's primary name, $chg must carry a
 * surviving name. Callers are responsible for the last-name guard: the API
 * hard-errors when a removal would leave a package without names.
 */
function removeNameFromPackage(
    Services $servicesApi,
    string $packageId,
    string $name,
    ?string $chg = null
): void {
    postPackageNames($servicesApi, $packageId, [], [$name], $chg);
}

/**
 * Predict whether removing the given names would leave a package empty.
 *
 * Both lists are normalized before comparison, so cumulative batch
 * removals are handled by passing every planned removal at once.
 *
 * @param array<int,string> $currentNames
 * @param array<int,string> $removals
 */
function packageWouldBeEmptyAfterRemoval(
    array $currentNames,
    array $removals
): bool {
    $removalSet = [];

    foreach ($removals as $removal) {
        $removalSet[normalizeDomain($removal)] = true;
    }

    foreach ($currentNames as $name) {
        $normalized = normalizeDomain($name);

        if ($normalized !== '' && !isset($removalSet[$normalized])) {
            return false;
        }
    }

    return true;
}

/**
 * Pick the deterministic surviving primary name after a removal.
 *
 * The survivor is the first entry of the current name list that is not
 * being removed — the same order getPackageDomains() yields. Null means
 * no name would survive.
 *
 * @param array<int,string> $currentNames
 * @param array<int,string> $removals
 */
function pickPrimaryAfterRemoval(
    array $currentNames,
    array $removals
): ?string {
    $removalSet = [];

    foreach ($removals as $removal) {
        $removalSet[normalizeDomain($removal)] = true;
    }

    foreach ($currentNames as $name) {
        $normalized = normalizeDomain($name);

        if ($normalized !== '' && !isset($removalSet[$normalized])) {
            return $normalized;
        }
    }

    return null;
}

/**
 * Plan a sequence of single-name removals against one package.
 *
 * Enforces, before any mutation:
 *   - every removal is currently attached to the package;
 *   - no removal (including cumulatively, across the whole batch) would
 *     leave the package without any names;
 *   - removals of the then-primary name (first remaining entry) carry a
 *     deterministic chg survivor.
 *
 * @param array<int,string> $currentNames Names on the package, in order.
 * @param array<int,string> $removals Names to remove, in execution order.
 * @return array<int,array{name:string,chg:string|null}>
 * @throws RuntimeException When the plan is invalid.
 */
function planRemovalSequence(array $currentNames, array $removals): array
{
    $working = [];

    foreach ($currentNames as $name) {
        $normalized = normalizeDomain($name);

        if ($normalized !== '' && !in_array($normalized, $working, true)) {
            $working[] = $normalized;
        }
    }

    $plan = [];

    foreach ($removals as $removal) {
        $removal = normalizeDomain($removal);

        if (!in_array($removal, $working, true)) {
            throw new RuntimeException(
                "'{$removal}' is not attached to the package, so it cannot be removed."
            );
        }

        if (count($working) === 1) {
            throw new RuntimeException(
                "Removing '{$removal}' would leave the package without any names. "
                . 'The API forbids removing the last name of a package.'
            );
        }

        $isPrimary = $working[0] === $removal;
        $chg = $isPrimary ? pickPrimaryAfterRemoval($working, [$removal]) : null;

        $plan[] = [
            'name' => $removal,
            'chg' => $chg,
        ];

        $remaining = [];

        foreach ($working as $name) {
            if ($name !== $removal) {
                $remaining[] = $name;
            }
        }

        $working = $remaining;
    }

    return $plan;
}

/**
 * Classify a domain for detachment from a source package.
 *
 * Statuses: on-source (eligible), not-attached, on-other.
 * A domain attached to the source package classifies as on-source even
 * when it is also attached elsewhere.
 *
 * @param array<mixed> $packages GET /package snapshot.
 * @return array{status:string,packageId:string|null,selector:string|null}
 */
function classifyDomainForDetach(
    array $packages,
    string $domain,
    string $sourcePackageId
): array {
    $sourcePackage = getPackageById($packages, $sourcePackageId);

    if (packageHasDomain($sourcePackage, $domain)) {
        return [
            'status' => 'on-source',
            'packageId' => $sourcePackageId,
            'selector' => $sourcePackage === null
                ? null
                : getPackageSelector($sourcePackage),
        ];
    }

    $holder = findPackageByDomain($packages, $domain);

    if ($holder === null) {
        return [
            'status' => 'not-attached',
            'packageId' => null,
            'selector' => null,
        ];
    }

    return [
        'status' => 'on-other',
        'packageId' => getPackageId($holder),
        'selector' => getPackageSelector($holder),
    ];
}

/**
 * Classify a domain for a move from a source package to a target package.
 *
 * Statuses: on-source (eligible), on-target (idempotent skip),
 * not-attached, on-third. A domain attached to BOTH source and target
 * (the leftover of an interrupted move) classifies as on-source: the add
 * step is idempotent, so re-running the move completes the detach.
 *
 * @param array<mixed> $packages GET /package snapshot.
 * @return array{status:string,packageId:string|null,selector:string|null}
 */
function classifyDomainForMove(
    array $packages,
    string $domain,
    string $sourcePackageId,
    string $targetPackageId
): array {
    $sourcePackage = getPackageById($packages, $sourcePackageId);

    if (packageHasDomain($sourcePackage, $domain)) {
        return [
            'status' => 'on-source',
            'packageId' => $sourcePackageId,
            'selector' => $sourcePackage === null
                ? null
                : getPackageSelector($sourcePackage),
        ];
    }

    $targetPackage = getPackageById($packages, $targetPackageId);

    if (packageHasDomain($targetPackage, $domain)) {
        return [
            'status' => 'on-target',
            'packageId' => $targetPackageId,
            'selector' => $targetPackage === null
                ? null
                : getPackageSelector($targetPackage),
        ];
    }

    $holder = findPackageByDomain($packages, $domain);

    if ($holder === null) {
        return [
            'status' => 'not-attached',
            'packageId' => null,
            'selector' => null,
        ];
    }

    return [
        'status' => 'on-third',
        'packageId' => getPackageId($holder),
        'selector' => getPackageSelector($holder),
    ];
}

/**
 * Verify that a domain is no longer attached to a specific package.
 *
 * Probes the package list up to $attempts times, sleeping between
 * attempts. Membership is checked against the specific package ID — not
 * via findPackageByDomain() — because during a move the domain
 * legitimately lives on another package.
 *
 * $fetchPackages and $sleeper are injectable for offline tests; by
 * default they call getPackages() and sleep().
 *
 * @param callable|null $fetchPackages fn(): array — package list snapshot.
 * @param callable|null $sleeper fn(int $seconds): void.
 */
function verifyDomainAbsentFromPackage(
    ?Services $servicesApi,
    string $domain,
    string $packageId,
    int $attempts = 3,
    int $delaySeconds = 1,
    ?callable $fetchPackages = null,
    ?callable $sleeper = null
): bool {
    if ($fetchPackages === null) {
        if ($servicesApi === null) {
            throw new RuntimeException(
                'verifyDomainAbsentFromPackage() needs a Services client or a fetchPackages callable.'
            );
        }

        $fetchPackages = static function () use ($servicesApi): array {
            return getPackages($servicesApi);
        };
    }

    if ($sleeper === null) {
        $sleeper = static function (int $seconds): void {
            sleep($seconds);
        };
    }

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $packages = $fetchPackages();
        $package = getPackageById($packages, $packageId);

        if (!packageHasDomain($package, $domain)) {
            return true;
        }

        if ($attempt < $attempts) {
            $sleeper($delaySeconds);
        }
    }

    return false;
}

/**
 * Verify that a domain is attached to a specific package.
 *
 * The counterpart of verifyDomainAbsentFromPackage(), with the same
 * probing behavior and the same package-ID-specific membership check.
 * During a move the domain is briefly on both packages, so a
 * findPackageByDomain()-based check could return the wrong package.
 *
 * @param callable|null $fetchPackages fn(): array — package list snapshot.
 * @param callable|null $sleeper fn(int $seconds): void.
 */
function verifyDomainPresentOnPackage(
    ?Services $servicesApi,
    string $domain,
    string $packageId,
    int $attempts = 3,
    int $delaySeconds = 1,
    ?callable $fetchPackages = null,
    ?callable $sleeper = null
): bool {
    if ($fetchPackages === null) {
        if ($servicesApi === null) {
            throw new RuntimeException(
                'verifyDomainPresentOnPackage() needs a Services client or a fetchPackages callable.'
            );
        }

        $fetchPackages = static function () use ($servicesApi): array {
            return getPackages($servicesApi);
        };
    }

    if ($sleeper === null) {
        $sleeper = static function (int $seconds): void {
            sleep($seconds);
        };
    }

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $packages = $fetchPackages();
        $package = getPackageById($packages, $packageId);

        if (packageHasDomain($package, $domain)) {
            return true;
        }

        if ($attempt < $attempts) {
            $sleeper($delaySeconds);
        }
    }

    return false;
}

/**
 * Execute one domain move with the frozen step ordering.
 *
 * Ordering (frozen by the lifecycle-expansion contract):
 *   1. add to target
 *   2. verify present on target
 *   3. remove from source
 *   4. verify absent from source
 *
 * Any failure at step 1 changes nothing: status "failed-clean".
 * Any failure after step 1 leaves the domain on BOTH packages; the domain
 * is NEVER auto-removed from the target. Status "needs-manual-detach" and
 * the message carries "NEEDS MANUAL DETACH FROM SOURCE (package {id})".
 *
 * All four steps are injected callables so the state machine is testable
 * offline. $verifyPresentOnTarget / $verifyAbsentFromSource return bool.
 *
 * @return array{status:string,message:string}
 */
function runMoveSequence(
    string $sourcePackageId,
    callable $addToTarget,
    callable $verifyPresentOnTarget,
    callable $removeFromSource,
    callable $verifyAbsentFromSource
): array {
    try {
        $addToTarget();
    } catch (\Throwable $exception) {
        return [
            'status' => 'failed-clean',
            'message' => 'add to target failed, nothing changed: '
                . $exception->getMessage(),
        ];
    }

    $manualDetach = 'NEEDS MANUAL DETACH FROM SOURCE (package '
        . $sourcePackageId . ')';

    try {
        if (!$verifyPresentOnTarget()) {
            return [
                'status' => 'needs-manual-detach',
                'message' => 'added, but presence on the target package could '
                    . 'not be verified; the domain may now be on both packages. '
                    . $manualDetach,
            ];
        }
    } catch (\Throwable $exception) {
        return [
            'status' => 'needs-manual-detach',
            'message' => 'added, but target verification failed ('
                . $exception->getMessage()
                . '); the domain may now be on both packages. '
                . $manualDetach,
        ];
    }

    try {
        $removeFromSource();
    } catch (\Throwable $exception) {
        return [
            'status' => 'needs-manual-detach',
            'message' => 'removal from the source package failed ('
                . $exception->getMessage()
                . '); the domain is on both packages. ' . $manualDetach,
        ];
    }

    try {
        if (!$verifyAbsentFromSource()) {
            return [
                'status' => 'needs-manual-detach',
                'message' => 'removed, but absence from the source package '
                    . 'could not be verified. ' . $manualDetach,
            ];
        }
    } catch (\Throwable $exception) {
        return [
            'status' => 'needs-manual-detach',
            'message' => 'removed, but source verification failed ('
                . $exception->getMessage() . '). ' . $manualDetach,
        ];
    }

    return [
        'status' => 'moved',
        'message' => 'moved',
    ];
}

/**
 * Return the base state directory for the 20i CLI tools.
 *
 * Resolution mirrors the DNS submission journal in
 * scripts/dns/add-records.php: XDG_STATE_HOME, then ~/.local/state, then
 * LOCALAPPDATA/APPDATA, then the system temp directory — always with a
 * trailing "20i-cli" component.
 */
function resolveStateDirectory(): string
{
    $stateHome = getenv('XDG_STATE_HOME');

    if (is_string($stateHome) && trim($stateHome) !== '') {
        return rtrim($stateHome, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . '20i-cli';
    }

    $home = getenv('HOME');

    if (is_string($home) && trim($home) !== '') {
        return rtrim($home, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . '.local'
            . DIRECTORY_SEPARATOR . 'state'
            . DIRECTORY_SEPARATOR . '20i-cli';
    }

    $appData = getenv('LOCALAPPDATA') ?: getenv('APPDATA');

    if (is_string($appData) && trim($appData) !== '') {
        return rtrim($appData, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . '20i-cli';
    }

    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . '20i-cli';
}

/**
 * Build a dump-records-shaped snapshot payload for one domain's zone.
 *
 * Callers must have loaded lib/zone-records.php. The payload matches the
 * JSON Lines rows emitted by scripts/dns/dump-records.php with the api
 * source: normalized records including the raw "fields".
 *
 * @param array<string,mixed> $zoneMap GET /package/{id}/dns response.
 * @return array<string,mixed>
 * @throws \RuntimeException When no zone on the package covers the domain.
 */
function buildZoneSnapshotPayload(
    array $zoneMap,
    string $domain,
    string $packageId
): array {
    $zone = \SoftwareWrap\TwentyI\Dns\findZoneForDomain($zoneMap, $domain);

    if ($zone === null) {
        throw new RuntimeException(
            "No DNS zone covers '{$domain}' on package {$packageId}."
        );
    }

    $records = \SoftwareWrap\TwentyI\Dns\getApiRecordsForDomain(
        $zoneMap,
        $domain,
        []
    );

    return [
        'domain' => normalizeDomain($domain),
        'ok' => true,
        'packageId' => $packageId,
        'apiZone' => normalizeDomain((string) $zone),
        'sources' => ['api' => true],
        'records' => $records,
        'errors' => new \stdClass(),
    ];
}

/**
 * Write one zone snapshot payload as a JSON Lines file in the state dir.
 *
 * The directory is created 0700 and the file written 0600. The file name
 * is "<domain>-<utcstamp>.jsonl"; the stamp is injectable for tests and
 * defaults to the current UTC time.
 *
 * @param array<string,mixed> $payload
 * @return string The path written.
 * @throws RuntimeException On any filesystem or encoding failure.
 */
function writeZoneSnapshotFile(
    string $directory,
    string $domain,
    array $payload,
    ?string $utcStamp = null
): string {
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException(
            "Unable to create snapshot directory '{$directory}'."
        );
    }

    @chmod($directory, 0700);

    if ($utcStamp === null) {
        $utcStamp = gmdate('Ymd\THis\Z');
    }

    $path = $directory
        . DIRECTORY_SEPARATOR
        . normalizeDomain($domain) . '-' . $utcStamp . '.jsonl';

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException(
            "Unable to encode the zone snapshot for '{$domain}'."
        );
    }

    if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException(
            "Unable to write zone snapshot '{$path}'."
        );
    }

    if (!@chmod($path, 0600)) {
        @unlink($path);

        throw new RuntimeException(
            "Unable to restrict permissions on zone snapshot '{$path}'."
        );
    }

    return $path;
}
