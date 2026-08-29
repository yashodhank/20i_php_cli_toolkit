# `scripts/email`

## Overview

The `scripts/email` directory contains command-line tools for managing
20i email forwarders. All commands run on the shared bootstrap
(`lib/bootstrap.php` + `.env`), use the shared exit codes from
`lib/cli.php`, and route every payload through the pure helpers in
`lib/email.php`.

Shared behavior:

- **Exit codes:** `0` success (including skips), `1` fatal error before
  any mutation, `2` operator declined the batch confirmation, `3` one or
  more items failed. The read-only lister uses `0/1/3`.
- **Flags:** `--dry-run` (full preflight, `WOULD ...` lines, zero
  mutation), `--yes`/`-y` (bypass the confirmation prompt shown for 10+
  eligible items), `--skip` (tolerate the documented per-command
  skippable condition), `--help`/`-h` (works without an API key).
- **Batch input:** every command accepts stdin lines; blank lines and
  `#` comments are ignored; duplicates are removed preserving order.
- **Efficiency:** one `GET /package` snapshot per run and one
  `GET /package/{id}/allMailForwarders` per package.
- **Out of scope (refused loudly):** catch-all forwards (empty local
  part), wildcard subjects (`*`), and mailboxes. These tools never touch
  mailbox configuration.

## Commands

### `create-forward.php`

```
create-forward.php [--dry-run] [--yes] [--skip] <local@domain> <remote@dest>
create-forward.php [--dry-run] [--yes] [--skip] < forwards.txt   # lines: <from> <to>
```

Creates email forwards. Idempotent with `--skip`: an identical existing
forward is skipped; without `--skip` it is a per-item failure. The
create payload (`{"new":{"forward":{"local":...,"remote":...}}}` POSTed
to `/package/{id}/email/{domain}`) is proven live.

### `list-forwards.php`

```
list-forwards.php <package-domain> [<package-domain> ...]
list-forwards.php < domains.txt
```

Read-only. Prints one JSON object per domain (JSON Lines) on stdout:

```json
{"domain":"example.com","ok":true,"packageId":"123",
 "forwarders":[{"id":42,"local":"info","remote":"team@example.net"}],
 "errors":{}}
```

Progress goes to stderr; PHP notices from the vendored REST client are
routed to stderr so the JSON stream stays clean. A swallowed API 404
(the client returns `null`) is reported as a failure — never as an
empty forwarder list.

### `delete-forward.php`

```
delete-forward.php [--dry-run] [--yes] [--skip] <local@domain> [<remote@dest>]
delete-forward.php [--dry-run] [--yes] [--skip] < forwards.txt
```

Deletes forwards, resolving them by listing the package's forwarders
and matching on the local part (plus destination when given). If a
source forwards to several destinations, the destination argument is
required — the item fails loudly rather than guessing. Missing forwards
are per-item failures unless `--skip`. Every delete is verified by
re-listing; an accepted-but-unapplied delete is a failure.

### `update-forward.php`

```
update-forward.php [--dry-run] [--yes] <local@domain> <old-remote> <new-remote>
update-forward.php [--dry-run] [--yes] < updates.txt
```

Changes a forward's destination with a frozen safety ordering:

1. create the new destination,
2. verify it by re-listing,
3. delete the old destination (and verify the deletion).

A failure after a successful create leaves **both** destinations active;
the item is reported as `NEEDS MANUAL DELETE (old destination)` and the
run exits `3`. The new destination is never rolled back. If the new
destination already exists and the old one is already gone, the item is
`UNCHANGED` and counts as success.

## CONFIRM-LIVE gate

The delete payload shape is inferred, not yet proven live (contract
`docs/contracts/lifecycle-expansion-contract.md` §2.3). It lives in
exactly one function — `buildDeleteForwardPayload()` in `lib/email.php`
— with the candidate shapes documented inline, and must be confirmed
against an operator-named test domain during integration verification
before merge. Because every delete is verified by re-listing, a wrong
payload surfaces as a loud failure, not silent success.

## Tests

Offline, no network, no `.env`:

```
php tests/email/forward-probes.php
php tests/email/update-state-machine-probes.php
```

## Design Goals

- Safe automation (verify-after-write, frozen orderings, loud refusals)
- Deterministic CLI behavior and shared exit codes
- Minimal dependencies
- Reusable libraries (`lib/email.php`)

## Future Work

- Mailbox management (explicitly out of scope for the forward tools)
- Live confirmation and, if needed, correction of the delete payload
