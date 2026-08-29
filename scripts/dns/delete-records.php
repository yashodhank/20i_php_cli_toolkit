#!/usr/bin/env php
<?php
/**
 * Delete DNS records from one or more domains attached to 20i packages.
 *
 * Records are matched by normalized owner name, type, and (optionally)
 * value against the 20i stored zone, identified by their stable per-record
 * ref, snapshotted locally, and then removed with one atomic DNS diff POST
 * per domain. Post-change authoritative verification is advisory because
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
use function SoftwareWrap\TwentyI\Dns\assertRecordsDeletable;
use function SoftwareWrap\TwentyI\Dns\buildDeletePayload;
use function SoftwareWrap\TwentyI\Dns\buildRecordFqdn;
use function SoftwareWrap\TwentyI\Dns\buildSnapshotFilename;
use function SoftwareWrap\TwentyI\Dns\extractRecordRef;
use function SoftwareWrap\TwentyI\Dns\findMatchingApiRecords;
use function SoftwareWrap\TwentyI\Dns\findZoneForDomain;
use function SoftwareWrap\TwentyI\Dns\formatMutationAge;
use function SoftwareWrap\TwentyI\Dns\getApiRecordsForDomain;
use function SoftwareWrap\TwentyI\Dns\getMutationJournalPath;
use function SoftwareWrap\TwentyI\Dns\getPackageZoneMap;
use function SoftwareWrap\TwentyI\Dns\getSnapshotDirectory;
use function SoftwareWrap\TwentyI\Dns\getStackDnsRecords;
use function SoftwareWrap\TwentyI\Dns\normalizeRecordNameForDomain;
use function SoftwareWrap\TwentyI\Dns\rdataValuesEqual;
use function SoftwareWrap\TwentyI\Dns\readMutationJournal;
use function SoftwareWrap\TwentyI\Dns\recordTypeCode;
use function SoftwareWrap\TwentyI\Dns\requireDeletableRecordType;
use function SoftwareWrap\TwentyI\Dns\saveMutationJournalEntry;
use function SoftwareWrap\TwentyI\Dns\writeSnapshotFile;
use function SoftwareWrap\TwentyI\findPackageByDomain;
use function SoftwareWrap\TwentyI\getPackageDomains;
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
const DELETION_JOURNAL_BASENAME = 'dns-deletions.json';

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
      --name <dns-name> --type <T> [--value <string>]

  {$script} [--dry-run] [--yes] [--skip] [--force]
      --name <dns-name> --type <T> [--value <string>] < domains.txt

  {$script} [--dry-run] [--yes] [--skip] [--force] --all <package-domain>
      --name <dns-name> --type <T> [--value <string>]

Options:
  --name <dns-name>
             DNS owner name. Use @ for the zone apex.
  --type <T>
             DNS record type. Supported: A, AAAA, CNAME, MX, TXT, SRV.
             SOA can never be deleted; NS deletion is refused entirely so
             the zone apex always keeps its delegation.
  --value <string>
             Optional record value. When present, only records whose data
             matches are deleted (TXT compares normalized values; other
             types compare case-insensitively). When omitted, EVERY record
             of that owner and type is deleted.
  --all      Delete matching records on every domain attached to the
             package identified by the positional <package-domain>.
  --dry-run  Resolve packages, read stored zones, match records, and show
             what would be deleted without changing DNS.
  --yes, -y  Skip the confirmation prompt for a batch of 10 or more domains.
  --skip     Treat a domain with zero matching records as a skip instead of
             a per-domain failure.
  --force    Ignore the local recent-deletion safeguard. It does not
             bypass matching, snapshots, or guards.
  --help, -h Display this help text.

Examples:
  {$script} example.com --name _acme-challenge --type TXT
  {$script} example.com --name @ --type TXT --value "old verification"
  {$script} --all lowpricereseller.com --name @ --type TXT \
      --value "This domain is for sale"

A single positional domain processes one domain. With no positional domain,
domains are read from standard input. The --all option requires one
positional domain that identifies a package.

Before any change, the full stored zone of each mutated domain is written
as JSON Lines (including raw record fields and refs) to the local state
directory under 20i-cli/snapshots/. A snapshot failure aborts that
domain's deletion.

Each domain is submitted separately with one atomic delete diff. Immediate
authoritative verification is advisory because StackDNS publication may
take 30 minutes or longer; ACCEPTED with PUBLICATION PENDING is a success.
Accepted deletions are recorded locally for 60 minutes to prevent
accidental duplicate resubmission.

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
 * Return the stable key identifying one deletion request.
 */
function buildDeletionKey(
    string $packageId,
    string $domain,
    string $recordName,
    string $recordType,
    ?string $recordValue
): string {
    return hash(
        'sha256',
        implode("\n", [
            'delete',
            $packageId,
            normalizeDomain($domain),
            buildRecordFqdn($domain, $recordName),
            $recordType,
            $recordValue === null ? '*' : $recordValue,
        ])
    );
}

/**
 * Advisory authoritative check that the deleted records are gone.
 *
 * Returns true when StackDNS no longer answers with any matching record.
 * Publication lag makes a false return ordinary rather than an error.
 */
function deletionVerified(
    string $domain,
    string $recordName,
    string $recordType,
    ?string $recordValue
): bool {
    $fqdn = buildRecordFqdn($domain, $recordName);
    $records = getStackDnsRecords($fqdn, recordTypeCode($recordType));

    foreach ($records as $record) {
        if (
            $recordValue === null
            || rdataValuesEqual(
                $recordType,
                (string) $record['rdata'],
                $recordValue
            )
        ) {
            return false;
        }
    }

    return true;
}

/**
 * Report zero-match domains skipped during preflight.
 *
 * @param array<int,string> $skippedDomains
 */
function reportSkippedDomains(array $skippedDomains): void
{
    if ($skippedDomains === []) {
        return;
    }

    echo "\nSkipped domains without matching records ("
        . count($skippedDomains) . "):\n";

    foreach ($skippedDomains as $domain) {
        echo "  {$domain}\n";
    }
}

/**
 * Report journal-protected recent deletions.
 *
 * @param array<string,array<string,mixed>> $recentDomains
 */
function reportRecentDeletions(array $recentDomains): void
{
    if ($recentDomains === []) {
        return;
    }

    echo "\nProtected recent deletions (" . count($recentDomains) . "):\n";

    foreach ($recentDomains as $domain => $entry) {
        $submittedAt = isset($entry['submittedAt']) ? (int) $entry['submittedAt'] : time();
        $age = formatMutationAge($submittedAt);
        echo "  {$domain} -> accepted {$age} ago; publication pending\n";
    }
}

/*
 * Parse options and positional arguments. --help must work without any
 * API key, so nothing here touches the bootstrap.
 */
$dryRun = false;
$assumeYes = false;
$skipZeroMatches = false;
$forceRecentDeletion = false;
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
        $skipZeroMatches = true;
        continue;
    }

    if ($argument === '--force') {
        $forceRecentDeletion = true;
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

try {
    $recordType = requireDeletableRecordType($recordType);

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

    if ($allDomains) {
        $package = findPackageByDomain($packages, $selectorDomain);

        if ($package === null) {
            fail("No package contains '{$selectorDomain}'.");
        }

        $packageId = getPackageId($package);
        $packageDomains = getPackageDomains($package);

        if ($packageDomains === []) {
            fail("Package '{$packageId}' does not contain any usable domains.");
        }

        foreach ($packageDomains as $domain) {
            $targets[] = [
                'domain' => $domain,
                'packageId' => $packageId,
            ];
        }
    } else {
        foreach ($requestedDomains as $domain) {
            $package = findPackageByDomain($packages, $domain);

            $targets[] = [
                'domain' => $domain,
                'packageId' => $package === null ? null : getPackageId($package),
            ];
        }
    }

    echo "DNS record deletion:\n";
    echo "  Name:  {$recordName}\n";
    echo "  Type:  {$recordType}\n";
    echo '  Value: '
        . ($recordValue === null ? '(any value of this owner and type)' : $recordValue)
        . "\n";
    echo "\nRequested mode: "
        . ($allDomains ? 'all domains in package' : 'explicit domains')
        . "\n";
    echo 'Skip domains without matches: '
        . ($skipZeroMatches ? 'yes' : 'no') . "\n";
    echo 'Ignore recent-deletion safeguard: '
        . ($forceRecentDeletion ? 'yes' : 'no') . "\n";
    echo 'Domains resolved: ' . count($targets) . "\n";

    $journalPath = getMutationJournalPath(DELETION_JOURNAL_BASENAME);
    $deletionJournal = readMutationJournal(
        $journalPath,
        RECENT_SUBMISSION_WINDOW_SECONDS
    );

    $eligibleTargets = [];
    $zeroMatchSkips = [];
    $recentDeletionDomains = [];
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
                $recordType,
                $recordValue
            );

            if ($matches === []) {
                if ($skipZeroMatches) {
                    echo "NO MATCH (skipped)\n";
                    $zeroMatchSkips[] = $domain;
                    continue;
                }

                throw new RuntimeException(
                    'no matching records; rerun with --skip to tolerate this'
                );
            }

            assertRecordsDeletable($matches, $zoneApex);

            $refs = [];

            foreach ($matches as $match) {
                $ref = extractRecordRef($match);

                if ($ref === null) {
                    throw new RuntimeException(
                        'a matched record carries no ref and cannot be deleted'
                    );
                }

                $refs[] = $ref;
            }

            if (!$forceRecentDeletion) {
                $key = buildDeletionKey(
                    $packageId,
                    $domain,
                    $targetRecordName,
                    $recordType,
                    $recordValue
                );

                if (isset($deletionJournal[$key])) {
                    $entry = $deletionJournal[$key];
                    $submittedAt = isset($entry['submittedAt'])
                        ? (int) $entry['submittedAt']
                        : time();
                    $age = formatMutationAge($submittedAt);
                    echo "RECENTLY DELETED ({$age} ago)\n";
                    $recentDeletionDomains[$domain] = $entry;
                    continue;
                }
            }

            echo 'READY (' . count($refs) . ' record'
                . (count($refs) === 1 ? '' : 's') . ")\n";

            $eligibleTargets[] = [
                'domain' => $domain,
                'packageId' => $packageId,
                'recordName' => $targetRecordName,
                'zoneApex' => $zoneApex,
                'zoneRecords' => $zoneRecords,
                'matches' => $matches,
                'refs' => $refs,
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
    echo '  Ready to delete: ' . count($eligibleTargets) . "\n";
    echo '  No-match skips: ' . count($zeroMatchSkips) . "\n";
    echo '  Recently deleted: ' . count($recentDeletionDomains) . "\n";
    echo '  Preflight failures: ' . count($preflightFailures) . "\n";

    if ($eligibleTargets === []) {
        echo "\nNo DNS records need to be deleted.\n";
        reportSkippedDomains($zeroMatchSkips);
        reportRecentDeletions($recentDeletionDomains);

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
            $count = count($target['refs']);
            echo "[{$position}/{$total}] {$target['domain']} ... WOULD DELETE "
                . $count . ' record' . ($count === 1 ? '' : 's')
                . ' (refs: ' . implode(', ', $target['refs']) . ")\n";
        }

        echo "\nDry run complete. No DNS changes were made.\n";
        reportSkippedDomains($zeroMatchSkips);
        reportRecentDeletions($recentDeletionDomains);

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
            "\nThis will delete matching DNS records on "
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
                buildDeletePayload($target['refs'])
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
                $deletionJournal,
                buildDeletionKey(
                    $packageId,
                    $domain,
                    $target['recordName'],
                    $recordType,
                    $recordValue
                ),
                [
                    'operation' => 'delete',
                    'packageId' => $packageId,
                    'domain' => normalizeDomain($domain),
                    'fqdn' => buildRecordFqdn($domain, $target['recordName']),
                    'type' => $recordType,
                    'value' => $recordValue,
                    'refs' => $target['refs'],
                    'snapshot' => $snapshotPaths[$domain],
                ]
            );
        } catch (Throwable $journalException) {
            $journalWarnings[$domain] = $journalException->getMessage();
        }

        try {
            $verified = deletionVerified(
                $domain,
                $target['recordName'],
                $recordType,
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

    $failureCount = count($failedDomains) + count($preflightFailures);

    echo "\nProcessing complete.\n";
    echo "  API accepted: {$acceptedCount}\n";
    echo '  No-match skips: ' . count($zeroMatchSkips) . "\n";
    echo '  Recent deletions protected: '
        . count($recentDeletionDomains) . "\n";
    echo "  Failures: {$failureCount}\n";

    echo "\nVerification status (advisory):\n";
    echo "  Verified gone immediately: {$verifiedCount}\n";
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

        echo "\nThe 20i API accepted all pending deletions. "
            . "StackDNS publication may take 30 minutes or longer.\n";
        echo "Do not resubmit these deletions during that interval.\n";
    }

    if ($verificationWarnings !== []) {
        echo "\nAuthoritative verification warnings:\n";

        foreach ($verificationWarnings as $domain => $message) {
            echo "  {$domain} -> {$message}\n";
        }
    }

    if ($journalWarnings !== []) {
        echo "\nLocal deletion journal warnings:\n";

        foreach ($journalWarnings as $domain => $message) {
            echo "  {$domain} -> {$message}\n";
        }

        echo "  Do not rerun these deletions until authoritative DNS reflects them.\n";
    }

    reportSkippedDomains($zeroMatchSkips);
    reportRecentDeletions($recentDeletionDomains);

    exit($failureCount === 0 ? EXIT_SUCCESS : EXIT_PARTIAL_FAILURE);
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
