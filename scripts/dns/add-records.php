#!/usr/bin/env php
<?php
/**
 * Add one TXT DNS record to one or more domains attached to 20i packages.
 *
 * Existing records and post-write verification are checked directly against
 * the authoritative StackDNS nameservers. DNS changes are submitted through
 * the 20i package DNS endpoint.
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
 * Created: July 12, 2026
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/cli.php';
require_once __DIR__ . '/../../lib/dns.php';
require_once __DIR__ . '/../../lib/package.php';

use function SoftwareWrap\TwentyI\Cli\confirm;
use function SoftwareWrap\TwentyI\Cli\fail;
use function SoftwareWrap\TwentyI\Cli\readLinesFromStdin;
use function SoftwareWrap\TwentyI\Dns\buildAddTxtRecordPayload;
use function SoftwareWrap\TwentyI\Dns\buildRecordFqdn;
use function SoftwareWrap\TwentyI\Dns\normalizeRecordNameForDomain;
use function SoftwareWrap\TwentyI\Dns\requireSupportedRecordType;
use function SoftwareWrap\TwentyI\Dns\requireValidRecordName;
use function SoftwareWrap\TwentyI\Dns\requireValidTxtValue;
use function SoftwareWrap\TwentyI\Dns\stackDnsTxtRecordExists;
use function SoftwareWrap\TwentyI\findPackageByDomain;
use function SoftwareWrap\TwentyI\getPackageDomains;
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
const RECENT_SUBMISSION_WINDOW_SECONDS = 3600;

/**
 * Display usage information.
 */
function usage(int $exitCode = EXIT_SUCCESS): void
{
    $script = basename($_SERVER['argv'][0]);
    $stream = $exitCode === EXIT_SUCCESS ? STDOUT : STDERR;

    fwrite($stream, <<<EOT
Usage:
  {$script} [--dry-run] [--yes] [--skip] [--force] <domain>
      --name <dns-name> --type TXT --value <string>

  {$script} [--dry-run] [--yes] [--skip] [--force]
      --name <dns-name> --type TXT --value <string> < domains.txt

  {$script} [--dry-run] [--yes] [--skip] [--force] --all <package-domain>
      --name <dns-name> --type TXT --value <string>

Options:
  --name <dns-name>
             DNS owner name. Use @ for the zone apex.
  --type TXT
             DNS record type. The initial implementation supports TXT only.
  --value <string>
             TXT record value.
  --all      Apply the record to every domain attached to the package
             identified by the positional <package-domain>.
  --dry-run  Resolve packages, inspect authoritative DNS, and show what would
             be done without changing DNS.
  --yes, -y  Skip the confirmation prompt for a batch of 10 or more domains.
  --skip     Skip domains on which the identical TXT record is already
             published in authoritative DNS. Without --skip, any published
             duplicate stops the run before changes begin.
  --force    Ignore the local recent-submission safeguard. This does not
             override a duplicate already visible in authoritative DNS.
  --help, -h Display this help text.

Examples:
  {$script} example.com \
      --name @ \
      --type TXT \
      --value "This domain is for sale"

  {$script} \
      --name @ \
      --type TXT \
      --value "This domain is for sale" \
      < domains.txt

  {$script} --all lowpricereseller.com \
      --name @ \
      --type TXT \
      --value "This domain is for sale"

A single positional domain processes one domain. With no positional domain,
domains are read from standard input. The --all option requires one positional
domain that identifies a package.

Each eligible domain is submitted separately so that progress is reported
continuously. Immediate authoritative verification is advisory because StackDNS
publication may take 30 minutes or longer. Successful API submissions are
recorded locally for 60 minutes to prevent accidental duplicate resubmission
during that publication interval.

EOT
    );

    exit($exitCode);
}

/**
 * Return the value following an option or terminate with a useful error.
 */
function requireOptionValue(
    string $option,
    int &$index,
    int $argc,
    array $argv
): string {
    $index++;

    if ($index >= $argc) {
        fail("Option '{$option}' requires a value.");
    }

    return $argv[$index];
}

/**
 * Read and normalize domains from standard input.
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
 * Validate and deduplicate domains while preserving input order.
 *
 * @param array<int,string> $domains
 * @return array<int,string>
 */
function validateDomains(array $domains): array
{
    $unique = [];

    foreach ($domains as $domain) {
        $domain = normalizeDomain($domain);

        if (!isValidDomain($domain)) {
            fail("Invalid domain '{$domain}'.");
        }

        $unique[$domain] = true;
    }

    return array_keys($unique);
}

/**
 * Ask the operator to confirm a large batch operation.
 */
function confirmBatch(int $count): bool
{
    return confirm(
        "\nThis will submit one DNS record for {$count} domains and attempt immediate authoritative verification. Continue? [y/N] "
    );
}

/**
 * Return the path used for the local DNS submission journal.
 */
function getSubmissionJournalPath(): string
{
    $stateHome = getenv('XDG_STATE_HOME');

    if (is_string($stateHome) && trim($stateHome) !== '') {
        return rtrim($stateHome, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . '20i-cli'
            . DIRECTORY_SEPARATOR . 'dns-submissions.json';
    }

    $home = getenv('HOME');

    if (is_string($home) && trim($home) !== '') {
        return rtrim($home, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . '.local'
            . DIRECTORY_SEPARATOR . 'state'
            . DIRECTORY_SEPARATOR . '20i-cli'
            . DIRECTORY_SEPARATOR . 'dns-submissions.json';
    }

    $appData = getenv('LOCALAPPDATA') ?: getenv('APPDATA');

    if (is_string($appData) && trim($appData) !== '') {
        return rtrim($appData, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . '20i-cli'
            . DIRECTORY_SEPARATOR . 'dns-submissions.json';
    }

    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . '20i-cli'
        . DIRECTORY_SEPARATOR . 'dns-submissions.json';
}

/**
 * Return the stable key used to identify an identical DNS submission.
 */
function buildSubmissionKey(
    string $packageId,
    string $domain,
    string $recordName,
    string $recordValue
): string {
    return hash(
        'sha256',
        implode("\n", [
            $packageId,
            normalizeDomain($domain),
            buildRecordFqdn($domain, $recordName),
            'TXT',
            $recordValue,
        ])
    );
}

/**
 * Read the local submission journal and discard expired entries.
 *
 * @return array<string,array<string,mixed>>
 */
function readSubmissionJournal(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Unable to read DNS submission journal '{$path}'.");
    }

    if (trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
        throw new RuntimeException("DNS submission journal '{$path}' is invalid.");
    }

    $cutoff = time() - RECENT_SUBMISSION_WINDOW_SECONDS;
    $active = [];

    foreach ($decoded as $key => $entry) {
        if (!is_string($key) || !is_array($entry)) {
            continue;
        }

        $submittedAt = $entry['submittedAt'] ?? null;

        if (is_int($submittedAt) && $submittedAt >= $cutoff) {
            $active[$key] = $entry;
        }
    }

    return $active;
}

/**
 * Return a recent matching submission, or null when none exists.
 *
 * @return array<string,mixed>|null
 */
function findRecentSubmission(
    array $journal,
    string $packageId,
    string $domain,
    string $recordName,
    string $recordValue
): ?array {
    $key = buildSubmissionKey(
        $packageId,
        $domain,
        $recordName,
        $recordValue
    );

    $entry = $journal[$key] ?? null;

    return is_array($entry) ? $entry : null;
}

/**
 * Persist a successful API submission atomically.
 */
function recordSubmission(
    string $path,
    array &$journal,
    string $packageId,
    string $domain,
    string $recordName,
    string $recordValue
): void {
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException(
            "Unable to create DNS submission journal directory '{$directory}'."
        );
    }

    $key = buildSubmissionKey(
        $packageId,
        $domain,
        $recordName,
        $recordValue
    );

    $journal[$key] = [
        'packageId' => $packageId,
        'domain' => normalizeDomain($domain),
        'fqdn' => buildRecordFqdn($domain, $recordName),
        'type' => 'TXT',
        'value' => $recordValue,
        'submittedAt' => time(),
        'submittedAtIso8601' => gmdate('c'),
    ];

    $temporaryPath = $path . '.tmp-' . getmypid();
    $json = json_encode(
        $journal,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";

    if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
        throw new RuntimeException(
            "Unable to write DNS submission journal '{$temporaryPath}'."
        );
    }

    @chmod($temporaryPath, 0600);

    if (!rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException(
            "Unable to replace DNS submission journal '{$path}'."
        );
    }
}

/**
 * Format the age of a recent submission for operator output.
 */
function formatSubmissionAge(int $submittedAt): string
{
    $seconds = max(0, time() - $submittedAt);

    if ($seconds < 60) {
        return $seconds . ' second' . ($seconds === 1 ? '' : 's');
    }

    $minutes = intdiv($seconds, 60);

    return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
}

/**
 * Report duplicate records skipped during preflight.
 *
 * @param array<int,string> $skippedDomains
 */
function reportSkippedDomains(array $skippedDomains): void
{
    if ($skippedDomains === []) {
        return;
    }

    echo "\nSkipped existing records (" . count($skippedDomains) . "):\n";

    foreach ($skippedDomains as $domain) {
        echo "  {$domain}\n";
    }
}

/*
 * Parse options and positional arguments.
 */
$dryRun = false;
$assumeYes = false;
$skipExisting = false;
$forceRecentSubmission = false;
$allDomains = false;

$recordName = null;
$recordType = null;
$recordValue = null;
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

    if ($argument === '--force') {
        $forceRecentSubmission = true;
        continue;
    }

    if ($argument === '--all') {
        $allDomains = true;
        continue;
    }

    if ($argument === '--name') {
        $recordName = requireOptionValue('--name', $index, $argc, $argv);
        continue;
    }

    if ($argument === '--type') {
        $recordType = requireOptionValue('--type', $index, $argc, $argv);
        continue;
    }

    if ($argument === '--value') {
        $recordValue = requireOptionValue('--value', $index, $argc, $argv);
        continue;
    }

    if (strpos($argument, '-') === 0) {
        fail("Unknown option '{$argument}'.");
    }

    $arguments[] = $argument;
}

if ($recordName === null) {
    fail('The --name option is required.');
}

if ($recordType === null) {
    fail('The --type option is required.');
}

if ($recordValue === null) {
    fail('The --value option is required.');
}

try {
    $recordType = requireSupportedRecordType($recordType);
    $recordValue = requireValidTxtValue($recordValue);

    if (strpos($recordName, '*') !== false) {
        throw new InvalidArgumentException(
            'Wildcard DNS owner names are not currently supported.'
        );
    }
} catch (Throwable $exception) {
    fail($exception->getMessage());
}

if ($allDomains) {
    if (count($arguments) !== 1) {
        fail('The --all option requires exactly one positional package domain.');
    }

    $selectorDomain = normalizeDomain($arguments[0]);

    if (!isValidDomain($selectorDomain)) {
        fail("Invalid package domain '{$selectorDomain}'.");
    }

    $requestedDomains = [];
} elseif (count($arguments) === 1) {
    $selectorDomain = null;
    $requestedDomains = validateDomains([$arguments[0]]);
} elseif (count($arguments) === 0) {
    $selectorDomain = null;
    $requestedDomains = validateDomains(readDomainsFromStdin());

    if ($requestedDomains === []) {
        fail('No domains were provided.');
    }
} else {
    usage(EXIT_ERROR);
}

try {
    $servicesApi = new \TwentyI\API\Services($api_key);
    $packages = getPackages($servicesApi);
    $targets = [];

    if ($allDomains) {
        $package = findPackageByDomain($packages, $selectorDomain);

        if ($package === null) {
            fail("No package contains '{$selectorDomain}'.");
        }

        $packageId = getPackageId($package);
        $packageSelector = getPackageSelector($package);
        $packageDomains = getPackageDomains($package);

        if ($packageDomains === []) {
            fail("Package '{$packageId}' does not contain any usable domains.");
        }

        foreach ($packageDomains as $domain) {
            $targets[] = [
                'domain' => $domain,
                'packageId' => $packageId,
                'packageSelector' => $packageSelector,
                'recordName' => normalizeRecordNameForDomain(
                    $domain,
                    $recordName
                ),
            ];
        }
    } else {
        foreach ($requestedDomains as $domain) {
            $package = findPackageByDomain($packages, $domain);

            $targets[] = [
                'domain' => $domain,
                'packageId' => $package === null ? null : getPackageId($package),
                'packageSelector' => $package === null
                    ? null
                    : getPackageSelector($package),
                'recordName' => normalizeRecordNameForDomain(
                    $domain,
                    $recordName
                ),
            ];
        }
    }

    echo "DNS record:\n";
    echo "  Name:  {$recordName}\n";
    echo "  Type:  {$recordType}\n";
    echo "  Value: {$recordValue}\n";
    echo "\nRequested mode: "
        . ($allDomains ? 'all domains in package' : 'explicit domains')
        . "\n";
    echo "Skip published identical records: "
        . ($skipExisting ? 'yes' : 'no') . "\n";
    echo "Ignore recent-submission safeguard: "
        . ($forceRecentSubmission ? 'yes' : 'no') . "\n";
    echo "Domains resolved: " . count($targets) . "\n";

    $journalPath = getSubmissionJournalPath();
    $submissionJournal = readSubmissionJournal($journalPath);

    $eligibleTargets = [];
    $duplicateDomains = [];
    $recentSubmissionDomains = [];
    $unresolvedDomains = [];
    $inspectionFailures = [];
    $totalTargets = count($targets);

    echo "\nPreflight inspection:\n";

    foreach ($targets as $offset => $target) {
        $position = $offset + 1;
        $domain = $target['domain'];

        echo "[{$position}/{$totalTargets}] {$domain} ... ";
        fflush(STDOUT);

        if ($target['packageId'] === null) {
            echo "ERROR: not attached to any visible package\n";
            $unresolvedDomains[$domain] = 'not attached to any visible package';
            continue;
        }

        try {
            $targetRecordName = $target['recordName'];

            if (
                stackDnsTxtRecordExists(
                    $domain,
                    $targetRecordName,
                    $recordValue
                )
            ) {
                echo "EXISTS\n";
                $duplicateDomains[] = $domain;
                continue;
            }

            $recentSubmission = findRecentSubmission(
                $submissionJournal,
                (string) $target['packageId'],
                $domain,
                $targetRecordName,
                $recordValue
            );

            if (
                $recentSubmission !== null
                && !$forceRecentSubmission
            ) {
                $age = formatSubmissionAge(
                    (int) ($recentSubmission['submittedAt'] ?? time())
                );
                echo "RECENTLY SUBMITTED ({$age} ago)\n";
                $recentSubmissionDomains[$domain] = $recentSubmission;
                continue;
            }

            echo "READY\n";
            $eligibleTargets[] = $target;
        } catch (Throwable $inspectionException) {
            echo "ERROR: {$inspectionException->getMessage()}\n";
            $inspectionFailures[$domain] = $inspectionException->getMessage();
        }
    }

    echo "\nPreflight complete.\n";
    echo "  Ready to add: " . count($eligibleTargets) . "\n";
    echo "  Published existing: " . count($duplicateDomains) . "\n";
    echo "  Recently submitted: " . count($recentSubmissionDomains) . "\n";
    echo "  Unresolved: " . count($unresolvedDomains) . "\n";
    echo "  Inspection failures: " . count($inspectionFailures) . "\n";

    if ($duplicateDomains !== [] && !$skipExisting) {
        fwrite(
            STDERR,
            "\nIdentical TXT records already exist ("
            . count($duplicateDomains)
            . "):\n"
        );

        foreach ($duplicateDomains as $domain) {
            fwrite(STDERR, "  {$domain}\n");
        }

        fail(
            'No changes were made because at least one identical record '
            . 'already exists. Rerun with --skip to ignore existing records.'
        );
    }

    if ($eligibleTargets === []) {
        echo "\nNo DNS records need to be added.\n";
        reportSkippedDomains($duplicateDomains);

        if ($recentSubmissionDomains !== []) {
            echo "\nProtected recent submissions ("
                . count($recentSubmissionDomains)
                . "):\n";
            foreach ($recentSubmissionDomains as $domain => $entry) {
                $age = formatSubmissionAge((int) ($entry['submittedAt'] ?? time()));
                echo "  {$domain} -> accepted {$age} ago; publication pending\n";
            }
        }

        exit(
            $unresolvedDomains === [] && $inspectionFailures === []
                ? EXIT_SUCCESS
                : EXIT_PARTIAL_FAILURE
        );
    }

    if ($dryRun) {
        echo "\nDry-run results:\n";
        $total = count($eligibleTargets);

        foreach ($eligibleTargets as $offset => $target) {
            $position = $offset + 1;
            echo "[{$position}/{$total}] {$target['domain']} ... WOULD ADD\n";
        }

        echo "\nDry run complete. No DNS changes were made.\n";
        reportSkippedDomains($duplicateDomains);

        if ($recentSubmissionDomains !== []) {
            echo "\nProtected recent submissions ("
                . count($recentSubmissionDomains)
                . "):\n";
            foreach ($recentSubmissionDomains as $domain => $entry) {
                $age = formatSubmissionAge((int) ($entry['submittedAt'] ?? time()));
                echo "  {$domain} -> accepted {$age} ago; publication pending\n";
            }
        }

        exit(
            $unresolvedDomains === [] && $inspectionFailures === []
                ? EXIT_SUCCESS
                : EXIT_PARTIAL_FAILURE
        );
    }

    if (
        count($eligibleTargets) >= CONFIRMATION_THRESHOLD
        && !$assumeYes
        && !confirmBatch(count($eligibleTargets))
    ) {
        fwrite(STDERR, "\nOperation cancelled. No changes were made.\n");
        exit(EXIT_CANCELLED);
    }

    $acceptedCount = 0;
    $verifiedCount = 0;
    $pendingDomains = [];
    $verificationWarnings = [];
    $failedDomains = [];
    $journalWarnings = [];
    $total = count($eligibleTargets);

    echo "\nProcessing domains:\n";

    foreach ($eligibleTargets as $offset => $target) {
        $position = $offset + 1;
        $domain = $target['domain'];
        $packageId = (string) $target['packageId'];
        $targetRecordName = $target['recordName'];
        $payload = buildAddTxtRecordPayload(
            $targetRecordName,
            $recordValue
        );

        echo "[{$position}/{$total}] {$domain} ... ";
        fflush(STDOUT);

        try {
            $servicesApi->postWithFields(
                '/package/' . rawurlencode($packageId)
                . '/dns/' . rawurlencode($domain),
                $payload
            );
        } catch (Throwable $domainException) {
            echo "ERROR: {$domainException->getMessage()}\n";
            $failedDomains[$domain] = $domainException->getMessage();
            continue;
        }

        $acceptedCount++;

        try {
            recordSubmission(
                $journalPath,
                $submissionJournal,
                $packageId,
                $domain,
                $targetRecordName,
                $recordValue
            );
        } catch (Throwable $journalException) {
            $journalWarnings[$domain] = $journalException->getMessage();
        }

        try {
            $verified = stackDnsTxtRecordExists(
                $domain,
                $targetRecordName,
                $recordValue
            );
        } catch (Throwable $verificationException) {
            $verified = false;
            $verificationWarnings[$domain] =
                $verificationException->getMessage();
        }

        if ($verified) {
            echo "ACCEPTED; VERIFIED\n";
            $verifiedCount++;
        } else {
            echo "ACCEPTED; PUBLICATION PENDING\n";
            $pendingDomains[] = $domain;
        }
    }

    $failureCount = count($failedDomains)
        + count($unresolvedDomains)
        + count($inspectionFailures);

    echo "\nProcessing complete.\n";
    echo "  API accepted: {$acceptedCount}\n";
    echo "  Published existing skipped: " . count($duplicateDomains) . "\n";
    echo "  Recent submissions protected: "
        . count($recentSubmissionDomains) . "\n";
    echo "  API/resolution failures: {$failureCount}\n";

    echo "\nVerification status:\n";
    echo "  Verified immediately: {$verifiedCount}\n";
    echo "  Pending publication: " . count($pendingDomains) . "\n";

    $allFailures = $unresolvedDomains + $inspectionFailures + $failedDomains;

    if ($allFailures !== []) {
        echo "\nFailed domains:\n";

        foreach ($allFailures as $domain => $message) {
            echo "  {$domain} -> {$message}\n";
        }
    }

    if ($pendingDomains !== []) {
        echo "\nPending authoritative publication ("
            . count($pendingDomains) . "):\n";

        foreach ($pendingDomains as $domain) {
            echo "  {$domain}\n";
        }

        echo "\nThe 20i API accepted all pending records. "
            . "StackDNS publication may take 30 minutes or longer.\n";
        echo "Do not resubmit these records during that interval.\n";
    }

    if ($verificationWarnings !== []) {
        echo "\nAuthoritative verification warnings:\n";

        foreach ($verificationWarnings as $domain => $message) {
            echo "  {$domain} -> {$message}\n";
        }
    }

    if ($journalWarnings !== []) {
        echo "\nLocal submission journal warnings:\n";

        foreach ($journalWarnings as $domain => $message) {
            echo "  {$domain} -> {$message}\n";
        }

        echo "  Do not rerun these submissions until authoritative DNS shows them.\n";
    }

    reportSkippedDomains($duplicateDomains);

    if ($recentSubmissionDomains !== []) {
        echo "\nProtected recent submissions ("
            . count($recentSubmissionDomains)
            . "):\n";

        foreach ($recentSubmissionDomains as $domain => $entry) {
            $age = formatSubmissionAge((int) ($entry['submittedAt'] ?? time()));
            echo "  {$domain} -> accepted {$age} ago; publication pending\n";
        }
    }


    exit($failureCount === 0 ? EXIT_SUCCESS : EXIT_PARTIAL_FAILURE);
} catch (Throwable $exception) {
    fail($exception->getMessage());
}