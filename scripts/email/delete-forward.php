#!/usr/bin/env php
<?php
/**
 * Delete one or more email forwards from 20i hosting packages.
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
use function SoftwareWrap\TwentyI\Email\deleteForward;
use function SoftwareWrap\TwentyI\Email\distinctRemotes;
use function SoftwareWrap\TwentyI\Email\extractForwardersForDomain;
use function SoftwareWrap\TwentyI\Email\findForwarders;
use function SoftwareWrap\TwentyI\Email\forwarderIds;
use function SoftwareWrap\TwentyI\Email\listForwarders;
use function SoftwareWrap\TwentyI\Email\parseForwardSpec;
use function SoftwareWrap\TwentyI\Email\parseRemoteAddress;
use function SoftwareWrap\TwentyI\findPackageByDomain;
use function SoftwareWrap\TwentyI\getPackageId;
use function SoftwareWrap\TwentyI\getPackages;

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
  {$script} [--dry-run] [--yes] [--skip] <local@domain> [<remote@dest>]
  {$script} [--dry-run] [--yes] [--skip] < forwards.txt

Options:
  --dry-run  Resolve the forwards that would be deleted and make no
             changes.
  --yes, -y  Skip the confirmation prompt for a batch of 10 or more
             forwards.
  --skip     Skip forwards that do not exist instead of counting them as
             failures.
  --help, -h Display this help text.

Standard input lines contain '<local@domain>' or
'<local@domain> <remote@dest>'. Blank lines and lines beginning with #
are ignored.

Forwards are resolved by listing the package's forwarders and matching
on the local part (and destination when given). When a source address
forwards to multiple destinations, the destination argument is required;
the command fails that item loudly rather than guessing. Catch-all
(empty local part) and wildcard (*) subjects are refused.

Every deletion is verified by re-listing the package's forwarders; a
delete call that the API accepts but does not apply is reported as a
failure.

Exit status:
  0  All requested forwards deleted (or skipped with --skip)
  1  Usage, validation, or configuration error before any change
  2  Operator declined the batch confirmation
  3  One or more forwards failed

EOT
    );

    exit($exitCode);
}

/**
 * Verify that the given forwarder IDs are gone from the domain.
 *
 * @param array<int,int|string> $ids
 */
function verifyForwardersAbsent(
    \TwentyI\API\Services $servicesApi,
    string $packageId,
    string $domain,
    array $ids
): bool {
    $wanted = [];

    foreach ($ids as $id) {
        $wanted[(string) $id] = true;
    }

    for ($attempt = 1; $attempt <= VERIFICATION_ATTEMPTS; $attempt++) {
        $remaining = extractForwardersForDomain(
            listForwarders($servicesApi, $packageId),
            $domain
        );

        $present = false;

        foreach ($remaining as $forwarder) {
            if (
                $forwarder['id'] !== null
                && isset($wanted[(string) $forwarder['id']])
            ) {
                $present = true;
                break;
            }
        }

        if (!$present) {
            return true;
        }

        if ($attempt < VERIFICATION_ATTEMPTS) {
            sleep(VERIFICATION_DELAY_SECONDS);
        }
    }

    return false;
}

/*
 * Parse command-line options and positional arguments. --help is handled
 * before the bootstrap loads so no API key is required to read the docs.
 */
$dryRun = false;
$assumeYes = false;
$skipMissing = false;
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
        $skipMissing = true;
        continue;
    }

    if (strpos($argument, '-') === 0) {
        fail("Unknown option '{$argument}'.");
    }

    $arguments[] = $argument;
}

if (count($arguments) === 0) {
    $requestLines = readLinesFromStdin();
} elseif (count($arguments) <= 2) {
    $requestLines = [implode(' ', $arguments)];
} else {
    usage(EXIT_ERROR);
}

if ($requestLines === []) {
    fail('No forwards were provided.');
}

/*
 * Validate every request up front so invalid input aborts before any
 * mutation, then dedupe while preserving order.
 */
$items = [];

foreach ($requestLines as $line) {
    $parts = preg_split('/\s+/', trim($line));

    if (!is_array($parts) || count($parts) < 1 || count($parts) > 2) {
        fail(
            "Invalid input line '{$line}': expected "
            . "'<local@domain> [<remote@dest>]'."
        );
    }

    try {
        $subject = parseForwardSpec($parts[0]);
        $remote = count($parts) === 2 ? parseRemoteAddress($parts[1]) : null;
    } catch (Throwable $exception) {
        fail($exception->getMessage());
        exit(EXIT_ERROR); // Unreachable; keeps static analysis honest.
    }

    $label = $subject['local'] . '@' . $subject['domain']
        . ($remote === null ? '' : ' -> ' . $remote);
    $key = $subject['local'] . '@' . $subject['domain']
        . ' -> ' . ($remote === null ? '*any*' : strtolower($remote));

    if (isset($items[$key])) {
        continue;
    }

    $items[$key] = [
        'local' => $subject['local'],
        'domain' => $subject['domain'],
        'remote' => $remote,
        'label' => $label,
    ];
}

$items = array_values($items);

try {
    require_once __DIR__ . '/../../lib/bootstrap.php';

    $servicesApi = new \TwentyI\API\Services($api_key);

    /*
     * One GET /package snapshot classifies every source domain; the
     * forwarder list is fetched once per package for matching.
     */
    $packages = getPackages($servicesApi);
    $packageIdByDomain = [];
    $forwarderCache = [];
    $forwarderCacheError = [];

    $toDelete = [];
    $skipped = [];
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

        $matches = findForwarders(
            $domainForwarders,
            $item['local'],
            $item['remote']
        );

        if ($matches === []) {
            if ($skipMissing) {
                $skipped[$item['label']] = 'not found';
            } else {
                $preflightErrors[$item['label']] =
                    'no matching forward exists '
                    . '(rerun with --skip to ignore missing forwards)';
            }

            continue;
        }

        /*
         * A source with several destinations needs an explicit
         * destination; deleting all of them on a bare local@domain would
         * be a guess.
         */
        $remotes = distinctRemotes($matches);

        if ($item['remote'] === null && count($remotes) > 1) {
            $preflightErrors[$item['label']] =
                "'{$item['local']}@{$domain}' forwards to multiple "
                . 'destinations (' . implode(', ', $remotes)
                . '); pass the destination to select one';
            continue;
        }

        try {
            $ids = forwarderIds($matches);
        } catch (Throwable $exception) {
            $preflightErrors[$item['label']] = $exception->getMessage();
            continue;
        }

        $item['packageId'] = $packageId;
        $item['ids'] = $ids;
        $item['remotes'] = $remotes;
        $toDelete[] = $item;
    }

    echo 'Requested forwards: ' . count($items) . "\n";
    echo 'Forwards to delete: ' . count($toDelete) . "\n";

    if ($dryRun) {
        echo "\nDry-run results:\n";

        $total = count($toDelete);

        foreach ($toDelete as $offset => $item) {
            $position = $offset + 1;
            echo "[{$position}/{$total}] {$item['label']}"
                . ' (ids ' . implode(', ', $item['ids']) . ')'
                . " ... WOULD DELETE\n";
        }

        foreach ($skipped as $label => $reason) {
            echo "  {$label} ... WOULD SKIP ({$reason})\n";
        }

        foreach ($preflightErrors as $label => $reason) {
            echo "  {$label} ... ERROR: {$reason}\n";
        }

        echo "\nDry run complete. No changes were made.\n";
        exit($preflightErrors === [] ? EXIT_SUCCESS : EXIT_PARTIAL_FAILURE);
    }

    if (
        count($toDelete) >= CONFIRMATION_THRESHOLD
        && !$assumeYes
        && !confirm(
            "\nThis will delete " . count($toDelete)
            . ' email forwards individually. Continue? [y/N] '
        )
    ) {
        fwrite(STDERR, "\nOperation cancelled. No changes were made.\n");
        exit(EXIT_CANCELLED);
    }

    $successCount = 0;
    $failed = [];
    $total = count($toDelete);

    if ($toDelete !== []) {
        echo "\nProcessing forwards:\n";
    }

    foreach ($toDelete as $offset => $item) {
        $position = $offset + 1;

        /*
         * Print and flush before the network calls so the operator
         * always sees which forward is currently being processed.
         */
        echo "[{$position}/{$total}] {$item['label']} ... ";
        fflush(STDOUT);

        try {
            deleteForward(
                $servicesApi,
                $item['packageId'],
                $item['domain'],
                $item['ids']
            );

            if (
                !verifyForwardersAbsent(
                    $servicesApi,
                    $item['packageId'],
                    $item['domain'],
                    $item['ids']
                )
            ) {
                throw new RuntimeException(
                    'the API accepted the delete call, but the forward is '
                    . 'still listed. The delete payload shape may need '
                    . 'live confirmation (see lib/email.php '
                    . 'buildDeleteForwardPayload()).'
                );
            }

            echo "SUCCESS\n";
            $successCount++;
        } catch (Throwable $exception) {
            echo "ERROR: {$exception->getMessage()}\n";
            $failed[$item['label']] = $exception->getMessage();
        }
    }

    $failureCount = count($failed) + count($preflightErrors);

    echo "\nProcessing complete.\n";
    echo "  Deleted: {$successCount}\n";
    echo "  Failed: {$failureCount}\n";
    echo '  Skipped: ' . count($skipped) . "\n";

    if ($preflightErrors !== []) {
        echo "\nFailed before any change was attempted:\n";

        foreach ($preflightErrors as $label => $message) {
            echo "  {$label} -> {$message}\n";
        }
    }

    if ($failed !== []) {
        echo "\nFailed forwards:\n";

        foreach ($failed as $label => $message) {
            echo "  {$label} -> {$message}\n";
        }
    }

    if ($skipped !== []) {
        echo "\nSkipped missing forwards (" . count($skipped) . "):\n";

        foreach ($skipped as $label => $reason) {
            echo "  {$label} ({$reason})\n";
        }
    }

    exit($failureCount === 0 ? EXIT_SUCCESS : EXIT_PARTIAL_FAILURE);
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
