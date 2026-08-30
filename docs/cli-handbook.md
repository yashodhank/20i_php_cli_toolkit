# 20i CLI Handbook

Operator, automation, and agent reference for this checkout. Everything below was read from the current scripts and libraries — no invented flags, endpoints, or record types are described.

Run every command from the repository root:

```bash
cd /Users/kritananda/Projects/20i_php_cli_toolkit
```

## Quick reference

| Task | Command | Exit 0 means |
|---|---|---|
| Is a domain on any package? | `php scripts/domain/exists.php <domain>` | attached |
| Dump a domain's DNS records | `php scripts/dns/dump-records.php <domain>` | all records retrieved |
| Attach one domain | `php scripts/domain/attach-domain-to-package.php <package-domain> <new-domain>` | added + verified |
| Attach many from a file | `php scripts/domain/attach-domain-to-package.php --skip --yes <package-domain> < domains.txt` | all added (see summary) |
| Add TXT to one domain | `php scripts/dns/add-records.php <domain> --name @ --type TXT --value "text" --dry-run` | safe to submit |
| Add TXT to a batch | `php scripts/dns/add-records.php --name _v --type TXT --value "tok" --skip --yes < domains.txt` | all accepted or protected |
| Delete a DNS record | `php scripts/dns/delete-records.php <domain> --name <n> --type TXT --value "v" --dry-run` | matched (dry-run) |
| Replace a TXT value | `php scripts/dns/replace-records.php <domain> --name <n> --type TXT --old-value "a" --new-value "b"` | replaced atomically |
| Detach a domain | `php scripts/domain/detach-domain-from-package.php <package-domain> <domain> --dry-run` | eligible (dry-run) |
| Move a domain between packages | `php scripts/domain/move-domain.php <src-pkg-domain> <dst-pkg-domain> <domain>` | moved + verified |
| List email forwards | `php scripts/email/list-forwards.php <package-domain>` | JSON line per domain |
| Create / delete / update a forward | `php scripts/email/{create,delete,update}-forward.php …` | done + re-list verified |

Golden rules:

- `--dry-run` first. No exceptions on a new command shape.
- `--yes` whenever the run is unattended **or** 10+ items may be eligible.
- `--skip` for "ensure this exists" jobs; without it, one published duplicate aborts everything.
- `--force` only overrides the 60-minute local resubmission journal. Never on a schedule.
- Trust exit codes, not log text. `1` fatal, `2` you cancelled, `3` partial failure, `0` fine even with pending/skipped items.

---

## 1. What this toolkit is

Reusable PHP CLIs for a 20i reseller account:

| Command | Path | Mutates? | Status |
|---|---|---|---|
| Domain attached? | `scripts/domain/exists.php` | No | Production |
| Attach domains to a package | `scripts/domain/attach-domain-to-package.php` | Yes | Production |
| Detach domains from a package | `scripts/domain/detach-domain-from-package.php` | Yes | Production |
| Move domains between packages | `scripts/domain/move-domain.php` | Yes | Production |
| Add TXT records | `scripts/dns/add-records.php` | Yes | Production |
| Delete DNS records | `scripts/dns/delete-records.php` | Yes | Production |
| Replace a TXT record | `scripts/dns/replace-records.php` | Yes | Production |
| Dump DNS records | `scripts/dns/dump-records.php` | No | Production |
| List email forwards | `scripts/email/list-forwards.php` | No | Production |
| Create email forwards | `scripts/email/create-forward.php` | Yes | Production (rewritten on `lib/bootstrap.php`, 2026-08) |
| Delete email forwards | `scripts/email/delete-forward.php` | Yes | Production |
| Update email forwards | `scripts/email/update-forward.php` | Yes | Production |

There is no daemon, installer, or remote deploy step. Installation is: clone, init submodule, write `.env`, run `php scripts/...`.

Related files:

- `README.md` — project overview
- `docs/setup.md` — older setup notes; **stale** (says Composer and `GENERAL_API_KEY`; the real contract is below)
- `scripts/dns/README.md` — DNS-only companion with the deep publication story
- `lib/cli.php`, `lib/config.php`, `lib/package.php`, `lib/dns.php` — source of truth

---

## 2. Local setup

### Requirements

- PHP 7.4+ (this machine runs 8.5.9 — see “Deprecations” below)
- Git
- A 20i reseller **general API key**
- Submodule `lib/20i-api-modules` (LunarDevelopment/20iRestModule)

Composer is **not** used by the current scripts.

### One-time

```bash
git submodule update --init --recursive
printf 'API_KEY=paste-only-the-general-api-key\n' > .env
```

`.env` lives at the **repo root**. `lib/config.php` loads it via `lib/env.php` and requires a non-empty `API_KEY`.

### Credential lifecycle

Every production script does the same thing:

1. `require __DIR__ . '/../../lib/bootstrap.php'`
2. `bootstrap.php` → `env.php` reads repo-root `.env` → `config.php` exposes `$api_key` (throws if empty) → loads the `TwentyI\API\Client` classes
3. Script builds `new \TwentyI\API\Services($api_key)` and calls `api.20i.com`

Consequences:

- `.env` is read from the repo root regardless of your cwd — you can run `php /full/path/scripts/...` from anywhere.
- The auth key is the **whole** bearer token; the library base64-encodes it into the `Authorization` header.
- Which key: only the **General API key** from <https://my.20i.com/reseller/api>. The **Auth client key** is for Stack control-panel SSO and is **not used** by this toolkit.

Do **not** concatenate the two keys. Combined values produce HTTP 401 `Invalid Authentication` on `https://api.20i.com/package`.

`.env` parser behavior (`lib/env.php`):

- First `=` splits the line. Quotes are **not** stripped — `API_KEY="abc"` is the 5-character token `"abc"` (with quotes) and will 401.
- Surrounding whitespace of key and value is trimmed.
- `#` comments and blank lines ignored.
- Missing `.env` is silent; missing `API_KEY` throws `API_KEY is not defined in .env.` on any script start.

Never print `.env` contents in logs, tickets, or agent transcripts.

### Deprecations on PHP 8.5

The vendored 20i client emits deprecation notices (`implicitly nullable $scopes`, `curl_close()`). They print to **stderr**, do not affect exit codes, and are not errors. Silence them locally with `error_reporting=E_ALL & ~E_DEPRECATED` if your log pipeline needs clean stderr.

### Smoke test (read-only)

```bash
php scripts/domain/exists.php --help
php scripts/domain/exists.php --verbose example.com
```

Exiting `0` with package details means the key works end to end (this account resolves 75 packages).

---

## 3. Shared CLI contract

Applies to every mutating command (`attach-domain-to-package.php`,
`detach-domain-from-package.php`, `move-domain.php`, `add-records.php`,
`delete-records.php`, `replace-records.php`, `create-forward.php`,
`delete-forward.php`, `update-forward.php`). `exists.php` has its **own** exit
table (§5); read-only JSON exporters (`dump-records.php`,
`list-forwards.php`) use the shared table without exit 2.

### Exit codes (`lib/cli.php`)

| Code | Constant | Meaning |
|---:|---|---|
| 0 | `EXIT_SUCCESS` | Work finished. Skips / pending DNS still exit 0. |
| 1 | `EXIT_ERROR` | Usage, validation, config, or fatal preflight stop. Nothing was mutated. |
| 2 | `EXIT_CANCELLED` | Operator declined the 10+ confirmation prompt. Nothing was mutated. |
| 3 | `EXIT_PARTIAL_FAILURE` | Batch ran; one or more items failed. Some mutations may have happened. |

### stdout vs stderr

| Stream | Content |
|---|---|
| stdout | Progress lines (`[2/11] example.com ... SUCCESS`), summaries, skip lists |
| stderr | `Error: ...` fatal/cancel messages, duplicate-blocks-run lists |

Do not discard stdout in automation: the per-item and summary lines are the machine-readable record which items changed. Parse `$?` first; on `3`, grep stdout for `-> ` (per-item failure map).

### stdin

`readLinesFromStdin()`:

- Trims each line, drops empty lines, drops lines starting with `#`
- Inline comments after a domain are **not** supported (a line `example.com # note` is treated as junk and will fail validation)

### Confirmation prompt

Batches of **10 or more eligible** items prompt unless `--yes`/`-y`.

- `confirm()` reads `/dev/tty`, not stdin — a redirected domain file does not break prompting
- No `/dev/tty` (cron, CI, most agent sandboxes) → throws and tells you to rerun with `--yes`
- Default answer is no; only `y` or `yes` (case-insensitive) continues
- A declined prompt is a clean exit 2 with zero mutations

### Domain rules (`lib/package.php`)

- `normalizeDomain()`: trim → strip trailing `.` → lowercase
- `isValidDomain()`: non-empty, ≤253 chars, must contain a `.`, `FILTER_VALIDATE_DOMAIN`
- Single-label names (`localhost`, `intranet`) rejected
- Package membership = exact match against the package's `names` array. A subdomain or sibling of an attached name is **not** treated as attached

---

## 4. Standard workflows

Apply the same four-step discipline to anything that writes:

```text
check  →  dry-run  →  run  →  re-check
```

### 4.1 Attach a sold/transferred domain to a package

```bash
php scripts/domain/exists.php --verbose newdomain.example            # expect exit 1
php scripts/domain/attach-domain-to-package.php --dry-run \
    park.example newdomain.example
php scripts/domain/attach-domain-to-package.php \
    park.example newdomain.example
php scripts/domain/exists.php --verbose newdomain.example            # expect exit 0
```

### 4.2 Ensure a TXT exists on a batch

```bash
php scripts/dns/add-records.php --name @ --type TXT \
    --value "This domain is for sale" --skip --dry-run < domains.txt
php scripts/dns/add-records.php --name @ --type TXT \
    --value "This domain is for sale" --skip --yes < domains.txt
```

Steady state on rerun: exit 0, `No DNS records need to be added.`

### 4.3 Add a verification TXT from a registrar/DV

```bash
php scripts/dns/add-records.php target.example \
    --name _dcv --type TXT --value "token-here"
```

One domain needs no `--yes`. If the token was already published, the run aborts (exit 1) — add `--skip` to tolerate it.

### 4.4 Apply one record everywhere on a package

```bash
php scripts/dns/add-records.php --all lowpricereseller.com \
    --name @ --type TXT --value "This domain is for sale" --skip --dry-run
php scripts/dns/add-records.php --all lowpricereseller.com \
    --name @ --type TXT --value "This domain is for sale" --skip --yes
```

---

## 5. `scripts/domain/exists.php`

Read-only: is this domain attached to any visible package?

```text
php scripts/domain/exists.php [--verbose] <domain>
```

### Options

| Option | Effect |
|---|---|
| `--verbose`, `-v` | Print domain, status, and on a hit the package id + names |
| `--help`, `-h` | Help to stdout, exit 0 |

Exactly one positional domain.

### Exit codes (distinct table)

| Code | Meaning |
|---:|---|
| 0 | Attached |
| 1 | Not attached to any visible package (**not an error**) |
| 2 | Usage, invalid domain, API/config error |

### Shell pattern

```bash
php scripts/domain/exists.php "$d"
case $? in
  0) ;;                                    # attached
  1) echo "missing: $d" >&2;  exit 1 ;;    # treat as data
  *) echo "lookup failed: $d" >&2; exit 2 ;;
esac
```

### Edges

| Input/state | Result |
|---|---|
| `EXAMPLE.COM.` | Normalized to `example.com` first |
| No arguments / two arguments | Usage + exit 2 |
| Unknown `--flag` | Exit 2 |
| Invalid domain | Exit 2, not 1 |
| API 401 / network failure | Exit 2 with `Error: ...` on stderr |
| `--verbose` + not found | Prints `Status: not attached`, still exit 1 |

Cost: one `GET /package` per call. Looping it across hundreds of domains hammers the same endpoint — for bulk checks, attach/add scripts do the fetch once internally.

---

## 6. `scripts/domain/attach-domain-to-package.php`

Adds domain names to an **existing** hosting package. Will not create packages.

```text
php scripts/domain/attach-domain-to-package.php [--dry-run] [--yes] [--skip] \
    <package-domain> <new-domain>

php scripts/domain/attach-domain-to-package.php [--dry-run] [--yes] [--skip] \
    <package-domain> < domains.txt
```

`<package-domain>` is **any** domain already on the target package — not a package id.

### Options

| Option | Effect |
|---|---|
| `--dry-run` | Classify, print `WOULD ADD`, POST nothing |
| `--yes`, `-y` | Skip confirm when ≥10 domains will be added |
| `--skip` | Skip names already on any package; report them at the end |
| `--help`, `-h` | Help |

### Processing

1. One `GET /package`; target resolved by `<package-domain>`
2. Each requested domain classified: unattached / on target / on another package
3. Without `--skip`, **any** name on another package aborts the whole run (exit 1, no writes)
4. Eligible domains POST one at a time: `/package/{id}/names` with `add=[domain]`, `rem=[]`, `chg=null`
5. Each add is re-verified with up to 3 `GET /package` probes at 1s intervals
6. Progress per item: `SUCCESS` / `ERROR: <message>`; final counts; skipped list last

### Edges

| Situation | Result |
|---|---|
| Selector not on any package | Exit 1, no writes |
| Empty stdin / no second positional | Exit 1 `No domains were provided.` |
| One bad domain in the list | Preflight exit 1 — **before** any POST |
| Duplicate lines in file | Deduped, order preserved |
| Already on target, no `--skip` | Skip-classified; does not abort; counted in skip list |
| Already on another package, no `--skip` | Whole run aborts, exit 1 |
| Same with `--skip` | Omitted; printed last as `domain -> package {id} ({selector})` |
| Every domain already attached | `No domains need to be added.`, exit 0 |
| Batch ≥10, no TTY, no `--yes` | Throws — use `--yes` |
| API OK but verification never confirms | That domain counts as failed → exit 3 if any such |
| Some succeed, some fail | Exit 3; failed map printed to stdout |

---

## 7. Email forward commands

The legacy `create-forward.php` (hardcoded key, vendor autoload, no shared
exit codes) was replaced in 2026-08 by a full email-forward suite on
`lib/bootstrap.php` + `lib/email.php`, following the §3 contract:

```text
php scripts/email/create-forward.php [--dry-run] [--yes] [--skip] <local@domain> <remote@dest>
php scripts/email/delete-forward.php [--dry-run] [--yes] [--skip] <local@domain> [<remote@dest>]
php scripts/email/update-forward.php [--dry-run] [--yes] <local@domain> <old-remote> <new-remote>
php scripts/email/list-forwards.php <package-domain> [...]        # read-only JSON Lines
# stdin batches supported on all of them
```

Key semantics (details in `scripts/email/README.md`):

- Forward identity is the server-assigned id from `allMailForwarders`;
  commands resolve by `local@domain` (+`remote` to disambiguate multiple
  destinations — ambiguity fails loudly, never guesses).
- Deletes/updates are verified by re-listing; a silently ignored API call
  surfaces as a per-item failure, never false success.
- Update creates the new destination first, then deletes the old; a failure
  in between leaves both active and reports `NEEDS MANUAL DELETE`.
- Catch-all and wildcard forwards are refused; mailboxes are out of scope.

---

## 8. `scripts/dns/add-records.php`

Adds **one TXT record** to one or more domains already attached to a 20i package. Additive only — for deletes see `delete-records.php` (§8b), for value replacement see `replace-records.php` (§8c). No other RR types on add, no wildcards.

```text
php scripts/dns/add-records.php [--dry-run] [--yes] [--skip] [--force] <domain> \
    --name <dns-name> --type TXT --value <string>

php scripts/dns/add-records.php [--dry-run] [--yes] [--skip] [--force] \
    --name <dns-name> --type TXT --value <string> < domains.txt

php scripts/dns/add-records.php [--dry-run] [--yes] [--skip] [--force] \
    --all <package-domain> --name <dns-name> --type TXT --value <string>
```

`--name`, `--type`, `--value` are required. `--type` must be `TXT` (case-insensitive).

### Options

| Option | Effect |
|---|---|
| `--name` | Owner name; `@` = zone apex (table below) |
| `--type TXT` | Only valid type |
| `--value` | Trimmed; empty after trim is fatal |
| `--all` | One positional **package** domain; apply to every name on it |
| `--dry-run` | Full preflight + `WOULD ADD`; no DNS POST |
| `--yes`, `-y` | Skip confirm when ≥10 **eligible** domains |
| `--skip` | Published identical TXT → omit instead of aborting |
| `--force` | Ignore the 60-minute local submission journal |
| `--help`, `-h` | Help |

`--force` never overrides an identical TXT already published in StackDNS — that is `--skip`'s territory. The two flags exist precisely because they disable different safeguards.

### Owner-name forms (zone `example.com`)

| `--name` | Relative host sent to 20i |
|---|---|
| `@` (or empty) | `@` |
| `example.com` / `example.com.` | `@` |
| `_verification` | `_verification` |
| `_verification.example.com(.`)| `_verification` |
| `_verification.example.net.` | **Rejected** — outside the target zone |
| `*.example.com` | **Rejected** — wildcards unsupported |

Labels: alnum/underscore edges, `-`/`_` interior, ≤63 chars per label, ≤253 total.

### Processing

1. `GET /package`; build targets (single, stdin, or `--all`)
2. Normalize `--name` **per target zone**
3. Preflight each domain:
   - attached to a visible package?
   - StackDNS (`ns1`–`ns4.stackdns.com`, direct UDP, TCP fallback, 5s timeout): identical TXT already published? → `EXISTS`
   - local journal: submitted within 60 minutes? → `RECENTLY SUBMITTED`
   - else `READY`
4. Without `--skip`, any `EXISTS` aborts before any mutation (exit 1)
5. Eligible domains POST `/package/{packageId}/dns/{domain}` with `conflictPolicy=reject`, `insertPolicy=append`
6. Accepted submissions are journaled for 60 minutes
7. One immediate StackDNS read per accepted item → `ACCEPTED; VERIFIED` or `ACCEPTED; PUBLICATION PENDING`

**`PUBLICATION PENDING` is success.** StackDNS can take 30+ minutes. It contributes nothing to the failure count. The companion read-only exporter is `scripts/dns/dump-records.php` (§8a).

### Submission journal - state and recovery

| Question | Answer |
|---|---|
| Path | `$XDG_STATE_HOME/20i-cli/dns-submissions.json`, else `$HOME/.local/state/20i-cli/dns-submissions.json`, else `%LOCALAPPDATA%`/`%APPDATA%`, else system temp |
| Format | JSON map; SHA-256 key of package id + domain + FQDN + TXT + value; `submittedAt` epoch |
| Permissions | Dir `0700`, file `0600`, atomic replace |
| Expiry | Entries older than 3600s are ignored on read |
| Clear | Delete the file. Only do this when you genuinely intend to repeat a recent submission (know why) |
| Shared? | Per host/user only — another machine, `--force`, or the 20i panel can still duplicate during the publication window |

Cron/CI on ephemeral disks: pin `XDG_STATE_HOME` to a persistent path or accept duplicate protection loss.

### Edges

| Situation | Result |
|---|---|
| Domain not on a visible package | Preflight `ERROR` per item; exit 3 at the end |
| `--all` selector unknown | Exit 1 |
| `--all` with ≠1 positional | Exit 1 |
| Two positionals, no `--all` | Usage, exit 1 |
| No positional + empty stdin | Exit 1 `No domains were provided.` |
| `--type A` etc. | Exit 1 before any network I/O |
| Published duplicate, no `--skip` | Exit 1, nothing written, list on stderr |
| Published duplicate, `--skip` | Omitted; other targets continue |
| Journal hit, no `--force` | `RECENTLY SUBMITTED`, protected, not submitted |
| Journal hit, `--force` | Eligible again (if StackDNS doesn't show it) |
| StackDNS inspect failure (timeout, all NS down) | Per-item inspection failure → exit 3 |
| All StackDNS queries fail for a domain | Same as above |
| API submit rejected (conflict) | Per-item failure; `conflictPolicy=reject` may do this even after a clean local preflight — e.g., a conflicting CNAME at that owner |
| Journal write fails after API accept | Warning printed; **do not rerun blindly** — 20i accepted it |
| `--dry-run` with inspect/unresolved errors | Exit 3 despite zero writes |
| Pending publication after successful accept | Exits 0 (or per other items) |

---

## 8a. `scripts/dns/dump-records.php` (read-only)

Dumps public DNS records for domains from two independent sources. Never mutates.

```text
php scripts/dns/dump-records.php [--source <api|dns|both>] [--types <list>] <domain> [<domain> ...]
php scripts/dns/dump-records.php [--source <api|dns|both>] [--types <list>] < domains.txt
php scripts/dns/dump-records.php [--source <api|dns|both>] [--types <list>] --all <package-domain>
```

| Option | Effect |
|---|---|
| `--source` | `api` (20i stored zone), `dns` (authoritative StackDNS), or `both` (default) |
| `--types` | Comma list. Default for `dns`: `A,AAAA,CNAME,MX,NS,SOA,TXT,SRV`. Without it the `api` source is **unfiltered** (true full-zone export); with it, both sources narrow |
| `--all` | Dump every domain on the package named by the positional domain; stdin ignored |
| `--help`, `-h` | Help |

**Sources:**

- **api** reads the stored zone via `GET /package/{packageId}/dns` and exports every record type the zone holds — SRV, wildcard (`*.example.com`, one label per RFC 4592), subhost entries, and types beyond the packet layer (CAA, DS, …) unless narrowed with `--types`. Subdomains attached to one package whose parent zone lives on another are resolved by walking ancestor names across packages. API records keep their raw fields under `fields`, including the per-record `ref` id that edit/delete operations key on. This is zone *config*: a just-submitted record appears here immediately even while StackDNS publication is still pending. Zone GETs answer only for zone roots; unrecognized response shapes fail loudly rather than reporting an empty zone.
- **dns** sends authoritative StackDNS queries (UDP with TCP fallback, the exact code path §8 preflight uses) for the requested `--types` only. This is the ground truth for "did it publish yet?"
- **both** merges them; every record carries a `source` tag.

Query names accept leading underscores (`_dmarc.example.com`, `_sip._tcp.example.com`) for TXT/SRV owner checks.

**Output contract:** one JSON object per domain on stdout (JSON Lines) — `{domain, ok, packageId, apiZone, sources:{api,dns}, records:[{owner,type,ttl,rdata,source,...}]}` with per-source failure messages under `errors`. All PHP diagnostics route to stderr, so stdout stays pure machine-readable JSON safe to pipe; API error messages are reduced to status + endpoint (full detail stays on stderr).

**Exit codes:** shared table — `0` every domain answered by at least one requested source, `3` at least one domain failed all of its sources (still emits `ok:false` lines), `1` usage/config.

Uses: local DNS inventories, pre-change audits, full-zone export (`--source api`), and post-publication verification ("is my TXT live yet") without touching the submission journal:

```bash
# Full stored zone, all types incl. SRV/wildcards:
php scripts/dns/dump-records.php --source api example.com

# Is my TXT live yet?
php scripts/dns/dump-records.php --source dns --types TXT example.com
```

---

## 8b. `scripts/dns/delete-records.php`

Deletes stored records matched by owner + type (+ optional value) via the
per-record `ref` id. Follows the full §3 contract (`--dry-run`, `--skip`,
`--force`, ≥10 confirm, stdin, `--all`).

```text
php scripts/dns/delete-records.php [flags] <domain> --name <n> --type <T> [--value <v>]
```

- `--type` ∈ A, AAAA, CNAME, MX, TXT, SRV. `--value` omitted deletes ALL
  records at that owner+type.
- Guards: SOA is never deletable; apex-NS deletion is refused outright.
- A dump-shaped JSON-Lines **snapshot** (incl. raw `fields.ref`) is written to
  the state dir (`…/20i-cli/snapshots/`) before each mutation; snapshot
  failure aborts that domain.
- Deletions are journaled 60 minutes like additions; post-change StackDNS
  check is advisory (`ACCEPTED; PUBLICATION PENDING` is success).

## 8c. `scripts/dns/replace-records.php`

Atomically replaces one TXT value (single POST carrying both the new record
and the old record's delete ref — no delete-then-add gap):

```text
php scripts/dns/replace-records.php [flags] <domain> --name <n> --type TXT --old-value <v> --new-value <v>
```

TXT-only in v1; requires exactly one matching record (zero or multiple
matches fail that item). Snapshots and journals like §8b. Details:
`scripts/dns/README.md`.

---

## 9. Admin runbook

| Incident | Diagnosis | Action |
|---|---|---|
| 401 invalid auth | Wrong or combined key in `.env` | General API key only; no quotes |
| Stuck after printing a domain | 10+ eligible, waiting at `/dev/tty` | Ctrl-C (clean 2), rerun with `--yes` or shrink the batch |
| Duplicates in control panel | `--force`, second host, or panel edit during publication window | `delete-records.php --dry-run` to preview, then delete the extras (or use the panel) |
| `RECENTLY SUBMITTED` forever on one machine | Journal still fresh (<60 min) | Wait, or investigate the journal file’s `submittedAt` |
| Batch exits 3 | Read stdout `Failed domains` map | Fix each item; rerun only the failures |
| `exists` returns 1 but you expected attached | Name might be a subdomain or typo | Confirm exact FQDN as attached in the panel |

---

## 10. Automation (cron, CI, shell)

### Contract

- Always `--yes` on unattended mutation runs (or keep eligible count <10)
- `--skip` on ensure-jobs
- No `--force` on schedules
- Dry-run in the same script before the live line, or in a prior pipeline stage
- Parse `$?`; stderr→log; stdout→log (needed for item-level failures)
- Pin cwd to the repo or use absolute script paths
- Export no shell variable for the key — `.env` is the only credential source these scripts read

### Ensure TXT (cron)

```bash
#!/bin/sh
set -eu
cd /Users/kritananda/Projects/20i_php_cli_toolkit

php scripts/dns/add-records.php \
    --name @ --type TXT --value "This domain is for sale" \
    --skip --dry-run < /var/lib/20i/sale-domains.txt >> /var/log/20i-txt.log 2>&1

php scripts/dns/add-records.php \
    --name @ --type TXT --value "This domain is for sale" \
    --skip --yes < /var/lib/20i/sale-domains.txt >> /var/log/20i-txt.log 2>&1
```

### Attach batch (CI)

```bash
set -e
php scripts/domain/attach-domain-to-package.php --dry-run --skip "$PACKAGE_DOMAIN" < domains.txt
php scripts/domain/attach-domain-to-package.php --yes --skip "$PACKAGE_DOMAIN" < domains.txt
```

A dry-run exit 3 still aborts the pipeline — inspection failures really are failures.

### Timeout guidance

`exists`: seconds. `attach` per domain: one POST + ≤3 probes ≈ up to ~4s worst case. `add-records` per domain: up to 5s StackDNS preflight (×2 if TC→TCP) + POST + up to 5s verify. Size job timeouts accordingly; a 500-domain `--all` run legitimately takes tens of minutes.

---

## 11. Contract for AI agents

### Allowed without asking

- Read this handbook, `scripts/*/README.md`, `--help` output
- Run `exists.php`, anything with `--dry-run`
- Show the operator exact commands

### Requires operator approval

- Any non-dry-run invocation of `attach-domain-to-package.php` or `add-records.php`
- Any `--force`

### Never

- Read, print, echo, or commit `.env` or key material — not even to “check quoting”
- Combine general + auth-client keys
- Claim non-TXT record ADDS, wildcard support, mailbox management, or package creation exist
- Delete/detach/move/update anything without a `--dry-run` first and operator approval
- Treat `exists.php` exit 1 as an error
- Treat DNS `ACCEPTED; PUBLICATION PENDING` as a failure
- Resubmit a DNS record within 60 minutes of acceptance to "see if it worked" — that defeats the journal
- Wrap these scripts in a retry-on-3 loop without reading the per-item failures

### Recommended sequence

```text
1. php scripts/<cmd>.php --help
2. php scripts/domain/exists.php --verbose <domain>
3. <cmd> --dry-run ...
4. Report the dry-run summary verbatim to the operator
5. <cmd> ... only after approval
```

### Error mapping for tool responses

| `$?` | Tell the operator |
|---:|---|
| 0 | Done; relay the summary counts |
| 1 | Fatal before mutation; quote the stderr `Error:` line |
| 2 | Cancelled at prompt; nothing changed |
| 3 | Partial; list each `domain -> message` from stdout |

### Agent prompt block

```text
You are operating the 20i PHP CLI in this repo.
Read docs/cli-handbook.md first. Use only documented flags.
Never read or echo .env. General API key only — never combined keys.
exists.php exit 1 = unattached, not a failure.
Dry-run before any mutation. Show the dry-run summary before running live.
DNS ACCEPTED; PUBLICATION PENDING is success — do not resubmit.
Deletes, detaches, moves and forward updates are destructive: dry-run + approval always.
Report exit codes verbatim.
```

---

## 12. Capability matrix

| Need | Command | Notes |
|---|---|---|
| Is a domain on a package? | `exists.php` | One `GET /package` per call |
| Attach names to an existing package | `attach-domain-to-package.php` | No package creation |
| Detach names from a package | `detach-domain-from-package.php` | Last-name + primary guards; zone snapshot first |
| Move names between packages | `move-domain.php` | add-target→verify→rem-source; mid-failure = on both |
| Add a TXT record | `add-records.php` | Apex or relative names; 1 record per invocation |
| Delete DNS records | `delete-records.php` | A/AAAA/CNAME/MX/TXT/SRV by owner+type(+value); §8b |
| Replace a TXT value | `replace-records.php` | Atomic single-POST; §8c |
| Dump zone / verify publication | `dump-records.php` | Read-only; api + authoritative dns sources |
| List email forwards | `list-forwards.php` | Read-only JSON Lines |
| Create/delete/update email forwards | `{create,delete,update}-forward.php` | §7; re-list verified |
| Non-TXT adds / mailboxes | — | Not implemented |
| List packages | — | `exists.php --verbose` per domain, or custom `GET /package` snippet |
| SSO / Stack users | — | Needs the auth client key; not in this toolkit |

---

## 13. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `API_KEY is not defined in .env.` | Missing file/key | Write repo-root `.env` |
| 401, `type: User ID` | Wrong key / quotes / combined keys | General key, unquoted |
| `Unable to open /dev/tty` | Unattended batch ≥10 | `--yes` |
| Exit 1 “identical record already exists” | Published TXT duplicate | `--skip`, or change the value |
| Everything `RECENTLY SUBMITTED` | Journal <60 min | Wait; `--force` only if duplicate intended |
| `not attached to any visible package` | Name not in any package `names` | Attach first; check spelling |
| Forward delete "accepted but still listed" | Wrong payload shape regression | Delete shape is flat `{"delete":["f<id>"]}` — see `lib/email.php` `buildDeleteForwardPayload()` |
| Dry-run exits 3 | Inspect/unresolved errors in set | Fix those domains; 3 ≠ crash |
| Exit 0 but `--value` "not visible" in dig | Publication window (30+ min) | Recheck later via another `--dry-run` |

---

## 14. Source map

| Behavior | File |
|---|---|
| `.env` / `API_KEY` | `lib/env.php`, `lib/config.php` |
| Shared exits, stdin, confirm | `lib/cli.php` |
| Package scan, domain matching, names add/rem, move guards | `lib/package.php` |
| Record validation, StackDNS queries, DNS payloads, ref matching, snapshots | `lib/dns.php` |
| API-zone record normalization (incl. `fields.ref`) | `lib/zone-records.php` |
| Email forward parsing, listing, payloads | `lib/email.php` |
| 20i HTTP client | `lib/20i-api-modules/lib/TwentyI/API/` |
