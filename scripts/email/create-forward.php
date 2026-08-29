#!/usr/bin/env php
<?php
/**
 * Create one or more email forwards on 20i hosting packages.
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
 * Created: December 30, 2024 (rewritten onto the shared bootstrap 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/cli.php';
require_once __DIR__ . '/../../lib/package.php';
require_once __DIR__ . '/../../lib/email.php';

use function SoftwareWrap\TwentyI\Cli\confirm;
use function SoftwareWrap\TwentyI\Cli\fail;
use function SoftwareWrap\TwentyI\Cli\readLinesFromStdin;
use function SoftwareWrap\TwentyI\Email\createForward;
use function SoftwareWrap\TwentyI\Email\extractForwardersForDomain;
use function SoftwareWrap\TwentyI\Email\findForwarders;
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

/**
 * Display usage information.
 */
function usage(int $exitCode = EXIT_SUCCESS): void
{
    $script = basename($_SERVER['argv'][0]);
    $stream = $exitCode === EXIT_SUCCESS ? STDOUT : STDERR;

    fwrite($stream, <<<EOT
Usage:
  {$script} [--dry-run] [--yes] [--skip] <local@domain> <remote@dest>
  {$script} [--dry-run] [--yes] [--skip] < forwards.txt

Options:
  --dry-run  Resolve packages and existing forwards, print what would be
             created, and make no changes.
  --yes, -y  Skip the confirmation prompt for a batch of 10 or more
             forwards.
  --skip     Skip forwards that already exist identically instead of
             counting them as failures.
  --help, -h Display this help text.

Standard input lines contain '<local@domain> <remote@dest>' pairs.
Blank lines and lines beginning with # are ignored.

Each source domain must belong to a hosting package visible to the API
key. The package list is fetched once per run; existing forwards are
fetched once per package to detect duplicates. Catch-all (empty local
part) and wildcard (*) subjects are refused.

Exit status:
  0  All requested forwards created (or skipped with --skip)
  1  Usage, validation, or configuration error before any change
  2  Operator declined the batch confirmation
  3  One or more forwards failed

EOT
    );

    exit($exitCode);
}

/*
 * Parse command-line options and positional arguments. --help is handled
 * before the bootstrap loads so no API key is required to read the docs.
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

if (count($arguments) === 0) {
    $pairLines = readLinesFromStdin();
} elseif (count($arguments) === 2) {
    $pairLines = [$arguments[0] . ' ' . $arguments[1]];
} else {
    usage(EXIT_ERROR);
}

if ($pairLines === []) {
    fail('No forwards were provided.');
}

/*
 * Validate every requested pair up front so invalid input aborts before
 * any mutation, then dedupe while preserving order.
 */
$items = [];

foreach ($pairLines as $line) {
    $parts = preg_split('/\s+/', trim($line));

    if (!is_array($parts) || count($parts) !== 2) {
        fail(
            "Invalid input line '{$line}': expected "
            . "'<local@domain> <remote@dest>'."
        );
    }

    try {
        $subject = parseForwardSpec($parts[0]);
        $remote = parseRemoteAddress($parts[1]);
    } catch (Throwable $exception) {
        fail($exception->getMessage());
        exit(EXIT_ERROR); // Unreachable; keeps static analysis honest.
    }

    $key = $subject['local'] . '@' . $subject['domain']
        . ' -> ' . strtolower($remote);

    if (isset($items[$key])) {
        continue;
    }

    $items[$key] = [
        'local' => $subject['local'],
        'domain' => $subject['domain'],
        'remote' => $remote,
        'label' => $subject['local'] . '@' . $subject['domain']
            . ' -> ' . $remote,
    ];
}

$items = array_values($items);

try {
    require_once __DIR__ . '/../../lib/bootstrap.php';

    $servicesApi = new \TwentyI\API\Services($api_key);

    /*
     * One GET /package snapshot classifies every source domain; existing
     * forwards are fetched once per package for idempotency checks.
     */
    $packages = getPackages($servicesApi);
    $packageIdByDomain = [];
    $forwarderCache = [];
    $forwarderCacheError = [];

    $toCreate = [];
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
                'existing forwards could not be listed, refusing to '
                . 'create blind: ' . $forwarderCacheError[$packageId];
            continue;
        }

        $existing = findForwarders(
            extractForwardersForDomain($forwarderCache[$packageId], $domain),
            $item['local'],
            $item['remote']
        );

        if ($existing !== []) {
            if ($skipExisting) {
                $skipped[$item['label']] = 'already exists';
            } else {
                $preflightErrors[$item['label']] =
                    'an identical forward already exists '
                    . '(rerun with --skip to ignore it)';
            }

            continue;
        }

        $item['packageId'] = $packageId;
        $toCreate[] = $item;
    }

    echo 'Requested forwards: ' . count($items) . "\n";
    echo 'Forwards to create: ' . count($toCreate) . "\n";

    if ($dryRun) {
        echo "\nDry-run results:\n";

        $total = count($toCreate);

        foreach ($toCreate as $offset => $item) {
            $position = $offset + 1;
            echo "[{$position}/{$total}] {$item['label']} ... WOULD CREATE\n";
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
        count($toCreate) >= CONFIRMATION_THRESHOLD
        && !$assumeYes
        && !confirm(
            "\nThis will create " . count($toCreate)
            . ' email forwards individually. Continue? [y/N] '
        )
    ) {
        fwrite(STDERR, "\nOperation cancelled. No changes were made.\n");
        exit(EXIT_CANCELLED);
    }

    $successCount = 0;
    $failed = [];
    $total = count($toCreate);

    if ($toCreate !== []) {
        echo "\nProcessing forwards:\n";
    }

    foreach ($toCreate as $offset => $item) {
        $position = $offset + 1;

        /*
         * Print and flush before the network call so the operator always
         * sees which forward is currently being processed.
         */
        echo "[{$position}/{$total}] {$item['label']} ... ";
        fflush(STDOUT);

        try {
            createForward(
                $servicesApi,
                $item['packageId'],
                $item['domain'],
                $item['local'],
                $item['remote']
            );

            echo "SUCCESS\n";
            $successCount++;
        } catch (Throwable $exception) {
            echo "ERROR: {$exception->getMessage()}\n";
            $failed[$item['label']] = $exception->getMessage();
        }
    }

    $failureCount = count($failed) + count($preflightErrors);

    echo "\nProcessing complete.\n";
    echo "  Created: {$successCount}\n";
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
        echo "\nSkipped existing forwards (" . count($skipped) . "):\n";

        foreach ($skipped as $label => $reason) {
            echo "  {$label} ({$reason})\n";
        }
    }

    exit($failureCount === 0 ? EXIT_SUCCESS : EXIT_PARTIAL_FAILURE);
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
