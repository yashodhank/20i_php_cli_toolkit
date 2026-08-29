#!/usr/bin/env php
<?php
/**
 * Move one or more domain names between two 20i hosting packages.
 *
 * The 20i API has no atomic cross-package move; this command composes one
 * client-side with a frozen ordering: add to target, verify, remove from
 * source, verify. A failure after a successful add leaves the domain on
 * both packages and is reported for manual detachment — the domain is
 * never automatically removed from the target.
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
 * Created: August 30, 2026
 */

declare(strict_types=1);

/*
 * Only dependency-free libraries are loaded before argument parsing so
 * that --help works without an API key, a .env file, or the vendored
 * API client. lib/bootstrap.php is required after parsing succeeds.
 */
require_once __DIR__ . '/../../lib/cli.php';
require_once __DIR__ . '/../../lib/package.php';
require_once __DIR__ . '/../../lib/zone-records.php';

use function SoftwareWrap\TwentyI\Cli\confirm;
use function SoftwareWrap\TwentyI\Cli\fail;
use function SoftwareWrap\TwentyI\Cli\readLinesFromStdin;
use function SoftwareWrap\TwentyI\Cli\sanitizeApiError;
use function SoftwareWrap\TwentyI\addNamesToPackage;
use function SoftwareWrap\TwentyI\buildZoneSnapshotPayload;
use function SoftwareWrap\TwentyI\classifyDomainForMove;
use function SoftwareWrap\TwentyI\findPackageByDomain;
use function SoftwareWrap\TwentyI\getPackageDomains;
use function SoftwareWrap\TwentyI\getPackageId;
use function SoftwareWrap\TwentyI\getPackages;
use function SoftwareWrap\TwentyI\isValidDomain;
use function SoftwareWrap\TwentyI\normalizeDomain;
use function SoftwareWrap\TwentyI\pickPrimaryAfterRemoval;
use function SoftwareWrap\TwentyI\planRemovalSequence;
use function SoftwareWrap\TwentyI\removeNameFromPackage;
use function SoftwareWrap\TwentyI\resolveStateDirectory;
use function SoftwareWrap\TwentyI\responseToArray;
use function SoftwareWrap\TwentyI\runMoveSequence;
use function SoftwareWrap\TwentyI\verifyDomainAbsentFromPackage;
use function SoftwareWrap\TwentyI\verifyDomainPresentOnPackage;
use function SoftwareWrap\TwentyI\writeZoneSnapshotFile;

use const SoftwareWrap\TwentyI\Cli\EXIT_CANCELLED;
use const SoftwareWrap\TwentyI\Cli\EXIT_ERROR;
use const SoftwareWrap\TwentyI\Cli\EXIT_PARTIAL_FAILURE;
use const SoftwareWrap\TwentyI\Cli\EXIT_SUCCESS;

const CONFIRMATION_THRESHOLD = 10;
const VERIFICATION_ATTEMPTS = 3;
const VERIFICATION_DELAY_SECONDS = 1;

/**
 * Display usage information.
 */
function usage(int $exitCode = EXIT_SUCCESS): void
{
    $script = basename($_SERVER['argv'][0]);
    $stream = $exitCode === EXIT_SUCCESS ? STDOUT : STDERR;

    fwrite($stream, <<<EOT
Usage:
  {$script} [--dry-run] [--yes] [--skip] <source-package-domain> <target-package-domain> <domain>
  {$script} [--dry-run] [--yes] [--skip] <source-package-domain> <target-package-domain> < domains.txt

Options:
  --dry-run  Resolve both packages, run every preflight guard, and show
             what would be done without modifying either package.
  --yes, -y  Skip the confirmation prompt for a batch of 10 or more domains,
             and proceed without a per-domain prompt when the best-effort
             zone snapshot cannot be written.
  --skip     Skip domains that are not movable: domains not attached to any
             package and domains attached to a third package. Domains
             already on the target package are always skipped (idempotent).
             Skipped domains are reported at the end.
  --help, -h Display this help text.

Examples:
  {$script} old-site.com new-site.com moving-example.com
  {$script} --dry-run old-site.com new-site.com < domains.txt
  {$script} --skip --yes old-site.com new-site.com < domains.txt

Each package is identified by any domain name already attached to it.
Blank input lines and lines beginning with # are ignored.

There is no atomic cross-package move in the 20i API. Each domain is moved
with a frozen step ordering:
  1. add to the target package
  2. verify presence on the target
  3. remove from the source package
  4. verify absence from the source
A failure at step 1 changes nothing for that domain. A failure at any
later step leaves the domain attached to BOTH packages; the command
reports "NEEDS MANUAL DETACH FROM SOURCE (package <id>)" and never removes
the domain from the target automatically.

Guards (enforced before any change):
  - The source package must never be left without names. The guard is
    cumulative across the whole batch.
  - Removing the source package's primary name (its first name) sends
    "chg" with the deterministic survivor: the first remaining name.

Removing a domain from its source package destroys that domain's 20i
web-forwarding configuration. Before each move a best-effort snapshot of
the domain's stored DNS zone is written to the state directory
(XDG_STATE_HOME or ~/.local/state, under 20i-cli/snapshots/). If the
snapshot cannot be written, the domain is only processed after an explicit
confirmation (or with --yes).

EOT
    );

    exit($exitCode);
}

/**
 * Read and normalize domain names from standard input.
 *
 * @return array<int,string>
 */
function readDomainsFromStdin(): array
{
    return array_map(
        'SoftwareWrap\\TwentyI\\normalizeDomain',
        readLinesFromStdin()
    );
}

/**
 * Ask the operator to confirm a large batch operation.
 */
function confirmBatch(int $count): bool
{
    return confirm(
        "\nThis will move and verify {$count} domains individually. Continue? [y/N] "
    );
}

/**
 * Report domains skipped during preflight classification.
 *
 * @param array<string,string> $skippedDomains domain => reason
 */
function reportSkippedDomains(array $skippedDomains): void
{
    if ($skippedDomains === []) {
        return;
    }

    echo "\nSkipped domains (" . count($skippedDomains) . "):\n";

    foreach ($skippedDomains as $domain => $reason) {
        echo "  {$domain} -> {$reason}\n";
    }
}

/**
 * Fetch the source package's zone map once, caching the outcome.
 *
 * Failures are cached too, so one broken zone endpoint produces one
 * warning path per run instead of a request storm.
 *
 * @param array<string,mixed> $cache
 * @return array<string,mixed>
 */
function getZoneMapCached(
    \TwentyI\API\Services $servicesApi,
    string $packageId,
    array &$cache
): array {
    if (array_key_exists('error', $cache)) {
        throw new RuntimeException((string) $cache['error']);
    }

    if (array_key_exists('map', $cache)) {
        return $cache['map'];
    }

    try {
        $cache['map'] = responseToArray(
            $servicesApi->getWithFields(
                '/package/' . rawurlencode($packageId) . '/dns'
            )
        );
    } catch (Throwable $exception) {
        $cache['error'] = sanitizeApiError($exception);

        throw new RuntimeException($cache['error']);
    }

    return $cache['map'];
}

/*
 * Parse command-line options and positional arguments.
 */
$dryRun = false;
$assumeYes = false;
$skipIneligible = false;
$arguments = [];

for ($index = 1; $index < $argc; $index++) {
    $argument = $argv[$index];

    if ($argument === '--help' || $argument === '-h') {
        usage(EXIT_SUCCESS);
    }

    if ($argument === '--dry-run') {
        $dryRun = true;
        continue;
    }

    if ($argument === '--yes' || $argument === '-y') {
        $assumeYes = true;
        continue;
    }

    if ($argument === '--skip') {
        $skipIneligible = true;
        continue;
    }

    if (strpos($argument, '-') === 0) {
        fail("Unknown option '{$argument}'.");
    }

    $arguments[] = $argument;
}

if (count($arguments) === 2) {
    $sourceDomain = normalizeDomain($arguments[0]);
    $targetDomain = normalizeDomain($arguments[1]);
    $requestedDomains = readDomainsFromStdin();
} elseif (count($arguments) === 3) {
    $sourceDomain = normalizeDomain($arguments[0]);
    $targetDomain = normalizeDomain($arguments[1]);
    $requestedDomains = [normalizeDomain($arguments[2])];
} else {
    usage(EXIT_ERROR);
}

if (!isValidDomain($sourceDomain)) {
    fail("Invalid source package domain '{$sourceDomain}'.");
}

if (!isValidDomain($targetDomain)) {
    fail("Invalid target package domain '{$targetDomain}'.");
}

if ($requestedDomains === []) {
    fail('No domains were provided.');
}

/*
 * Validate and deduplicate the requested domains while preserving order.
 */
$uniqueDomains = [];

foreach ($requestedDomains as $domain) {
    if (!isValidDomain($domain)) {
        fail("Invalid domain '{$domain}'.");
    }

    $uniqueDomains[$domain] = true;
}

$requestedDomains = array_keys($uniqueDomains);

/*
 * Arguments are valid; load credentials and the API client.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';

try {
    $servicesApi = new \TwentyI\API\Services($api_key);

    /*
     * Fetch the package list once and preflight both packages from the
     * same snapshot.
     */
    $packages = getPackages($servicesApi);
    $sourcePackage = findPackageByDomain($packages, $sourceDomain);

    if ($sourcePackage === null) {
        fail("No package contains '{$sourceDomain}'.");
    }

    $targetPackage = findPackageByDomain($packages, $targetDomain);

    if ($targetPackage === null) {
        fail("No package contains '{$targetDomain}'.");
    }

    $sourcePackageId = getPackageId($sourcePackage);
    $targetPackageId = getPackageId($targetPackage);

    if ($sourcePackageId === $targetPackageId) {
        fail(
            "'{$sourceDomain}' and '{$targetDomain}' identify the same "
            . "package ({$sourcePackageId}); there is nothing to move."
        );
    }

    $sourceNames = getPackageDomains($sourcePackage);

    $domainsToMove = [];
    $skippedDomains = [];
    $ineligible = [];

    foreach ($requestedDomains as $domain) {
        $classification = classifyDomainForMove(
            $packages,
            $domain,
            $sourcePackageId,
            $targetPackageId
        );

        if ($classification['status'] === 'on-source') {
            $domainsToMove[] = $domain;
            continue;
        }

        if ($classification['status'] === 'on-target') {
            /*
             * Idempotent skip: the requested end state already holds.
             */
            $skippedDomains[$domain] = 'already on the target package';
            continue;
        }

        if ($classification['status'] === 'not-attached') {
            $reason = 'not attached to any package';
        } else {
            $reason = "attached to package {$classification['packageId']}"
                . " ({$classification['selector']})";
        }

        if ($skipIneligible) {
            $skippedDomains[$domain] = $reason;
        } else {
            $ineligible[$domain] = $reason;
        }
    }

    echo "Source package: {$sourceDomain} (ID {$sourcePackageId})\n";
    echo "Target package: {$targetDomain} (ID {$targetPackageId})\n";
    echo "Names on source package: " . count($sourceNames) . "\n";
    echo "Requested domains: " . count($requestedDomains) . "\n";
    echo "Domains to process: " . count($domainsToMove) . "\n";

    /*
     * Fail fast before any change unless --skip was supplied.
     */
    if ($ineligible !== []) {
        fwrite(
            STDERR,
            "\nNot movable from this package ("
            . count($ineligible)
            . "):\n"
        );

        foreach ($ineligible as $domain => $reason) {
            fwrite(STDERR, "  {$domain} -> {$reason}\n");
        }

        fail(
            'No changes were made because at least one requested domain '
            . 'is not attached to the source package. Rerun with --skip '
            . 'to ignore those domains.'
        );
    }

    if ($domainsToMove === []) {
        echo "\nNo domains need to be moved.\n";
        reportSkippedDomains($skippedDomains);
        exit(EXIT_SUCCESS);
    }

    /*
     * Cumulative last-name and primary guards on the source package,
     * before any mutation.
     */
    try {
        $removalPlan = planRemovalSequence($sourceNames, $domainsToMove);
    } catch (Throwable $planException) {
        fail($planException->getMessage());
    }

    if ($dryRun) {
        echo "\nDry-run results:\n";

        $total = count($removalPlan);

        foreach ($removalPlan as $offset => $step) {
            $position = $offset + 1;
            $chgNote = $step['chg'] === null
                ? ''
                : " (source primary; new primary: {$step['chg']})";

            echo "[{$position}/{$total}] {$step['name']} ... WOULD MOVE"
                . " to package {$targetPackageId}{$chgNote}\n";
        }

        echo "\nDry run complete. No changes were made.\n";
        reportSkippedDomains($skippedDomains);
        exit(EXIT_SUCCESS);
    }

    if (
        count($domainsToMove) >= CONFIRMATION_THRESHOLD
        && !$assumeYes
        && !confirmBatch(count($domainsToMove))
    ) {
        fwrite(STDERR, "\nOperation cancelled. No changes were made.\n");
        exit(EXIT_CANCELLED);
    }

    $snapshotDirectory = resolveStateDirectory()
        . DIRECTORY_SEPARATOR . 'snapshots';
    $zoneMapCache = [];
    $snapshotWarnings = [];

    $successCount = 0;
    $failureCount = 0;
    $failedDomains = [];
    $manualDetachDomains = [];
    $workingNames = $sourceNames;
    $total = count($domainsToMove);

    echo "\nProcessing domains:\n";

    foreach ($domainsToMove as $offset => $domain) {
        $position = $offset + 1;

        /*
         * Print and flush before the network calls so the operator always
         * sees which domain is currently being processed.
         */
        echo "[{$position}/{$total}] {$domain} ... ";
        fflush(STDOUT);

        /*
         * Best-effort zone snapshot before any change to either package.
         * Taking it before the add means a declined item changes nothing.
         */
        try {
            $zoneMap = getZoneMapCached(
                $servicesApi,
                $sourcePackageId,
                $zoneMapCache
            );
            writeZoneSnapshotFile(
                $snapshotDirectory,
                $domain,
                buildZoneSnapshotPayload($zoneMap, $domain, $sourcePackageId)
            );
        } catch (Throwable $snapshotException) {
            $snapshotWarnings[$domain] = $snapshotException->getMessage();
            fwrite(
                STDERR,
                "\nWarning: no zone snapshot for {$domain}: "
                . $snapshotException->getMessage() . "\n"
            );

            if (
                !$assumeYes
                && !confirm(
                    "No DNS snapshot could be written for {$domain}. "
                    . 'Move it anyway? [y/N] '
                )
            ) {
                echo "DECLINED (no zone snapshot)\n";
                $failureCount++;
                $failedDomains[$domain] =
                    'declined by operator after zone snapshot failure';
                continue;
            }
        }

        /*
         * The chg survivor is recomputed against the runtime name list so
         * that an earlier per-item failure cannot desynchronize the plan.
         */
        $isPrimary = $workingNames !== [] && $workingNames[0] === $domain;
        $chg = $isPrimary
            ? pickPrimaryAfterRemoval($workingNames, [$domain])
            : null;

        if ($isPrimary && $chg === null) {
            echo "ERROR: no surviving name for the primary change\n";
            $failureCount++;
            $failedDomains[$domain] =
                'no surviving name would remain on the source; move refused';
            continue;
        }

        $result = runMoveSequence(
            $sourcePackageId,
            static function () use ($servicesApi, $targetPackageId, $domain): void {
                addNamesToPackage($servicesApi, $targetPackageId, [$domain]);
            },
            static function () use ($servicesApi, $domain, $targetPackageId): bool {
                return verifyDomainPresentOnPackage(
                    $servicesApi,
                    $domain,
                    $targetPackageId,
                    VERIFICATION_ATTEMPTS,
                    VERIFICATION_DELAY_SECONDS
                );
            },
            static function () use ($servicesApi, $sourcePackageId, $domain, $chg): void {
                removeNameFromPackage(
                    $servicesApi,
                    $sourcePackageId,
                    $domain,
                    $chg
                );
            },
            static function () use ($servicesApi, $domain, $sourcePackageId): bool {
                return verifyDomainAbsentFromPackage(
                    $servicesApi,
                    $domain,
                    $sourcePackageId,
                    VERIFICATION_ATTEMPTS,
                    VERIFICATION_DELAY_SECONDS
                );
            }
        );

        if ($result['status'] === 'moved') {
            echo "MOVED\n";
            $successCount++;

            $remaining = [];

            foreach ($workingNames as $name) {
                if ($name !== $domain) {
                    $remaining[] = $name;
                }
            }

            $workingNames = $remaining;
            continue;
        }

        echo "ERROR: {$result['message']}\n";
        fwrite(STDERR, "Error: {$domain}: {$result['message']}\n");
        $failureCount++;
        $failedDomains[$domain] = $result['message'];

        if ($result['status'] === 'needs-manual-detach') {
            $manualDetachDomains[$domain] = $result['message'];
        }

        /*
         * The source name list is only updated on verified success, so a
         * conservative view of the source package is kept for later chg
         * survivor picks.
         */
    }

    echo "\nProcessing complete.\n";
    echo "  Successfully moved: {$successCount}\n";
    echo "  Failed: {$failureCount}\n";
    echo "  Skipped: " . count($skippedDomains) . "\n";

    if ($manualDetachDomains !== []) {
        echo "\nDomains needing manual detachment from the source package ("
            . count($manualDetachDomains) . "):\n";

        foreach ($manualDetachDomains as $domain => $message) {
            echo "  {$domain} -> {$message}\n";
        }
    }

    if ($failedDomains !== []) {
        echo "\nFailed domains:\n";

        foreach ($failedDomains as $domain => $message) {
            echo "  {$domain} -> {$message}\n";
        }
    }

    if ($snapshotWarnings !== []) {
        echo "\nZone snapshot warnings:\n";

        foreach ($snapshotWarnings as $domain => $message) {
            echo "  {$domain} -> {$message}\n";
        }
    }

    reportSkippedDomains($skippedDomains);

    exit($failureCount === 0 ? EXIT_SUCCESS : EXIT_PARTIAL_FAILURE);
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
