#!/usr/bin/env php
<?php
/**
 * Read-only email forward dump: print the forwarders configured for
 * domains attached to 20i packages, one JSON object per domain.
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
 * stdout carries machine-consumable JSON Lines. The vendored 20i client
 * and PHP runtime can raise notices and warnings mid-run (for example the
 * client's "404 on <url>" notice); with CLI defaults those interleave
 * into stdout and corrupt the stream. Deprecations from the vendored
 * client are dropped outright; every other diagnostic is routed to
 * STDERR.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 'stderr');
set_error_handler(static function (
    int $severity,
    string $message,
    string $file,
    int $line
): bool {
    if ((error_reporting() & $severity) === 0) {
        return true;
    }

    fwrite(STDERR, "[php] {$message} in {$file}:{$line}\n");

    return true;
});

require_once __DIR__ . '/../../lib/cli.php';
require_once __DIR__ . '/../../lib/package.php';
require_once __DIR__ . '/../../lib/email.php';

use function SoftwareWrap\TwentyI\Cli\fail;
use function SoftwareWrap\TwentyI\Cli\readLinesFromStdin;
use function SoftwareWrap\TwentyI\Cli\sanitizeApiError;
use function SoftwareWrap\TwentyI\Email\emitForwarderLine;
use function SoftwareWrap\TwentyI\Email\extractForwardersForDomain;
use function SoftwareWrap\TwentyI\Email\listForwarders;
use function SoftwareWrap\TwentyI\findPackageByDomain;
use function SoftwareWrap\TwentyI\getPackageId;
use function SoftwareWrap\TwentyI\getPackages;
use function SoftwareWrap\TwentyI\isValidDomain;
use function SoftwareWrap\TwentyI\normalizeDomain;

use const SoftwareWrap\TwentyI\Cli\EXIT_PARTIAL_FAILURE;
use const SoftwareWrap\TwentyI\Cli\EXIT_SUCCESS;

/**
 * Display usage information.
 */
function usage(int $exitCode = EXIT_SUCCESS): void
{
    $script = basename($_SERVER['argv'][0]);
    $stream = $exitCode === EXIT_SUCCESS ? STDOUT : STDERR;

    fwrite($stream, <<<EOT
Usage:
  {$script} <package-domain> [<package-domain> ...]
  {$script} < domains.txt

Read-only. No package or email state is modified.

Options:
  --help, -h  Display this help text.

Each domain must be attached to a hosting package visible to the API
key. The package list is fetched once per run and the forwarder list is
fetched once per package (GET /package/{id}/allMailForwarders), so
listing many domains on one package costs one forwarder request.

Output:
  One JSON object per domain on stdout (JSON Lines):
  {"domain":"example.com","ok":true,"packageId":"123",
   "forwarders":[{"id":42,"local":"info","remote":"team@example.net"}],
   "errors":{}}
  On failure, ok is false and "errors" carries the reason. A swallowed
  API 404 (the REST client returns null) is reported as a failure, never
  as an empty forwarder list. Progress is written to stderr.

Exit status:
  0  All domains answered
  1  Usage, validation, or configuration error
  3  One or more domains failed

EOT
    );

    exit($exitCode);
}

/*
 * Parse options and positional arguments. --help is handled before the
 * bootstrap loads so no API key is required to read the docs.
 */
$arguments = [];

for ($index = 1; $index < $argc; $index++) {
    $argument = $argv[$index];

    if ($argument === '--help' || $argument === '-h') {
        usage(EXIT_SUCCESS);
    }

    if (strpos($argument, '-') === 0) {
        fail("Unknown option '{$argument}'.");
    }

    $arguments[] = $argument;
}

if ($arguments === []) {
    $arguments = readLinesFromStdin();
}

if ($arguments === []) {
    fail('No domains were provided.');
}

$uniqueDomains = [];

foreach ($arguments as $domain) {
    $domain = normalizeDomain($domain);

    if (!isValidDomain($domain)) {
        fail("Invalid domain '{$domain}'.");
    }

    $uniqueDomains[$domain] = true;
}

$requestedDomains = array_keys($uniqueDomains);

try {
    require_once __DIR__ . '/../../lib/bootstrap.php';

    $servicesApi = new \TwentyI\API\Services($api_key);
    $packages = getPackages($servicesApi);

    $forwarderCache = [];
    $forwarderCacheError = [];
    $failureCount = 0;
    $position = 0;
    $total = count($requestedDomains);

    foreach ($requestedDomains as $domain) {
        $position++;

        fwrite(STDERR, "[{$position}/{$total}] {$domain} ... ");
        fflush(STDERR);

        $package = findPackageByDomain($packages, $domain);

        if ($package === null) {
            $failureCount++;
            fwrite(STDERR, "ERROR\n");

            emitForwarderLine([
                'domain' => $domain,
                'ok' => false,
                'packageId' => null,
                'forwarders' => [],
                'errors' => ['package' => "no package contains '{$domain}'"],
            ]);

            continue;
        }

        $packageId = getPackageId($package);

        /*
         * One allMailForwarders fetch per package; failures are cached
         * too so a broken package is not re-queried for every domain.
         */
        if (
            !isset($forwarderCache[$packageId])
            && !isset($forwarderCacheError[$packageId])
        ) {
            try {
                $forwarderCache[$packageId] =
                    listForwarders($servicesApi, $packageId);
            } catch (Throwable $exception) {
                $forwarderCacheError[$packageId] =
                    sanitizeApiError($exception);
                fwrite(
                    STDERR,
                    "\n[php] api detail: " . $exception->getMessage() . "\n"
                );
            }
        }

        if (isset($forwarderCacheError[$packageId])) {
            $failureCount++;
            fwrite(STDERR, "ERROR\n");

            emitForwarderLine([
                'domain' => $domain,
                'ok' => false,
                'packageId' => $packageId,
                'forwarders' => [],
                'errors' => ['api' => $forwarderCacheError[$packageId]],
            ]);

            continue;
        }

        $forwarders = extractForwardersForDomain(
            $forwarderCache[$packageId],
            $domain
        );

        fwrite(STDERR, 'OK (' . count($forwarders) . " forwarders)\n");

        $rowClean = emitForwarderLine([
            'domain' => $domain,
            'ok' => true,
            'packageId' => $packageId,
            'forwarders' => $forwarders,
            'errors' => (object) [],
        ]);

        if (!$rowClean) {
            fwrite(STDERR, "[warn] row degraded during encode\n");
            $failureCount++;
        }
    }

    fwrite(
        STDERR,
        'List complete: ' . ($total - $failureCount) . " ok, "
            . "{$failureCount} failed\n"
    );

    exit($failureCount === 0 ? EXIT_SUCCESS : EXIT_PARTIAL_FAILURE);
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
