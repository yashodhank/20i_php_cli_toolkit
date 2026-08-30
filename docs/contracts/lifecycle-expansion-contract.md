# Frozen Contract: Lifecycle Expansion (DNS / Domain / Email)

Status: FROZEN 2026-08-30. Implementation lanes build against this document.
Deviations require an orchestrator-approved contract amendment committed to
`main` before the deviating code merges.

Three parallel lanes deliver, against this contract:

- **Lane DNS** — delete and replace DNS records (today: add-only).
- **Lane Domain** — detach domains from packages and move them between packages.
- **Lane Email** — list/delete/update email forwards and rewrite the legacy
  `create-forward.php` onto the shared bootstrap.

## 1. Shared conventions (all lanes, non-negotiable)

Source of truth: `docs/cli-handbook.md` §3 and the existing production scripts.

- PHP **7.4 compatible** (`declare(strict_types=1);`). No PHP 8-only syntax
  (no `match`, enums, named arguments, readonly, constructor promotion,
  nullsafe `?->`, `str_contains`). Local runtime is 8.5; the floor is 7.4.
- Exit codes from `lib/cli.php`: `0` success (skips/pending included),
  `1` fatal before mutation, `2` operator declined confirm, `3` partial
  failure. Read-only listers use `0/1/3` like `dump-records.php`.
- Flags: `--dry-run` (full preflight, `WOULD …` lines, zero mutation),
  `--yes`/`-y` (bypass the ≥10-eligible `/dev/tty` confirm), `--skip`
  (tolerate the documented per-command skippable condition), `--help`/`-h`.
- Hand-rolled `$argv` loop parsing, `usage()` heredoc, `fail()` to stderr —
  mirror `scripts/domain/attach-domain-to-package.php`.
- stdin batch via `readLinesFromStdin()`; dedupe preserving order.
- stdout = per-item progress (`[i/n] item ... STATUS`, `fflush` before network)
  + summary + skip list; stderr = `Error:` lines and API detail
  (`sanitizeApiError()` for the machine stream).
- Path segments always `rawurlencode()`d. GPL header block as in existing files.
- Namespaces: `SoftwareWrap\TwentyI` (package), `SoftwareWrap\TwentyI\Dns`
  (dns), `SoftwareWrap\TwentyI\Email` (new), `SoftwareWrap\TwentyI\Cli`.
- Mutating scripts print nothing secret, never read `.env` directly
  (bootstrap only), and never batch multiple subjects into one API call when
  per-item progress/rollback is possible.

## 2. Endpoint contracts (verified status per route)

### 2.1 DNS

| Op | Route | Status |
|---|---|---|
| Read zone map | `GET /package/{packageId}/dns` | proven (dump-records) |
| Read one zone | `GET /package/{packageId}/dns/{zone}` | proven; zone roots only, 404 on non-roots |
| Write diff | `POST /package/{packageId}/dns/{domain}` | proven for add (add-records) |

Write body (one atomic diff):
`{"conflictPolicy":"reject"|"replace"|"ignore", "insertPolicy":"append"|"replace", "new":{TYPE:[record…]}, "delete":[refString…]}`

**Record identity: `fields.ref` — VERIFIED LIVE 2026-08-30** (package 716033):
every record carries a numeric `ref` except SOA (`ref` null); refs stable
across calls (identical digest twice). Delete refs are sent as strings.
No `id` key exists. `reuseId` on CNAME `new` entries is the only in-place
edit primitive; do not use it in v1.

### 2.2 Package names

| Op | Route | Status |
|---|---|---|
| List names | `GET /package/{packageId}/names` | apib-documented |
| Add/remove | `POST /package/{packageId}/names` `{add:[…], rem:[…], chg:string|null}` | proven for add (attach script) |

Constraints (apib 1805–1902): `add` silently skips already-mapped names
(idempotent). Removing a package's **last** name is forbidden. `chg` must name
a surviving name when the primary is removed. Removal destroys the domain's
web-forwarding config. **No atomic cross-package move exists** — move is
composed client-side.

### 2.3 Email forwards

| Op | Route | Status |
|---|---|---|
| List all forwards | `GET /package/{packageId}/allMailForwarders` → `{domain:[{id,local,remote}]}` | VERIFIED LIVE (empty arrays; shape per apib 8309) |
| Per-domain config | `GET /package/{packageId}/email/{domain}` | VERIFIED LIVE, bare-domain path accepted |
| Mutate | `POST /package/{packageId}/email/{domain}` | create proven (`{"new":{"forward":{"local":…,"remote":…}}}`); delete **LIVE-CONFIRMED**: `{"delete":["f<id>",…]}` |

Bare domain **VERIFIED** as the `{emailId}` segment for all email GETs
(2026-08-30, package 786795). Forward identity = server-assigned `id` from
`allMailForwarders`, disambiguated by `local`+`remote`.

**CONFIRM-LIVE gate: RESOLVED 2026-08-30** (operator-authorized smoke on
rays.im/package 906553): the delete payload is a FLAT array of
type-prefixed server IDs — `{"delete":["f8282897"]}` deleted the forward;
response `{"result":{"result":[],"name":"rays.im"}}`. The originally
inferred nested shape `{"delete":{"forward":[id…]}}` is silently accepted
and IGNORED by the API. The shape lives only in
`buildDeleteForwardPayload()` (`lib/email.php`).
Mailboxes, catch-all and wildcard forwards are **out of scope v1**; commands
must detect and refuse them loudly.

## 3. Lane deliverables and file ownership

A lane may READ anything but WRITE only its owned files. No lane touches:
`docs/cli-handbook.md`, root `README.md`, `lib/cli.php`, `lib/bootstrap.php`,
`lib/config.php`, `lib/env.php`, another lane's files, or this contract.
Needed shared helpers are implemented in the lane's own lib file; the
orchestrator consolidates at integration.

### Lane DNS — branch `lane-dns`
Owns: `lib/dns.php`, `lib/zone-records.php`, `scripts/dns/delete-records.php`
(new), `scripts/dns/replace-records.php` (new), `scripts/dns/README.md`,
`tests/dns/`.

- `delete-records.php [--dry-run] [--yes] [--skip] [--force] <domain>|stdin|--all <pkg-domain> --name <n> --type <T> [--value <v>]`
  - `--type` ∈ {A, AAAA, CNAME, MX, TXT, SRV}. Match records by normalized
    owner + type + (optional) rdata value; collect `fields.ref`; one POST per
    domain with `{new:{}, delete:[refs]}`.
  - `--value` omitted ⇒ delete ALL records of that owner+type. Zero matches:
    error per item (exit 3) unless `--skip` (classified skip).
  - Guards: never delete SOA; refuse NS deletion at the zone apex entirely.
  - **Mandatory pre-change snapshot** per mutated domain (dump-records-shaped
    JSON Lines incl. raw `fields`) to the state dir
    (`$XDG_STATE_HOME/20i-cli/snapshots/<domain>-<utcstamp>.jsonl`), written
    before the POST; snapshot failure aborts that domain's mutation.
  - Journal deletions 60 min (same mechanism/keying style as add; `--force`
    bypasses journal only).
  - Post-change StackDNS check is advisory: `ACCEPTED; VERIFIED` /
    `ACCEPTED; PUBLICATION PENDING` (pending is success).
- `replace-records.php [--dry-run] [--yes] [--force] <domain>|stdin --name <n> --type TXT --old-value <v> --new-value <v>`
  - v1 TXT only. One atomic POST: `{new:{TXT:[new]}, delete:[matchedRef]}`.
    Exactly-one-match required; zero or multiple matches = per-item error.
- Lift `normalizeRecordNameForDomain()` into `lib/dns.php`; add
  `extractRecordRef()`, `findMatchingApiRecords()`, `buildDeletePayload()`,
  `buildReplacePayload()` (pure, unit-testable).

### Lane Domain — branch `lane-domain`
Owns: `lib/package.php`, `scripts/domain/detach-domain-from-package.php`
(new), `scripts/domain/move-domain.php` (new), `scripts/domain/README.md`
(new), `tests/domain/`.

- `detach-domain-from-package.php [--dry-run] [--yes] [--skip] <package-domain> [<domain>]` (+stdin)
  - Classify against one `GET /package` snapshot: on-source (eligible),
    not-attached (skip w/ `--skip`, else error), on-other-package (abort
    without `--skip`).
  - **Last-name guard**: computed against cumulative batch removals per
    package; would-be-empty package aborts with exit 1 before any write.
  - **Primary guard**: removing the primary name sets `chg` to a surviving
    name (first remaining, deterministic).
  - Best-effort zone snapshot via existing `lib/zone-records.php` read path to
    the state dir before each detach; on snapshot failure print a loud warning
    and require the item be confirmed (or `--yes`).
  - Mutation: `{add:[], rem:[domain], chg:survivor|null}`, one per POST;
    verify absence with ≤3 `GET /package` probes at 1s.
- `move-domain.php [--dry-run] [--yes] [--skip] <source-package-domain> <target-package-domain> [<domain>]` (+stdin)
  - Preflight both packages from one snapshot; already-on-target = idempotent
    skip; on-third-package aborts without `--skip`; last-name/primary guards
    on source.
  - **Ordering frozen: add-to-target first, verify, then rem-from-source,
    verify.** Failure after a successful add leaves the domain on BOTH —
    report `NEEDS MANUAL DETACH FROM SOURCE (package {id})`, count toward
    exit 3, never auto-remove from target. Failure at add = clean per-item
    failure, nothing changed.
- New helpers per discovery: `getPackageNames()`, `addNamesToPackage()`,
  `removeNameFromPackage()`, `packageWouldBeEmptyAfterRemoval()`,
  `pickPrimaryAfterRemoval()`, `verifyDomainAbsentFromPackage()`.

### Lane Email — branch `lane-email`
Owns: `lib/email.php` (new), `scripts/email/create-forward.php` (rewrite),
`scripts/email/list-forwards.php` (new), `scripts/email/delete-forward.php`
(new), `scripts/email/update-forward.php` (new), `scripts/email/README.md`,
`tests/email/`.

- `lib/email.php` (`SoftwareWrap\TwentyI\Email`): `parseForwardSpec()`,
  `listForwarders()`, `findForwarders()`, `createForward()`,
  `deleteForward()`, `buildCreateForwardPayload()`,
  `buildDeleteForwardPayload()` (CONFIRM-LIVE, see §2.3), pure helpers
  unit-testable without network.
- `create-forward.php` rewrite: bootstrap + `.env`, shared exit codes,
  `[--dry-run] [--yes] [--skip] <local@domain> <remote@dest>` (+stdin lines
  `<from> <to>`), one `GET /package` snapshot for all source domains,
  idempotency: identical forward already present ⇒ skip w/ `--skip`, else
  per-item error. Deletes the legacy vendor/autoload + hardcoded-key code.
- `list-forwards.php <package-domain> […]` (+stdin): read-only JSON Lines
  `{domain, ok, packageId, forwarders:[{id,local,remote}], errors}`, stderr
  shim as in dump-records (the REST client's 404→notice hazard), exits 0/1/3.
- `delete-forward.php [--dry-run] [--yes] [--skip] <local@domain> [<remote@dest>]` (+stdin)
  - Resolve by list→match; `remote` required when the source has multiple
    destinations (ambiguity = loud per-item failure, never guess).
  - Not-found: skip with `--skip`, else per-item error. Refuse catch-all and
    wildcard subjects.
- `update-forward.php [--dry-run] [--yes] <local@domain> <old-remote> <new-remote>` (+stdin)
  - **Ordering frozen: create new first, verify, then delete old.** Failure
    after create leaves both destinations — report
    `NEEDS MANUAL DELETE (old destination)`, exit 3, never delete the new one.
  - New already present and old absent ⇒ `UNCHANGED`, success.

## 4. Tests (every lane)

- Pure-PHP test files under `tests/<lane>/`, zero network, zero `.env`
  dependency, self-running: `php tests/<lane>/<file>.php` exits nonzero on
  failure with a per-assertion message. Cover: payload builders, matchers,
  classifiers, guard logic (last-name/primary/apex-NS/ambiguity), spec
  parsing, ordering state machines (simulated failure injection).
- Every new/changed PHP file passes `php -l`.
- `--help` for every script must exit 0 and document every flag above.

## 5. Lane completion definition

Commit granularly on the lane branch. Final lane state: all tests pass,
`php -l` clean, README for the lane's script directory updated, no writes
outside owned files, working tree clean. Report: files changed, test command
+ exit codes, any contract friction encountered (do NOT self-amend the
contract).
