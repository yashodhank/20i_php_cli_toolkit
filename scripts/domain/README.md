# `scripts/domain`

## Overview

The `scripts/domain` directory contains command-line tools for managing
domains within a 20i reseller account. All mutating commands share the
toolkit conventions: `--dry-run`, `--yes`/`-y`, `--skip`, `--help`/`-h`,
stdin batches (blank lines and `#` comments ignored, duplicates removed
preserving order), per-item progress on stdout, and the shared exit codes
(`0` success, `1` fatal before mutation, `2` operator declined the batch
confirmation, `3` partial failure).

Every add and every removal of a package name goes through one function,
`SoftwareWrap\TwentyI\postPackageNames()` in `lib/package.php`
(`POST /package/{id}/names` with `{add, rem, chg}`), so the wire shape
lives in exactly one place.

## Current Commands

### `attach-domain-to-package.php`

Attaches one or more domains to an existing hosting package.

    attach-domain-to-package.php [--dry-run] [--yes] [--skip] <package-domain> <new-domain>
    attach-domain-to-package.php [--dry-run] [--yes] [--skip] <package-domain> < domains.txt

- The target package is identified by any domain already attached to it.
- The API silently skips names already mapped, so adds are idempotent.
- Without `--skip`, a domain attached to a different package aborts the
  run before any change.
- Each domain is submitted separately and verified with up to three
  `GET /package` probes at one-second intervals.

### `detach-domain-from-package.php`

Detaches one or more domains from the package they are attached to.

    detach-domain-from-package.php [--dry-run] [--yes] [--skip] <package-domain> <domain>
    detach-domain-from-package.php [--dry-run] [--yes] [--skip] <package-domain> < domains.txt

Preflight classification against one `GET /package` snapshot:

- **on the source package** — eligible for detachment;
- **not attached anywhere** — skipped with `--skip`, otherwise the run
  aborts before any change;
- **attached to another package** — skipped with `--skip`, otherwise the
  run aborts before any change.

Guards, enforced before the first mutation:

- **Last-name guard.** A package must never be left without names (the
  API forbids it). The guard is cumulative across the whole batch: if the
  requested removals would empty the package, the command exits `1`
  before any write.
- **Primary guard.** Removing the package's primary name (its first
  name) sends `chg` with the deterministic survivor — the first
  remaining name.

Safety net: detaching a domain destroys its 20i web-forwarding
configuration, so before each detach a best-effort snapshot of the
domain's stored DNS zone is written as one JSON Lines row (the
`dump-records.php` row shape, including raw `fields` with record refs)
to the state directory:

    $XDG_STATE_HOME/20i-cli/snapshots/<domain>-<utcstamp>.jsonl

(falling back to `~/.local/state/20i-cli/snapshots/`). The directory is
created `0700` and files are written `0600`. If the snapshot cannot be
written, the failure is reported loudly and the domain is only processed
after an explicit per-domain confirmation (or with `--yes`).

Each removal is submitted separately
(`{add: [], rem: [domain], chg: survivor|null}`) and verified absent with
up to three `GET /package` probes at one-second intervals.

### `move-domain.php`

Moves one or more domains from one hosting package to another.

    move-domain.php [--dry-run] [--yes] [--skip] <source-package-domain> <target-package-domain> <domain>
    move-domain.php [--dry-run] [--yes] [--skip] <source-package-domain> <target-package-domain> < domains.txt

The 20i API has no atomic cross-package move; each domain is moved with a
**frozen step ordering**:

1. add to the target package,
2. verify presence on the target,
3. remove from the source package,
4. verify absence from the source.

Failure semantics:

- A failure at step 1 changes nothing for that domain (clean per-item
  failure).
- A failure at any later step leaves the domain attached to **both**
  packages. The command reports
  `NEEDS MANUAL DETACH FROM SOURCE (package <id>)`, counts the domain
  toward exit code `3`, and **never** removes the domain from the target
  automatically.

Preflight classification (both packages resolved from one snapshot):
domains already on the target are always skipped (idempotent); domains on
a third package or not attached anywhere are skipped with `--skip` and
abort the run otherwise. A domain attached to both source and target (the
leftover of an interrupted move) is treated as eligible: re-running the
move completes the detachment, because the add step is idempotent.

The source package gets the same last-name and primary guards, and the
same best-effort pre-move zone snapshot, as
`detach-domain-from-package.php`.

### `exists.php`

Checks whether domains exist in DNS. See the script's `--help` for usage.

## Common CLI Conventions

-   Validate before mutation.
-   Keep business logic in `lib/` (`lib/package.php` for name mutation,
    guards, classification, and verification helpers — all offline-testable
    via `tests/domain/`).
-   Support batch operations with continuous per-item progress.
-   Summarize results, including skipped domains, at the end.

## Tests

Offline, no API key or network required:

    php tests/domain/package-helpers.php
    php tests/domain/move-ordering.php
    php tests/domain/snapshot.php

Each exits non-zero when any assertion fails.
