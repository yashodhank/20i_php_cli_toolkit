#!/usr/bin/env php
<?php
/**
 * Change the destination of one or more email forwards on 20i hosting
 * packages by creating the new destination first, verifying it, and
 * only then deleting the old destination.
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

require_once __DIR__ . '/../../lib/cli.php';
require_once __DIR__ . '/../../lib/package.php';
require_once __DIR__ . '/../../lib/email.php';

use function SoftwareWrap\TwentyI\Cli\confirm;
use function SoftwareWrap\TwentyI\Cli\fail;
use function SoftwareWrap\TwentyI\Cli\readLinesFromStdin;
use function SoftwareWrap\TwentyI\Email\createForward;
use function SoftwareWrap\TwentyI\Email\deleteForward;
use function SoftwareWrap\TwentyI\Email\extractForwardersForDomain;
use function SoftwareWrap\TwentyI\Email\findForwarders;
use function SoftwareWrap\TwentyI\Email\forwarderIds;
use function SoftwareWrap\TwentyI\Email\listForwarders;
use function SoftwareWrap\TwentyI\Email\parseForwardSpec;
use function SoftwareWrap\TwentyI\Email\parseRemoteAddress;
use function SoftwareWrap\TwentyI\Email\runUpdateStateMachine;
use function SoftwareWrap\TwentyI\Email\sameAddress;
use function SoftwareWrap\TwentyI\findPackageByDomain;
use function SoftwareWrap\TwentyI\getPackageId;
use function SoftwareWrap\TwentyI\getPackages;

use const SoftwareWrap\TwentyI\Cli\EXIT_CANCELLED;
use const SoftwareWrap\TwentyI\Cli\EXIT_ERROR;
use const SoftwareWrap\TwentyI\Cli\EXIT_PARTIAL_FAILURE;
use const SoftwareWrap\TwentyI\Cli\EXIT_SUCCESS;
use const SoftwareWrap\TwentyI\Email\UPDATE_NEEDS_MANUAL_DELETE;
use const SoftwareWrap\TwentyI\Email\UPDATE_UPDATED;

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
  {$script} [--dry-run] [--yes] <local@domain> <old-remote> <new-remote>
  {$script} [--dry-run] [--yes] < updates.txt

Options:
  --dry-run  Resolve the forwards that would be updated and make no
             changes.
  --yes, -y  Skip the confirmation prompt for a batch of 10 or more
             updates.
  --help, -h Display this help text.

Standard input lines contain '<local@domain> <old-remote> <new-remote>'.
Blank lines and lines beginning with # are ignored.

Ordering is fixed for safety: the new destination is created first and
verified by re-listing; only then is the old destination deleted. If the
delete step fails after a successful create, BOTH destinations remain
active, the item is reported as 'NEEDS MANUAL DELETE (old destination)',
and the new destination is never rolled back. When the new destination
already exists and the old one is already gone the item is reported as
UNCHANGED and counts as success. Catch-all (empty local part) and
wildcard (*) subjects are refused.

Exit status:
  0  All updates applied (or already in the target state)
  1  Usage, validation, or configuration error before any change
  2  Operator declined the batch confirmation
  3  One or more updates failed (including NEEDS MANUAL DELETE)

EOT
    );

    exit($exitCode);
}

/**
 * Verify that a forward local -> remote is present on the domain.
 */
function verifyForwardPresent(
    \TwentyI\API\Services $servicesApi,
    string $packageId,
    string $domain,
    string $local,
    string $remote
): bool {
    for ($attempt = 1; $attempt <= VERIFICATION_ATTEMPTS; $attempt++) {
        $matches = findForwarders(
            extractForwardersForDomain(
                listForwarders($servicesApi, $packageId),
                $domain
            ),
            $local,
            $remote
        );

        if ($matches !== []) {
            return true;
        }

        if ($attempt < VERIFICATION_ATTEMPTS) {
            sleep(VERIFICATION_DELAY_SECONDS);
        }
    }

    return false;
}

/**
 * Verify that no forward local -> remote remains on the domain.
 */
function verifyForwardAbsent(
    \TwentyI\API\Services $servicesApi,
    string $packageId,
    string $domain,
    string $local,
    string $remote
): bool {
    for ($attempt = 1; $attempt <= VERIFICATION_ATTEMPTS; $attempt++) {
        $matches = findForwarders(
            extractForwardersForDomain(
                listForwarders($servicesApi, $packageId),
                $domain
            ),
            $local,
            $remote
        );

        if ($matches === []) {
            return true;
        }

        if ($attempt < VERIFICATION_ATTEMPTS) {
            sleep(VERIFICATION_DELAY_SECONDS);
        }
    }

    return false;
}

/**
 * Delete the old destination and verify it is gone.
 *
 * Used as the delete step of the update state machine; throwing marks
 * the item NEEDS MANUAL DELETE without touching the new destination.
 *
 * @param array<int,int|string> $ids
 */
function deleteOldDestination(
    \TwentyI\API\Services $servicesApi,
    string $packageId,
    string $domain,
    array $ids,
    string $local,
    string $oldRemote
): void {
    deleteForward($servicesApi, $packageId, $domain, $ids);

    if (
        !verifyForwardAbsent(
            $servicesApi,
            $packageId,
            $domain,
            $local,
            $oldRemote
        )
    ) {
        throw new RuntimeException(
            'the API accepted the delete call, but the old destination is '
            . 'still listed. The delete payload shape may need live '
            . 'confirmation (see lib/email.php buildDeleteForwardPayload()).'
        );
    }
}

/*
 * Parse command-line options and positional arguments. --help is handled
 * before the bootstrap loads so no API key is required to read the docs.
 */
$dryRun = false;
$assumeYes = false;
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

    if (strpos($argument, '-') === 0) {
        fail("Unknown option '{$argument}'.");
    }

    $arguments[] = $argument;
}

if (count($arguments) === 0) {
    $requestLines = readLinesFromStdin();
} elseif (count($arguments) === 3) {
    $requestLines = [implode(' ', $arguments)];
} else {
    usage(EXIT_ERROR);
}

if ($requestLines === []) {
    fail('No updates were provided.');
}

/*
 * Validate every request up front so invalid input aborts before any
 * mutation, then dedupe while preserving order.
 */
$items = [];

foreach ($requestLines as $line) {
    $parts = preg_split('/\s+/', trim($line));

    if (!is_array($parts) || count($parts) !== 3) {
        fail(
            "Invalid input line '{$line}': expected "
            . "'<local@domain> <old-remote> <new-remote>'."
        );
    }

    try {
        $subject = parseForwardSpec($parts[0]);
        $oldRemote = parseRemoteAddress($parts[1]);
        $newRemote = parseRemoteAddress($parts[2]);
    } catch (Throwable $exception) {
        fail($exception->getMessage());
        exit(EXIT_ERROR); // Unreachable; keeps static analysis honest.
    }

    if (sameAddress($oldRemote, $newRemote)) {
        fail(
            "Invalid input line '{$line}': the old and new destinations "
            . 'are identical.'
        );
    }

    $key = $subject['local'] . '@' . $subject['domain']
        . ' ' . strtolower($oldRemote) . ' ' . strtolower($newRemote);

    if (isset($items[$key])) {
        continue;
    }

    $items[$key] = [
        'local' => $subject['local'],
        'domain' => $subject['domain'],
        'oldRemote' => $oldRemote,
        'newRemote' => $newRemote,
        'label' => $subject['local'] . '@' . $subject['domain']
            . ': ' . $oldRemote . ' -> ' . $newRemote,
    ];
}

$items = array_values($items);

try {
    require_once __DIR__ . '/../../lib/bootstrap.php';

    $servicesApi = new \TwentyI\API\Services($api_key);

    /*
     * One GET /package snapshot classifies every source domain; the
     * forwarder list is fetched once per package for classification.
     */
    $packages = getPackages($servicesApi);
    $packageIdByDomain = [];
    $forwarderCache = [];
    $forwarderCacheError = [];

    $toProcess = [];
    $unchanged = [];
    $preflightErrors = [];

    foreach ($items as $item) {
        $domain = $item['domain'];

        if (!array_key_exists($domain, $packageIdByDomain)) {
            $package = findPackageByDomain($packages, $domain);
            $packageIdByDomain[$domain] = $package === null
                ? null
                : getPackageId($package);
        }

        $packageId = $packageIdByDomain[$domain];

        if ($packageId === null) {
            $preflightErrors[$item['label']] =
                "no package contains '{$domain}'";
            continue;
        }

        if (
            !isset($forwarderCache[$packageId])
            && !isset($forwarderCacheError[$packageId])
        ) {
            try {
                $forwarderCache[$packageId] =
                    listForwarders($servicesApi, $packageId);
            } catch (Throwable $exception) {
                $forwarderCacheError[$packageId] = $exception->getMessage();
            }
        }

        if (isset($forwarderCacheError[$packageId])) {
            $preflightErrors[$item['label']] =
                'forwards could not be listed: '
                . $forwarderCacheError[$packageId];
            continue;
        }

        $domainForwarders = extractForwardersForDomain(
            $forwarderCache[$packageId],
            $domain
        );

        $oldMatches = findForwarders(
            $domainForwarders,
            $item['local'],
            $item['oldRemote']
        );
        $newMatches = findForwarders(
            $domainForwarders,
            $item['local'],
            $item['newRemote']
        );

        if ($oldMatches === [] && $newMatches !== []) {
            /*
             * Already in the target state: new present, old absent.
             */
            $unchanged[$item['label']] = 'already updated';
            continue;
        }

        if ($oldMatches === [] && $newMatches === []) {
            $preflightErrors[$item['label']] =
                "no forward '{$item['local']}@{$domain}' -> "
                . "'{$item['oldRemote']}' exists";
            continue;
        }

        try {
            $oldIds = forwarderIds($oldMatches);
        } catch (Throwable $exception) {
            $preflightErrors[$item['label']] = $exception->getMessage();
            continue;
        }

        $item['packageId'] = $packageId;
        $item['oldIds'] = $oldIds;
        $item['newAlreadyPresent'] = $newMatches !== [];
        $toProcess[] = $item;
    }

    echo 'Requested updates: ' . count($items) . "\n";
    echo 'Updates to apply: ' . count($toProcess) . "\n";

    if ($dryRun) {
        echo "\nDry-run results:\n";

        $total = count($toProcess);

        foreach ($toProcess as $offset => $item) {
            $position = $offset + 1;
            $detail = $item['newAlreadyPresent']
                ? 'WOULD DELETE OLD (new destination already present)'
                : 'WOULD UPDATE (create new, verify, delete old)';
            echo "[{$position}/{$total}] {$item['label']} ... {$detail}\n";
        }

        foreach ($unchanged as $label => $reason) {
            echo "  {$label} ... UNCHANGED ({$reason})\n";
        }

        foreach ($preflightErrors as $label => $reason) {
            echo "  {$label} ... ERROR: {$reason}\n";
        }

        echo "\nDry run complete. No changes were made.\n";
        exit($preflightErrors === [] ? EXIT_SUCCESS : EXIT_PARTIAL_FAILURE);
    }

    if (
        count($toProcess) >= CONFIRMATION_THRESHOLD
        && !$assumeYes
        && !confirm(
            "\nThis will update " . count($toProcess)
            . ' email forwards individually. Continue? [y/N] '
        )
    ) {
        fwrite(STDERR, "\nOperation cancelled. No changes were made.\n");
        exit(EXIT_CANCELLED);
    }

    $successCount = 0;
    $failed = [];
    $total = count($toProcess);

    if ($toProcess !== []) {
        echo "\nProcessing updates:\n";
    }

    foreach ($toProcess as $offset => $item) {
        $position = $offset + 1;

        /*
         * Print and flush before the network calls so the operator
         * always sees which update is currently being processed.
         */
        echo "[{$position}/{$total}] {$item['label']} ... ";
        fflush(STDOUT);

        $packageId = $item['packageId'];
        $domain = $item['domain'];
        $local = $item['local'];
        $oldRemote = $item['oldRemote'];
        $newRemote = $item['newRemote'];
        $oldIds = $item['oldIds'];

        if ($item['newAlreadyPresent']) {
            /*
             * The create+verify half is already satisfied; only the old
             * destination remains to be deleted. A failure here is the
             * same both-destinations-active state as a late failure in
             * the full machine.
             */
            try {
                deleteOldDestination(
                    $servicesApi,
                    $packageId,
                    $domain,
                    $oldIds,
                    $local,
                    $oldRemote
                );

                echo "SUCCESS\n";
                $successCount++;
            } catch (Throwable $exception) {
                echo 'NEEDS MANUAL DELETE (old destination): '
                    . $exception->getMessage() . "\n";
                $failed[$item['label']] =
                    'NEEDS MANUAL DELETE (old destination): '
                    . $exception->getMessage();
            }

            continue;
        }

        $result = runUpdateStateMachine(
            static function () use (
                $servicesApi,
                $packageId,
                $domain,
                $local,
                $newRemote
            ): void {
                createForward(
                    $servicesApi,
                    $packageId,
                    $domain,
                    $local,
                    $newRemote
                );
            },
            static function () use (
                $servicesApi,
                $packageId,
                $domain,
                $local,
                $newRemote
            ): bool {
                return verifyForwardPresent(
                    $servicesApi,
                    $packageId,
                    $domain,
                    $local,
                    $newRemote
                );
            },
            static function () use (
                $servicesApi,
                $packageId,
                $domain,
                $oldIds,
                $local,
                $oldRemote
            ): void {
                deleteOldDestination(
                    $servicesApi,
                    $packageId,
                    $domain,
                    $oldIds,
                    $local,
                    $oldRemote
                );
            }
        );

        if ($result['status'] === UPDATE_UPDATED) {
            echo "SUCCESS\n";
            $successCount++;
            continue;
        }

        if ($result['status'] === UPDATE_NEEDS_MANUAL_DELETE) {
            echo $result['message'] . "\n";
            $failed[$item['label']] = $result['message'];
            continue;
        }

        echo "ERROR: {$result['message']}\n";
        $failed[$item['label']] = $result['message'];
    }

    $failureCount = count($failed) + count($preflightErrors);

    echo "\nProcessing complete.\n";
    echo "  Updated: {$successCount}\n";
    echo "  Failed: {$failureCount}\n";
    echo '  Unchanged: ' . count($unchanged) . "\n";

    if ($preflightErrors !== []) {
        echo "\nFailed before any change was attempted:\n";

        foreach ($preflightErrors as $label => $message) {
            echo "  {$label} -> {$message}\n";
        }
    }

    if ($failed !== []) {
        echo "\nFailed updates:\n";

        foreach ($failed as $label => $message) {
            echo "  {$label} -> {$message}\n";
        }
    }

    if ($unchanged !== []) {
        echo "\nAlready in the target state (" . count($unchanged) . "):\n";

        foreach ($unchanged as $label => $reason) {
            echo "  {$label} ({$reason})\n";
        }
    }

    exit($failureCount === 0 ? EXIT_SUCCESS : EXIT_PARTIAL_FAILURE);
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
