#!/usr/bin/env php
<?php
/**
 * Detach one or more domain names from an existing 20i hosting package.
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
use function SoftwareWrap\TwentyI\buildZoneSnapshotPayload;
use function SoftwareWrap\TwentyI\classifyDomainForDetach;
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
use function SoftwareWrap\TwentyI\verifyDomainAbsentFromPackage;
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
  {$script} [--dry-run] [--yes] [--skip] <package-domain> <domain>
  {$script} [--dry-run] [--yes] [--skip] <package-domain> < domains.txt

Options:
  --dry-run  Resolve the package, run every preflight guard, and show what
             would be done without modifying the package.
  --yes, -y  Skip the confirmation prompt for a batch of 10 or more domains,
             and proceed without a per-domain prompt when the best-effort
             zone snapshot cannot be written.
  --skip     Skip domains that are not detachable from the source package:
             domains not attached to any package and domains attached to a
             different package. Skipped domains are reported at the end.
  --help, -h Display this help text.

Examples:
  {$script} example.com retired-example.com
  {$script} --dry-run example.com < domains.txt
  {$script} --skip --yes example.com < domains.txt

The source package is identified by any domain name already attached to it.
Blank input lines and lines beginning with # are ignored.

Guards (enforced before any change):
  - A package must never be left without names. The guard is cumulative
    across the whole batch: if the requested removals would empty the
    package, the command aborts before the first change.
  - Removing the package's primary name (its first name) sends "chg" with
    the deterministic survivor: the first remaining name.

Detaching a domain destroys its 20i web-forwarding configuration. Before
each detach a best-effort snapshot of the domain's stored DNS zone is
written to the state directory (XDG_STATE_HOME or ~/.local/state, under
20i-cli/snapshots/). If the snapshot cannot be written, the domain is only
processed after an explicit confirmation (or with --yes).

Each eligible domain is submitted and verified separately so that progress
is reported continuously. Without --skip, the command terminates before
making changes if any requested domain is not detachable.

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
        "\nThis will detach and verify {$count} domains individually. Continue? [y/N] "
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

if (count($arguments) === 1) {
    $packageDomain = normalizeDomain($arguments[0]);
    $requestedDomains = readDomainsFromStdin();
} elseif (count($arguments) === 2) {
    $packageDomain = normalizeDomain($arguments[0]);
    $requestedDomains = [normalizeDomain($arguments[1])];
} else {
    usage(EXIT_ERROR);
}

if (!isValidDomain($packageDomain)) {
    fail("Invalid package domain '{$packageDomain}'.");
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
     * Fetch the package list once for preflight classification.
     */
    $packages = getPackages($servicesApi);
    $sourcePackage = findPackageByDomain($packages, $packageDomain);

    if ($sourcePackage === null) {
        fail("No package contains '{$packageDomain}'.");
    }

    $sourcePackageId = getPackageId($sourcePackage);
    $sourceNames = getPackageDomains($sourcePackage);

    $domainsToDetach = [];
    $skippedDomains = [];
    $ineligible = [];

    foreach ($requestedDomains as $domain) {
        $classification = classifyDomainForDetach(
            $packages,
            $domain,
            $sourcePackageId
        );

        if ($classification['status'] === 'on-source') {
            $domainsToDetach[] = $domain;
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

    echo "Package selector: {$packageDomain}\n";
    echo "Package ID: {$sourcePackageId}\n";
    echo "Names on package: " . count($sourceNames) . "\n";
    echo "Requested domains: " . count($requestedDomains) . "\n";
    echo "Domains to process: " . count($domainsToDetach) . "\n";

    /*
     * Fail fast before any change unless --skip was supplied.
     */
    if ($ineligible !== []) {
        fwrite(
            STDERR,
            "\nNot detachable from this package ("
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

    if ($domainsToDetach === []) {
        echo "\nNo domains need to be detached.\n";
        reportSkippedDomains($skippedDomains);
        exit(EXIT_SUCCESS);
    }

    /*
     * Cumulative last-name and primary guards, before any mutation.
     * planRemovalSequence() throws when the batch would empty the package.
     */
    try {
        $removalPlan = planRemovalSequence($sourceNames, $domainsToDetach);
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
                : " (primary name; new primary: {$step['chg']})";

            echo "[{$position}/{$total}] {$step['name']} ... WOULD DETACH{$chgNote}\n";
        }

        echo "\nDry run complete. No changes were made.\n";
        reportSkippedDomains($skippedDomains);
        exit(EXIT_SUCCESS);
    }

    if (
        count($domainsToDetach) >= CONFIRMATION_THRESHOLD
        && !$assumeYes
        && !confirmBatch(count($domainsToDetach))
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
    $workingNames = $sourceNames;
    $total = count($domainsToDetach);

    echo "\nProcessing domains:\n";

    foreach ($domainsToDetach as $offset => $domain) {
        $position = $offset + 1;

        /*
         * Print and flush before the network calls so the operator always
         * sees which domain is currently being processed.
         */
        echo "[{$position}/{$total}] {$domain} ... ";
        fflush(STDOUT);

        /*
         * Best-effort zone snapshot before the detach. A snapshot failure
         * never proceeds silently: the domain requires explicit
         * confirmation (or --yes).
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
                    . 'Detach it anyway? [y/N] '
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
                'no surviving name would remain; removal refused';
            continue;
        }

        try {
            removeNameFromPackage(
                $servicesApi,
                $sourcePackageId,
                $domain,
                $chg
            );

            $verified = verifyDomainAbsentFromPackage(
                $servicesApi,
                $domain,
                $sourcePackageId,
                VERIFICATION_ATTEMPTS,
                VERIFICATION_DELAY_SECONDS
            );

            if (!$verified) {
                throw new RuntimeException(
                    'API call completed, but the removal could not be verified.'
                );
            }

            echo "DETACHED\n";
            $successCount++;

            $remaining = [];

            foreach ($workingNames as $name) {
                if ($name !== $domain) {
                    $remaining[] = $name;
                }
            }

            $workingNames = $remaining;
        } catch (Throwable $domainException) {
            echo "ERROR: {$domainException->getMessage()}\n";
            fwrite(
                STDERR,
                "Error: {$domain}: "
                . sanitizeApiError($domainException) . "\n"
            );
            $failureCount++;
            $failedDomains[$domain] = $domainException->getMessage();
        }
    }

    echo "\nProcessing complete.\n";
    echo "  Successfully detached: {$successCount}\n";
    echo "  Failed: {$failureCount}\n";
    echo "  Skipped: " . count($skippedDomains) . "\n";

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
