<?php
/**
 * Shared helpers for managing 20i email forwarders.
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

namespace SoftwareWrap\TwentyI\Email;

require_once __DIR__ . '/package.php';

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use TwentyI\API\Services;

use function SoftwareWrap\TwentyI\isValidDomain;
use function SoftwareWrap\TwentyI\normalizeDomain;
use function SoftwareWrap\TwentyI\responseToArray;

/*
 * Update state machine statuses (see runUpdateStateMachine()).
 */
const UPDATE_UPDATED = 'updated';
const UPDATE_CREATE_FAILED = 'create-failed';
const UPDATE_VERIFY_FAILED = 'verify-failed';
const UPDATE_NEEDS_MANUAL_DELETE = 'needs-manual-delete';

/**
 * Determine whether a forward subject local part denotes a catch-all.
 *
 * Catch-all forwards are out of scope for v1 and must be refused loudly.
 */
function isCatchAllSubject(string $local): bool
{
    return trim($local) === '';
}

/**
 * Determine whether a forward subject local part denotes a wildcard.
 *
 * Wildcard forwards are out of scope for v1 and must be refused loudly.
 */
function isWildcardSubject(string $local): bool
{
    return strpos($local, '*') !== false;
}

/**
 * Parse a forward subject of the form local@domain.
 *
 * The returned local part and domain are lowercased so that identical
 * subjects always compare equal. Catch-all (empty local) and wildcard (*)
 * subjects are refused: both are out of scope and mutating them by
 * accident would affect all mail for the domain.
 *
 * @return array{local:string,domain:string}
 * @throws InvalidArgumentException When the spec is not a usable subject.
 */
function parseForwardSpec(string $spec): array
{
    $spec = trim($spec);

    if ($spec === '') {
        throw new InvalidArgumentException('Empty forward spec.');
    }

    $separator = strrpos($spec, '@');

    if ($separator === false) {
        throw new InvalidArgumentException(
            "Invalid forward spec '{$spec}': expected local@domain."
        );
    }

    $local = substr($spec, 0, $separator);
    $domain = normalizeDomain(substr($spec, $separator + 1));

    if (isCatchAllSubject($local)) {
        throw new InvalidArgumentException(
            "Refusing catch-all subject '{$spec}': forwards with an empty "
            . 'local part affect all mail for the domain and are out of '
            . 'scope for this tool.'
        );
    }

    if (isWildcardSubject($local)) {
        throw new InvalidArgumentException(
            "Refusing wildcard subject '{$spec}': wildcard forwards are "
            . 'out of scope for this tool.'
        );
    }

    if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._%+-]*[A-Za-z0-9_%+-])?$/', $local) !== 1) {
        throw new InvalidArgumentException(
            "Invalid forward spec '{$spec}': the local part contains "
            . 'unsupported characters.'
        );
    }

    if (!isValidDomain($domain)) {
        throw new InvalidArgumentException(
            "Invalid forward spec '{$spec}': '{$domain}' is not a valid "
            . 'domain name.'
        );
    }

    return [
        'local' => strtolower($local),
        'domain' => $domain,
    ];
}

/**
 * Validate and normalize a forward destination address.
 *
 * The domain part is lowercased; the local part is preserved as given
 * (destination matching is done case-insensitively elsewhere). Wildcards
 * are refused everywhere, destinations included.
 *
 * @throws InvalidArgumentException When the address is not usable.
 */
function parseRemoteAddress(string $remote): string
{
    $remote = trim($remote);

    if ($remote === '') {
        throw new InvalidArgumentException('Empty destination address.');
    }

    if (strpos($remote, '*') !== false) {
        throw new InvalidArgumentException(
            "Refusing wildcard destination '{$remote}'."
        );
    }

    if (filter_var($remote, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException(
            "Invalid destination address '{$remote}'."
        );
    }

    $separator = strrpos($remote, '@');

    return substr($remote, 0, $separator + 1)
        . normalizeDomain(substr($remote, $separator + 1));
}

/**
 * Compare two email addresses or local parts case-insensitively.
 */
function sameAddress(string $left, string $right): bool
{
    return strtolower(trim($left)) === strtolower(trim($right));
}

/**
 * Build the payload that creates one email forward.
 *
 * Shape proven live by the legacy create-forward script:
 * POST /package/{packageId}/email/{domain}.
 *
 * @return array<string,mixed>
 */
function buildCreateForwardPayload(string $local, string $remote): array
{
    return [
        'new' => [
            'forward' => [
                'local' => $local,
                'remote' => $remote,
            ],
        ],
    ];
}

/**
 * Build the payload that deletes email forwards by server-assigned ID.
 *
 * CONFIRM-LIVE (contract docs/contracts/lifecycle-expansion-contract.md
 * section 2.3): unlike the create payload, this delete shape is INFERRED
 * by analogy with the DNS diff payload and the create payload; it has NOT
 * been proven against the live API. This function is intentionally the
 * only place in the codebase where the shape lives, so a live
 * confirmation (or correction) during integration verification changes
 * exactly one line of production code.
 *
 * Primary candidate (implemented below):
 *   {"delete": {"forward": [id, ...]}}
 *
 * Alternatives to try, in order, if the primary candidate is rejected or
 * silently ignored when confirmed against an operator-named test domain:
 *   1. {"delete": {"forward": [{"id": id}, ...]}}
 *   2. HTTP DELETE /package/{packageId}/email/{domain} with the payload
 *      above (the vendored client exposes deleteWithFields()).
 *
 * Every delete and update path flows through deleteForward(), which uses
 * this builder; callers verify by re-listing after the call, so a
 * silently ignored payload surfaces as a loud per-item failure rather
 * than a false success.
 *
 * @param array<int,int|string> $ids Server-assigned forwarder IDs.
 * @return array<string,mixed>
 * @throws InvalidArgumentException When no usable IDs were supplied.
 */
function buildDeleteForwardPayload(array $ids): array
{
    if ($ids === []) {
        throw new InvalidArgumentException(
            'Refusing to build a delete payload without forwarder IDs.'
        );
    }

    $cleanIds = [];

    foreach ($ids as $id) {
        if (!is_int($id) && !is_string($id)) {
            throw new InvalidArgumentException(
                'Forwarder IDs must be integers or strings.'
            );
        }

        if (is_string($id) && trim($id) === '') {
            throw new InvalidArgumentException(
                'Refusing an empty forwarder ID in a delete payload.'
            );
        }

        $cleanIds[] = $id;
    }

    return [
        'delete' => [
            'forward' => $cleanIds,
        ],
    ];
}

/**
 * Reject a swallowed 404 from the vendored REST client.
 *
 * The client converts HTTP 404 into a PHP notice and returns null
 * (REST.php). A null response therefore means "the endpoint or resource
 * was not found", never "the resource exists and is empty"; treating it
 * as an empty forwarder list would fabricate success.
 *
 * @param mixed $response Raw return value of getWithFields()/postWithFields().
 * @param string $context Human-readable description of the request.
 * @return array<mixed>
 * @throws RuntimeException When the response is null or not decodable.
 */
function assertApiResponse($response, string $context): array
{
    if ($response === null) {
        throw new RuntimeException(
            "The 20i API returned no data for {$context} (the REST client "
            . 'swallows HTTP 404 and returns null). Refusing to treat '
            . 'this as success.'
        );
    }

    return responseToArray($response);
}

/**
 * Fetch every mail forwarder on a package, keyed by domain.
 *
 * GET /package/{packageId}/allMailForwarders returns
 * {domain: [{id, local, remote}, ...], ...}.
 *
 * @return array<string,mixed>
 */
function listForwarders(Services $servicesApi, string $packageId): array
{
    $response = $servicesApi->getWithFields(
        '/package/' . rawurlencode($packageId) . '/allMailForwarders'
    );

    return assertApiResponse(
        $response,
        "allMailForwarders on package {$packageId}"
    );
}

/**
 * Extract the forwarder entries for one domain from an
 * allMailForwarders response, tolerating case/dot differences in keys.
 *
 * Entries missing local or remote are dropped (they cannot be matched
 * safely); entries missing an ID are kept with a null ID so that delete
 * paths can refuse them loudly instead of guessing.
 *
 * @param array<string,mixed> $allForwarders
 * @return array<int,array{id:int|string|null,local:string,remote:string}>
 */
function extractForwardersForDomain(array $allForwarders, string $domain): array
{
    $domain = normalizeDomain($domain);
    $entries = [];

    foreach ($allForwarders as $key => $value) {
        if (!is_string($key) || normalizeDomain($key) !== $domain) {
            continue;
        }

        if (!is_array($value)) {
            continue;
        }

        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $local = isset($entry['local']) ? $entry['local'] : null;
            $remote = isset($entry['remote']) ? $entry['remote'] : null;

            if (!is_string($local) || !is_string($remote)) {
                continue;
            }

            $id = null;

            if (isset($entry['id']) && (is_int($entry['id']) || is_string($entry['id']))) {
                $id = $entry['id'];
            }

            $entries[] = [
                'id' => $id,
                'local' => $local,
                'remote' => $remote,
            ];
        }
    }

    return $entries;
}

/**
 * Find forwarder entries matching a local part and, optionally, a
 * destination. Matching is case-insensitive on both sides.
 *
 * @param array<int,array{id:int|string|null,local:string,remote:string}> $forwarders
 * @return array<int,array{id:int|string|null,local:string,remote:string}>
 */
function findForwarders(array $forwarders, string $local, ?string $remote = null): array
{
    $matches = [];

    foreach ($forwarders as $forwarder) {
        if (!sameAddress($forwarder['local'], $local)) {
            continue;
        }

        if ($remote !== null && !sameAddress($forwarder['remote'], $remote)) {
            continue;
        }

        $matches[] = $forwarder;
    }

    return $matches;
}

/**
 * List the distinct destinations among a set of forwarder entries.
 *
 * @param array<int,array{id:int|string|null,local:string,remote:string}> $forwarders
 * @return array<int,string>
 */
function distinctRemotes(array $forwarders): array
{
    $remotes = [];

    foreach ($forwarders as $forwarder) {
        $key = strtolower($forwarder['remote']);

        if (!isset($remotes[$key])) {
            $remotes[$key] = $forwarder['remote'];
        }
    }

    return array_values($remotes);
}

/**
 * Extract the server-assigned IDs from forwarder entries, refusing any
 * entry without a usable ID rather than guessing.
 *
 * @param array<int,array{id:int|string|null,local:string,remote:string}> $forwarders
 * @return array<int,int|string>
 * @throws RuntimeException When any entry lacks an ID.
 */
function forwarderIds(array $forwarders): array
{
    $ids = [];

    foreach ($forwarders as $forwarder) {
        if ($forwarder['id'] === null) {
            throw new RuntimeException(
                "Forward '{$forwarder['local']}' -> '{$forwarder['remote']}'"
                . ' carries no server-assigned ID; refusing to delete '
                . 'without one.'
            );
        }

        $ids[] = $forwarder['id'];
    }

    return $ids;
}

/**
 * Create one email forward on a package domain.
 *
 * @throws RuntimeException When the API swallows a 404 for the route.
 */
function createForward(
    Services $servicesApi,
    string $packageId,
    string $domain,
    string $local,
    string $remote
): void {
    $response = $servicesApi->postWithFields(
        '/package/' . rawurlencode($packageId)
            . '/email/' . rawurlencode($domain),
        buildCreateForwardPayload($local, $remote)
    );

    if ($response === null) {
        throw new RuntimeException(
            "The 20i API returned no data creating the forward (a 404 on "
            . "the email route for '{$domain}' was swallowed by the REST "
            . 'client). The forward was NOT created.'
        );
    }
}

/**
 * Delete email forwards by server-assigned ID.
 *
 * This is the single mutation path for forward deletion; the payload
 * shape lives only in buildDeleteForwardPayload() (CONFIRM-LIVE gate).
 * Callers must verify by re-listing afterwards.
 *
 * @param array<int,int|string> $ids
 * @throws RuntimeException When the API swallows a 404 for the route.
 */
function deleteForward(
    Services $servicesApi,
    string $packageId,
    string $domain,
    array $ids
): void {
    $response = $servicesApi->postWithFields(
        '/package/' . rawurlencode($packageId)
            . '/email/' . rawurlencode($domain),
        buildDeleteForwardPayload($ids)
    );

    if ($response === null) {
        throw new RuntimeException(
            "The 20i API returned no data deleting the forward (a 404 on "
            . "the email route for '{$domain}' was swallowed by the REST "
            . 'client). The forward was NOT deleted.'
        );
    }
}

/**
 * Run the frozen update ordering: create new -> verify -> delete old.
 *
 * The ordering is contract-frozen (section 3, Lane Email): the new
 * destination is created first and verified before the old destination
 * is touched. A failure after a successful create leaves BOTH
 * destinations active; the old one is reported for manual deletion and
 * the new one is never rolled back.
 *
 * The steps are injected as callables so the machine is unit-testable
 * without a network:
 *   $createNew  - creates the new destination; throws on failure.
 *   $verifyNew  - returns true when the new destination is confirmed
 *                 present; may throw.
 *   $deleteOld  - deletes (and verifies deletion of) the old
 *                 destination; throws on failure.
 *
 * @return array{status:string,message:string}
 */
function runUpdateStateMachine(
    callable $createNew,
    callable $verifyNew,
    callable $deleteOld
): array {
    try {
        $createNew();
    } catch (Throwable $exception) {
        return [
            'status' => UPDATE_CREATE_FAILED,
            'message' => 'create failed, nothing changed: '
                . $exception->getMessage(),
        ];
    }

    try {
        $verified = $verifyNew();
    } catch (Throwable $exception) {
        $verified = false;
    }

    if ($verified !== true) {
        return [
            'status' => UPDATE_VERIFY_FAILED,
            'message' => 'the new destination could not be verified after '
                . 'the create call; the old destination was NOT deleted. '
                . 'Inspect the domain before retrying.',
        ];
    }

    try {
        $deleteOld();
    } catch (Throwable $exception) {
        return [
            'status' => UPDATE_NEEDS_MANUAL_DELETE,
            'message' => 'NEEDS MANUAL DELETE (old destination): the new '
                . 'destination was created and verified, but deleting the '
                . 'old destination failed: ' . $exception->getMessage(),
        ];
    }

    return [
        'status' => UPDATE_UPDATED,
        'message' => 'updated',
    ];
}

/**
 * Print one forwarder payload as a JSON Lines row on stdout.
 *
 * Mirrors the degradation strategy of Cli\emitDomainLine() with a
 * forwarder-shaped fallback: when the full payload cannot be encoded,
 * the forwarders are dropped, an "encode" error is recorded, and the
 * domain/ok/packageId fields survive so the row never vanishes and its
 * ok state stays truthful.
 *
 * @param array<string,mixed> $payload
 * @return bool True when the full payload encoded cleanly.
 */
function emitForwarderLine(array $payload): bool
{
    $encoded = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($encoded !== false) {
        echo $encoded . "\n";

        return true;
    }

    $degraded = [
        'domain' => is_string($payload['domain'] ?? null) ? $payload['domain'] : null,
        'ok' => (bool) ($payload['ok'] ?? false),
        'packageId' => $payload['packageId'] ?? null,
        'forwarders' => [],
        'errors' => ['encode' => 'forwarder data could not be JSON-encoded'],
    ];

    foreach ((array) ($payload['errors'] ?? []) as $key => $message) {
        $degraded['errors'][$key] = $message;
    }

    $encoded = json_encode($degraded, JSON_UNESCAPED_SLASHES);

    echo ($encoded === false ? '{"domain":null,"ok":false}' : $encoded) . "\n";

    return false;
}
