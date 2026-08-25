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
| Add TXT records | `scripts/dns/add-records.php` | Yes | Production |
| Create email forwards | `scripts/email/create-forward.php` | Yes | **Legacy — see §7. Do not automate.** |

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

Applies to `attach-domain-to-package.php` and `add-records.php`. `exists.php` has its **own** exit table (§5).

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

## 7. `scripts/email/create-forward.php` (legacy)

Do **not** use this in admin, cron, CI, or agent workflows:

- Requires `vendor/autoload.php` (no root Composer project exists)
- Hardcodes the API key inline and ignores `.env`
- No `--dry-run`, `--yes`, `--help`, no shared exit codes
- Refetches `GET /package` once per distinct source domain
- Skips invalid input lines but usually still exits 0

Its intended shape, if it is ever rewritten on `lib/bootstrap.php`:

```text
php scripts/email/create-forward.php user@example.com dest@elsewhere.example
# or stdin lines:  <from>@<domain> <to>
```

Until then, create forwards in the 20i control panel, or with a one-off snippet using `$api_key` from bootstrap.

---

## 8. `scripts/dns/add-records.php`

Adds **one TXT record** to one or more domains already attached to a 20i package. Additive only — no deletes, no replace, no other RR types, no wildcards.

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

Dumps public DNS records for domains from **authoritative StackDNS** (UDP with TCP fallback, the exact code path §8 preflight uses). Never mutates.

```text
php scripts/dns/dump-records.php [--types <list>] <domain> [<domain> ...]
php scripts/dns/dump-records.php [--types <list>] < domains.txt
php scripts/dns/dump-records.php [--types <list>] --all <package-domain>
```

| Option | Effect |
|---|---|
| `--types` | Comma list; default `A,AAAA,CNAME,MX,NS,SOA,TXT` |
| `--all` | Dump every domain on the package named by the positional domain |
| `--help`, `-h` | Help |

**Output contract:** one JSON object per domain on stdout (JSON Lines) — `{domain, ok, packageId, records:[{owner,type,ttl,rdata}]}` or `{"ok":false,"error":...}`. Progress goes to stderr, and the script suppresses PHP deprecation notices so stdout stays pure machine-readable JSON safe to pipe.

**Exit codes:** shared table — `0` every domain answered, `3` at least one domain failed (still emits `ok:false` lines), `1` usage/config.

Uses: local DNS inventories, pre-change audits, and post-publication verification ("is my TXT live yet") without touching the submission journal:

```bash
php scripts/dns/dump-records.php --types TXT example.com
```

---

## 9. Admin runbook

| Incident | Diagnosis | Action |
|---|---|---|
| 401 invalid auth | Wrong or combined key in `.env` | General API key only; no quotes |
| Stuck after printing a domain | 10+ eligible, waiting at `/dev/tty` | Ctrl-C (clean 2), rerun with `--yes` or shrink the batch |
| Duplicates in control panel | `--force`, second host, or panel edit during publication window | Remove extra records in the 20i panel; this CLI cannot delete |
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
- Claim other RR types, deletes, wildcard support, or package creation exist
- Run `create-forward.php`
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
Do not run scripts/email/create-forward.php.
Report exit codes verbatim.
```

---

## 12. Capability matrix

| Need | Command | Notes |
|---|---|---|
| Is a domain on a package? | `exists.php` | One `GET /package` per call |
| Attach names to an existing package | `attach-domain-to-package.php` | No package creation |
| Add a TXT record | `add-records.php` | Apex or relative names; 1 record per invocation |
| Other RR types / deletes / replace | — | Not implemented |
| List packages | — | `exists.php --verbose` per domain, or custom `GET /package` snippet |
| Email forwards | — | Panel only (§7) |
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
| `create-forward.php` autoload fatal | Legacy script | Don't use it (§7) |
| Dry-run exits 3 | Inspect/unresolved errors in set | Fix those domains; 3 ≠ crash |
| Exit 0 but `--value` "not visible" in dig | Publication window (30+ min) | Recheck later via another `--dry-run` |

---

## 14. Source map

| Behavior | File |
|---|---|
| `.env` / `API_KEY` | `lib/env.php`, `lib/config.php` |
| Shared exits, stdin, confirm | `lib/cli.php` |
| Package scan, domain matching | `lib/package.php` |
| TXT validation, StackDNS queries, payload | `lib/dns.php` |
| 20i HTTP client | `lib/20i-api-modules/lib/TwentyI/API/` |
