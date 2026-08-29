#!/usr/bin/env php
<?php
/**
 * Add one or more domain names to an existing 20i hosting package.
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

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/cli.php';
require_once __DIR__ . '/../../lib/package.php';

use function SoftwareWrap\TwentyI\Cli\confirm;
use function SoftwareWrap\TwentyI\Cli\fail;
use function SoftwareWrap\TwentyI\Cli\readLinesFromStdin;
use function SoftwareWrap\TwentyI\addNamesToPackage;
use function SoftwareWrap\TwentyI\findPackageByDomain;
use function SoftwareWrap\TwentyI\getPackageId;
use function SoftwareWrap\TwentyI\getPackageSelector;
use function SoftwareWrap\TwentyI\getPackages;
use function SoftwareWrap\TwentyI\isValidDomain;
use function SoftwareWrap\TwentyI\normalizeDomain;

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
  {$script} [--dry-run] [--yes] [--skip] <package-domain> <new-domain>
  {$script} [--dry-run] [--yes] [--skip] <package-domain> < domains.txt

Options:
  --dry-run  Resolve the package and show what would be done without
             modifying the package.
  --yes, -y  Skip the confirmation prompt for a batch of 10 or more domains.
  --skip     Skip domains already attached to any package. Skipped domains
             are reported together at the end.
  --help, -h Display this help text.

Examples:
  {$script} example.com additional-example.com
  {$script} --dry-run example.com < domains.txt
  {$script} --skip --yes example.com < domains.txt

The target package is identified by any domain name already attached to it.
Blank input lines and lines beginning with # are ignored.

Each eligible domain is submitted and verified separately so that progress is
reported continuously. Without --skip, the command terminates before making
changes if any requested domain is attached to a different package.

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
        "\nThis will add and verify {$count} domains individually. Continue? [y/N] "
    );
}

/**
 * Report domains skipped because they were already attached.
 *
 * This is intentionally called only after all other summary output so that
 * the skipped-domain list is the final output from a completed run.
 *
 * @param array<string,array{id:string,selector:string,target:bool}> $skippedDomains
 */
function reportSkippedDomains(array $skippedDomains): void
{
    if ($skippedDomains === []) {
        return;
    }

    echo "\nSkipped existing domains (" . count($skippedDomains) . "):\n";

    foreach ($skippedDomains as $domain => $packageInfo) {
        $location = $packageInfo['target']
            ? 'target package'
            : "package {$packageInfo['id']} ({$packageInfo['selector']})";

        echo "  {$domain} -> {$location}\n";
    }
}

/**
 * Verify that a domain is attached to the expected package.
 */
function verifyDomainOnPackage(
    \TwentyI\API\Services $servicesApi,
    string $domain,
    string $targetPackageId
): bool {
    for ($attempt = 1; $attempt <= VERIFICATION_ATTEMPTS; $attempt++) {
        $packages = getPackages($servicesApi);
        $package = findPackageByDomain($packages, $domain);

        if ($package !== null && getPackageId($package) === $targetPackageId) {
            return true;
        }

        if ($attempt < VERIFICATION_ATTEMPTS) {
            sleep(VERIFICATION_DELAY_SECONDS);
        }
    }

    return false;
}

/*
 * Parse command-line options and positional arguments.
 */
$dryRun = false;
$assumeYes = false;
$skipExisting = false;
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
        $skipExisting = true;
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

try {
    $servicesApi = new \TwentyI\API\Services($api_key);

    /*
     * Fetch the package list once for preflight classification.
     */
    $packages = getPackages($servicesApi);
    $targetPackage = findPackageByDomain($packages, $packageDomain);

    if ($targetPackage === null) {
        fail("No package contains '{$packageDomain}'.");
    }

    $targetPackageId = getPackageId($targetPackage);
    $domainsToAdd = [];
    $skippedDomains = [];
    $attachedElsewhere = [];

    foreach ($requestedDomains as $domain) {
        $currentPackage = findPackageByDomain($packages, $domain);

        if ($currentPackage === null) {
            $domainsToAdd[] = $domain;
            continue;
        }

        $currentPackageId = getPackageId($currentPackage);
        $packageInfo = [
            'id' => $currentPackageId,
            'selector' => getPackageSelector($currentPackage),
            'target' => $currentPackageId === $targetPackageId,
        ];

        $skippedDomains[$domain] = $packageInfo;

        if (!$packageInfo['target']) {
            $attachedElsewhere[$domain] = $packageInfo;
        }
    }

    echo "Package selector: {$packageDomain}\n";
    echo "Package ID: {$targetPackageId}\n";
    echo "Requested domains: " . count($requestedDomains) . "\n";
    echo "Domains to process: " . count($domainsToAdd) . "\n";

    /*
     * Preserve the existing fail-fast behavior unless --skip was supplied.
     */
    if ($attachedElsewhere !== [] && !$skipExisting) {
        fwrite(
            STDERR,
            "\nAlready attached to other packages ("
            . count($attachedElsewhere)
            . "):\n"
        );

        foreach ($attachedElsewhere as $domain => $packageInfo) {
            fwrite(
                STDERR,
                "  {$domain} -> package {$packageInfo['id']}"
                . " ({$packageInfo['selector']})\n"
            );
        }

        fail(
            'No changes were made because at least one requested domain '
            . 'is attached to another package. Rerun with --skip to ignore '
            . 'existing domains.'
        );
    }

    if ($domainsToAdd === []) {
        echo "\nNo domains need to be added.\n";
        reportSkippedDomains($skippedDomains);
        exit(EXIT_SUCCESS);
    }

    if ($dryRun) {
        echo "\nDry-run results:\n";

        $total = count($domainsToAdd);

        foreach ($domainsToAdd as $offset => $domain) {
            $position = $offset + 1;
            echo "[{$position}/{$total}] {$domain} ... WOULD ADD\n";
        }

        echo "\nDry run complete. No changes were made.\n";
        reportSkippedDomains($skippedDomains);
        exit(EXIT_SUCCESS);
    }

    if (
        count($domainsToAdd) >= CONFIRMATION_THRESHOLD
        && !$assumeYes
        && !confirmBatch(count($domainsToAdd))
    ) {
        fwrite(STDERR, "\nOperation cancelled. No changes were made.\n");
        exit(EXIT_CANCELLED);
    }

    $successCount = 0;
    $failureCount = 0;
    $failedDomains = [];
    $total = count($domainsToAdd);

    echo "\nProcessing domains:\n";

    foreach ($domainsToAdd as $offset => $domain) {
        $position = $offset + 1;

        /*
         * Print and flush before the network calls so the operator always
         * sees which domain is currently being processed.
         */
        echo "[{$position}/{$total}] {$domain} ... ";
        fflush(STDOUT);

        try {
            addNamesToPackage($servicesApi, $targetPackageId, [$domain]);

            if (!verifyDomainOnPackage($servicesApi, $domain, $targetPackageId)) {
                throw new RuntimeException(
                    'API call completed, but package membership could not be verified.'
                );
            }

            echo "SUCCESS\n";
            $successCount++;
        } catch (Throwable $domainException) {
            echo "ERROR: {$domainException->getMessage()}\n";
            $failureCount++;
            $failedDomains[$domain] = $domainException->getMessage();
        }
    }

    echo "\nProcessing complete.\n";
    echo "  Successfully added: {$successCount}\n";
    echo "  Failed: {$failureCount}\n";
    echo "  Skipped: " . count($skippedDomains) . "\n";

    if ($failedDomains !== []) {
        echo "\nFailed domains:\n";

        foreach ($failedDomains as $domain => $message) {
            echo "  {$domain} -> {$message}\n";
        }
    }

    /*
     * Keep the existing-domain list as the final emitted output, as requested.
     */
    reportSkippedDomains($skippedDomains);

    exit($failureCount === 0 ? EXIT_SUCCESS : EXIT_PARTIAL_FAILURE);
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
