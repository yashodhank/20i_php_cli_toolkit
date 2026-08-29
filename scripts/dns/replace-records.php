#!/usr/bin/env php
<?php
/**
 * Atomically replace one TXT DNS record on one or more domains attached
 * to 20i packages.
 *
 * The record whose owner and old value match exactly one stored-zone TXT
 * record is replaced in a single DNS diff POST that carries both the new
 * record and the deletion of the matched ref, so the zone never holds
 * zero or two copies between calls. Zero or multiple matches fail that
 * domain. Post-change authoritative verification is advisory because
 * StackDNS publication may take 30 minutes or longer.
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
 * Only .env-free libraries are loaded before argument parsing so that
 * --help (and usage errors) work without any API key configured.
 * lib/bootstrap.php is required only once a real run begins.
 */
require_once __DIR__ . '/../../lib/cli.php';
require_once __DIR__ . '/../../lib/package.php';
require_once __DIR__ . '/../../lib/dns.php';
require_once __DIR__ . '/../../lib/zone-records.php';

use function SoftwareWrap\TwentyI\Cli\confirm;
use function SoftwareWrap\TwentyI\Cli\fail;
use function SoftwareWrap\TwentyI\Cli\readLinesFromStdin;
use function SoftwareWrap\TwentyI\Cli\sanitizeApiError;
use function SoftwareWrap\TwentyI\Dns\buildRecordFqdn;
use function SoftwareWrap\TwentyI\Dns\buildReplacePayload;
use function SoftwareWrap\TwentyI\Dns\buildSnapshotFilename;
use function SoftwareWrap\TwentyI\Dns\extractRecordRef;
use function SoftwareWrap\TwentyI\Dns\findMatchingApiRecords;
use function SoftwareWrap\TwentyI\Dns\findZoneForDomain;
use function SoftwareWrap\TwentyI\Dns\formatMutationAge;
use function SoftwareWrap\TwentyI\Dns\getApiRecordsForDomain;
use function SoftwareWrap\TwentyI\Dns\getMutationJournalPath;
use function SoftwareWrap\TwentyI\Dns\getPackageZoneMap;
use function SoftwareWrap\TwentyI\Dns\getSnapshotDirectory;
use function SoftwareWrap\TwentyI\Dns\normalizeRecordNameForDomain;
use function SoftwareWrap\TwentyI\Dns\readMutationJournal;
use function SoftwareWrap\TwentyI\Dns\requireExactlyOneMatch;
use function SoftwareWrap\TwentyI\Dns\requireSupportedRecordType;
use function SoftwareWrap\TwentyI\Dns\requireValidTxtValue;
use function SoftwareWrap\TwentyI\Dns\saveMutationJournalEntry;
use function SoftwareWrap\TwentyI\Dns\stackDnsTxtRecordExists;
use function SoftwareWrap\TwentyI\Dns\txtValuesEqual;
use function SoftwareWrap\TwentyI\Dns\writeSnapshotFile;
use function SoftwareWrap\TwentyI\findPackageByDomain;
use function SoftwareWrap\TwentyI\getPackageId;
use function SoftwareWrap\TwentyI\getPackages;
use function SoftwareWrap\TwentyI\isValidDomain;
use function SoftwareWrap\TwentyI\normalizeDomain;

use const SoftwareWrap\TwentyI\Cli\EXIT_CANCELLED;
use const SoftwareWrap\TwentyI\Cli\EXIT_ERROR;
use const SoftwareWrap\TwentyI\Cli\EXIT_PARTIAL_FAILURE;
use const SoftwareWrap\TwentyI\Cli\EXIT_SUCCESS;

const CONFIRMATION_THRESHOLD = 10;
const RECENT_SUBMISSION_WINDOW_SECONDS = 3600;
const REPLACEMENT_JOURNAL_BASENAME = 'dns-replacements.json';

/**
 * Display usage information.
 */
function usage(int $exitCode = EXIT_SUCCESS): void
{
    $script = basename($_SERVER['argv'][0]);
    $stream = $exitCode === EXIT_SUCCESS ? STDOUT : STDERR;

    fwrite($stream, <<<EOT
Usage:
  {$script} [--dry-run] [--yes] [--force] <domain>
      --name <dns-name> --type TXT --old-value <string> --new-value <string>

  {$script} [--dry-run] [--yes] [--force]
      --name <dns-name> --type TXT --old-value <string> --new-value <string> \
      < domains.txt

Options:
  --name <dns-name>
             DNS owner name. Use @ for the zone apex.
  --type TXT
             DNS record type. Replace supports TXT only in this version.
  --old-value <string>
             The exact current TXT value to replace. Exactly one stored
             TXT record must match this owner and value; zero or multiple
             matches fail that domain without changing anything.
  --new-value <string>
             The TXT value that replaces the old one.
  --dry-run  Resolve packages, read stored zones, match records, and show
             what would be replaced without changing DNS.
  --yes, -y  Skip the confirmation prompt for a batch of 10 or more domains.
  --force    Ignore the local recent-replacement safeguard. It does not
             bypass matching, snapshots, or the exactly-one-match rule.
  --help, -h Display this help text.

Examples:
  {$script} example.com --name @ --type TXT \
      --old-value "old verification" --new-value "new verification"

  {$script} --name _acme --type TXT \
      --old-value "token-1" --new-value "token-2" < domains.txt

A single positional domain processes one domain. With no positional domain,
domains are read from standard input.

The replacement is one atomic DNS diff POST per domain carrying both the
new TXT record and the deletion of the matched record's ref. Before any
change, the full stored zone of each mutated domain is written as JSON
Lines (including raw record fields and refs) to the local state directory
under 20i-cli/snapshots/; a snapshot failure aborts that domain's
mutation. Immediate authoritative verification is advisory because
StackDNS publication may take 30 minutes or longer; ACCEPTED with
PUBLICATION PENDING is a success. Accepted replacements are recorded
locally for 60 minutes to prevent accidental duplicate resubmission.

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
 * Return the stable key identifying one replacement request.
 */
function buildReplacementKey(
    string $packageId,
    string $domain,
    string $recordName,
    string $oldValue,
    string $newValue
): string {
    return hash(
        'sha256',
        implode("\n", [
            'replace',
            $packageId,
            normalizeDomain($domain),
            buildRecordFqdn($domain, $recordName),
            'TXT',
            $oldValue,
            $newValue,
        ])
    );
}

/*
 * Parse options and positional arguments. --help must work without any
 * API key, so nothing here touches the bootstrap.
 */
$dryRun = false;
$assumeYes = false;
$forceRecentReplacement = false;

$recordName = null;
$recordType = null;
$oldValue = null;
$newValue = null;
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

    if ($argument === '--force') {
        $forceRecentReplacement = true;
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

    if ($argument === '--old-value') {
        $oldValue = requireOptionValue('--old-value', $index, $argc, $argv);
        continue;
    }

    if ($argument === '--new-value') {
        $newValue = requireOptionValue('--new-value', $index, $argc, $argv);
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

if ($oldValue === null) {
    fail('The --old-value option is required.');
}

if ($newValue === null) {
    fail('The --new-value option is required.');
}

try {
    $recordType = requireSupportedRecordType($recordType);
    $oldValue = requireValidTxtValue($oldValue);
    $newValue = requireValidTxtValue($newValue);

    if (txtValuesEqual($oldValue, $newValue)) {
        throw new InvalidArgumentException(
            'The --old-value and --new-value are identical; nothing to replace.'
        );
    }

    if (strpos($recordName, '*') !== false) {
        throw new InvalidArgumentException(
            'Wildcard DNS owner names are not currently supported.'
        );
    }
} catch (Throwable $exception) {
    fail($exception->getMessage());
}

if (count($arguments) === 1) {
    $requestedDomains = validateDomains([$arguments[0]]);
} elseif (count($arguments) === 0) {
    $requestedDomains = validateDomains(readLinesFromStdin());

    if ($requestedDomains === []) {
        fail('No domains were provided.');
    }
} else {
    usage(EXIT_ERROR);
}

/*
 * Arguments are valid; a real run begins. Load the API key.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';

try {
    $servicesApi = new \TwentyI\API\Services($api_key);
    $packages = getPackages($servicesApi);
    $targets = [];

    foreach ($requestedDomains as $domain) {
        $package = findPackageByDomain($packages, $domain);

        $targets[] = [
            'domain' => $domain,
            'packageId' => $package === null ? null : getPackageId($package),
        ];
    }

    echo "TXT record replacement:\n";
    echo "  Name:      {$recordName}\n";
    echo "  Type:      {$recordType}\n";
    echo "  Old value: {$oldValue}\n";
    echo "  New value: {$newValue}\n";
    echo "\nDomains resolved: " . count($targets) . "\n";
    echo 'Ignore recent-replacement safeguard: '
        . ($forceRecentReplacement ? 'yes' : 'no') . "\n";

    $journalPath = getMutationJournalPath(REPLACEMENT_JOURNAL_BASENAME);
    $replacementJournal = readMutationJournal(
        $journalPath,
        RECENT_SUBMISSION_WINDOW_SECONDS
    );

    $eligibleTargets = [];
    $recentReplacementDomains = [];
    $preflightFailures = [];
    $zoneCache = [];
    $totalTargets = count($targets);

    echo "\nPreflight inspection:\n";

    foreach ($targets as $offset => $target) {
        $position = $offset + 1;
        $domain = $target['domain'];

        echo "[{$position}/{$totalTargets}] {$domain} ... ";
        fflush(STDOUT);

        if ($target['packageId'] === null) {
            echo "ERROR: not attached to any visible package\n";
            $preflightFailures[$domain] = 'not attached to any visible package';
            continue;
        }

        $packageId = (string) $target['packageId'];

        try {
            $targetRecordName = normalizeRecordNameForDomain(
                $domain,
                $recordName
            );

            $zoneMap = getPackageZoneMap($servicesApi, $packageId, $zoneCache);
            $zoneKey = findZoneForDomain($zoneMap, $domain);

            if ($zoneKey === null) {
                throw new RuntimeException(
                    "No DNS zone covers '{$domain}' on package {$packageId}."
                );
            }

            $zoneApex = normalizeDomain($zoneKey);
            $zoneRecords = getApiRecordsForDomain($zoneMap, $zoneApex, []);

            $matches = findMatchingApiRecords(
                $zoneRecords,
                $domain,
                $recordName,
                'TXT',
                $oldValue
            );

            $match = requireExactlyOneMatch(
                $matches,
                "TXT owner '"
                . buildRecordFqdn($domain, $targetRecordName)
                . "' with the old value"
            );

            $ref = extractRecordRef($match);

            if ($ref === null) {
                throw new RuntimeException(
                    'the matched record carries no ref and cannot be replaced'
                );
            }

            if (!$forceRecentReplacement) {
                $key = buildReplacementKey(
                    $packageId,
                    $domain,
                    $targetRecordName,
                    $oldValue,
                    $newValue
                );

                if (isset($replacementJournal[$key])) {
                    $entry = $replacementJournal[$key];
                    $submittedAt = isset($entry['submittedAt'])
                        ? (int) $entry['submittedAt']
                        : time();
                    $age = formatMutationAge($submittedAt);
                    echo "RECENTLY REPLACED ({$age} ago)\n";
                    $recentReplacementDomains[$domain] = $entry;
                    continue;
                }
            }

            echo "READY (ref {$ref})\n";

            $eligibleTargets[] = [
                'domain' => $domain,
                'packageId' => $packageId,
                'recordName' => $targetRecordName,
                'zoneApex' => $zoneApex,
                'zoneRecords' => $zoneRecords,
                'ref' => $ref,
            ];
        } catch (Throwable $inspectionException) {
            echo 'ERROR: ' . $inspectionException->getMessage() . "\n";
            fwrite(
                STDERR,
                'Error: ' . $domain . ': '
                . sanitizeApiError($inspectionException) . "\n"
            );
            $preflightFailures[$domain] = $inspectionException->getMessage();
        }
    }

    echo "\nPreflight complete.\n";
    echo '  Ready to replace: ' . count($eligibleTargets) . "\n";
    echo '  Recently replaced: ' . count($recentReplacementDomains) . "\n";
    echo '  Preflight failures: ' . count($preflightFailures) . "\n";

    if ($eligibleTargets === []) {
        echo "\nNo TXT records need to be replaced.\n";

        exit(
            $preflightFailures === []
                ? EXIT_SUCCESS
                : EXIT_PARTIAL_FAILURE
        );
    }

    if ($dryRun) {
        echo "\nDry-run results:\n";
        $total = count($eligibleTargets);

        foreach ($eligibleTargets as $offset => $target) {
            $position = $offset + 1;
            echo "[{$position}/{$total}] {$target['domain']} ... "
                . "WOULD REPLACE 1 record (ref {$target['ref']})\n";
        }

        echo "\nDry run complete. No DNS changes were made.\n";

        exit(
            $preflightFailures === []
                ? EXIT_SUCCESS
                : EXIT_PARTIAL_FAILURE
        );
    }

    if (
        count($eligibleTargets) >= CONFIRMATION_THRESHOLD
        && !$assumeYes
        && !confirm(
            "\nThis will atomically replace one TXT record on "
            . count($eligibleTargets)
            . " domains after snapshotting each zone locally. Continue? [y/N] "
        )
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
    $snapshotPaths = [];
    $snapshotDirectory = getSnapshotDirectory();
    $total = count($eligibleTargets);

    echo "\nProcessing domains:\n";

    foreach ($eligibleTargets as $offset => $target) {
        $position = $offset + 1;
        $domain = $target['domain'];
        $packageId = $target['packageId'];

        echo "[{$position}/{$total}] {$domain} ... ";
        fflush(STDOUT);

        /*
         * Mandatory pre-change snapshot: the full stored zone, shaped
         * like a dump-records row (raw fields and refs included). Any
         * snapshot failure aborts this domain's mutation.
         */
        try {
            $snapshotPath = $snapshotDirectory
                . DIRECTORY_SEPARATOR
                . buildSnapshotFilename($domain);

            writeSnapshotFile($snapshotPath, [[
                'domain' => $domain,
                'ok' => true,
                'packageId' => $packageId,
                'apiZone' => $target['zoneApex'],
                'sources' => ['api' => true],
                'records' => $target['zoneRecords'],
                'errors' => new stdClass(),
            ]]);

            $snapshotPaths[$domain] = $snapshotPath;
        } catch (Throwable $snapshotException) {
            echo 'ERROR: snapshot failed: '
                . $snapshotException->getMessage() . "\n";
            $failedDomains[$domain] = 'snapshot failed: '
                . $snapshotException->getMessage();
            continue;
        }

        try {
            $servicesApi->postWithFields(
                '/package/' . rawurlencode($packageId)
                . '/dns/' . rawurlencode($domain),
                buildReplacePayload(
                    $target['recordName'],
                    $newValue,
                    $target['ref']
                )
            );
        } catch (Throwable $domainException) {
            echo 'ERROR: ' . $domainException->getMessage() . "\n";
            fwrite(
                STDERR,
                'Error: ' . $domain . ': '
                . sanitizeApiError($domainException) . "\n"
            );
            $failedDomains[$domain] = $domainException->getMessage();
            continue;
        }

        $acceptedCount++;

        try {
            saveMutationJournalEntry(
                $journalPath,
                $replacementJournal,
                buildReplacementKey(
                    $packageId,
                    $domain,
                    $target['recordName'],
                    $oldValue,
                    $newValue
                ),
                [
                    'operation' => 'replace',
                    'packageId' => $packageId,
                    'domain' => normalizeDomain($domain),
                    'fqdn' => buildRecordFqdn($domain, $target['recordName']),
                    'type' => 'TXT',
                    'oldValue' => $oldValue,
                    'newValue' => $newValue,
                    'ref' => $target['ref'],
                    'snapshot' => $snapshotPaths[$domain],
                ]
            );
        } catch (Throwable $journalException) {
            $journalWarnings[$domain] = $journalException->getMessage();
        }

        try {
            $verified = stackDnsTxtRecordExists(
                $domain,
                $target['recordName'],
                $newValue
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

    $failureCount = count($failedDomains) + count($preflightFailures);

    echo "\nProcessing complete.\n";
    echo "  API accepted: {$acceptedCount}\n";
    echo '  Recent replacements protected: '
        . count($recentReplacementDomains) . "\n";
    echo "  Failures: {$failureCount}\n";

    echo "\nVerification status (advisory):\n";
    echo "  New value verified immediately: {$verifiedCount}\n";
    echo '  Pending publication: ' . count($pendingDomains) . "\n";

    if ($snapshotPaths !== []) {
        echo "\nPre-change snapshots:\n";

        foreach ($snapshotPaths as $domain => $path) {
            echo "  {$domain} -> {$path}\n";
        }
    }

    $allFailures = $preflightFailures + $failedDomains;

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

        echo "\nThe 20i API accepted all pending replacements. "
            . "StackDNS publication may take 30 minutes or longer.\n";
        echo "Do not resubmit these replacements during that interval.\n";
    }

    if ($verificationWarnings !== []) {
        echo "\nAuthoritative verification warnings:\n";

        foreach ($verificationWarnings as $domain => $message) {
            echo "  {$domain} -> {$message}\n";
        }
    }

    if ($journalWarnings !== []) {
        echo "\nLocal replacement journal warnings:\n";

        foreach ($journalWarnings as $domain => $message) {
            echo "  {$domain} -> {$message}\n";
        }

        echo "  Do not rerun these replacements until authoritative DNS reflects them.\n";
    }

    if ($recentReplacementDomains !== []) {
        echo "\nProtected recent replacements ("
            . count($recentReplacementDomains) . "):\n";

        foreach ($recentReplacementDomains as $domain => $entry) {
            $submittedAt = isset($entry['submittedAt'])
                ? (int) $entry['submittedAt']
                : time();
            $age = formatMutationAge($submittedAt);
            echo "  {$domain} -> accepted {$age} ago; publication pending\n";
        }
    }

    exit($failureCount === 0 ? EXIT_SUCCESS : EXIT_PARTIAL_FAILURE);
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
