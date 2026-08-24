# 20i CLI Handbook

Local operator, automation, and agent reference for this checkout. Behavior below is taken from the scripts and libraries as they exist now. Do not invent flags, endpoints, or record types.

Run every command from the repository root:

```bash
cd /Users/kritananda/Projects/20i_php_cli_toolkit
```

Related files:

- `README.md` — project overview
- `docs/setup.md` — older setup notes; prefer this handbook for credentials
- `scripts/dns/README.md` — DNS-only companion
- `lib/cli.php`, `lib/config.php`, `lib/package.php`, `lib/dns.php` — source of truth

---

## 1. What this toolkit is

Reusable PHP CLIs for a 20i reseller account:

| Command | Path | Mutates? | Production-ready? |
|---|---|---|---|
| Domain attached? | `scripts/domain/exists.php` | No | Yes |
| Attach domains to a package | `scripts/domain/attach-domain-to-package.php` | Yes | Yes |
| Add TXT records | `scripts/dns/add-records.php` | Yes | Yes |
| Create email forwards | `scripts/email/create-forward.php` | Yes | **No** — legacy; see §7 |

There is no daemon, installer, or remote deploy step. Installation is: clone, init submodule, write `.env`, run `php scripts/...`.

---

## 2. Local setup

### Requirements

- PHP 7.4+ (this machine has 8.5.9)
- Git
- A 20i reseller **general API key**
- Submodule `lib/20i-api-modules` (LunarDevelopment/20iRestModule)

Composer is **not** used by the modern scripts. `docs/setup.md` mentioning Composer and `GENERAL_API_KEY` is stale.

### One-time

```bash
git submodule update --init --recursive
printf 'API_KEY=paste-only-the-general-api-key\n' > .env
```

`.env` lives at the repo root. `lib/config.php` loads it via `lib/env.php` and requires a non-empty `API_KEY`.

### Credential rules

| Key on my.20i.com/reseller/api | Use here |
|---|---|
| General API key | `API_KEY=` in `.env`. Only this. |
| Auth client key | Unused by these CLIs. Stack CP login / SSO only. |

Do **not** concatenate the two keys. Combined values produce HTTP 401 `Invalid Authentication` on `https://api.20i.com/package`.

`.env` parser notes (`lib/env.php`):

- First `=` splits the line. Quotes are **not** stripped.
- Surrounding whitespace on the key and value is trimmed.
- `#` comments and blank lines are ignored.
- Missing `.env` is silent; missing `API_KEY` throws `API_KEY is not defined in .env.`

Keep `.env` out of git. Do not print it in logs, tickets, or agent transcripts.

### Smoke test (read-only)

```bash
php scripts/domain/exists.php --help
php scripts/domain/exists.php --verbose example.com
```

A live `GET /package` that succeeds means the key is valid. This account previously resolved **75** packages with the general key.

PHP 8.5 may print deprecations from the vendored 20i client (`implicitly nullable $scopes`, `curl_close()`). They are submodule warnings, not auth failures.

---

## 3. Shared CLI contract

Applies to `attach-domain-to-package.php` and `add-records.php`. `exists.php` uses a **different** exit table (see §5).

### Exit codes (`lib/cli.php`)

| Code | Constant | Meaning |
|---:|---|---|
| 0 | `EXIT_SUCCESS` | Requested work finished. Skips / pending DNS can still be 0. |
| 1 | `EXIT_ERROR` | Usage, validation, config, or fatal preflight stop. |
| 2 | `EXIT_CANCELLED` | Operator declined the 10+ confirmation prompt. |
| 3 | `EXIT_PARTIAL_FAILURE` | Batch ran; one or more items failed. |

Errors go to stderr as `Error: ...`. Progress and summaries go to stdout.

### stdin

`SoftwareWrap\TwentyI\Cli\readLinesFromStdin()`:

- Trims each line
- Drops empty lines
- Drops lines whose first non-whitespace character is `#`
- Does **not** support inline comments after a domain

### Confirmation

Batches of **10 or more eligible** items prompt unless `--yes` / `-y`.

`confirm()` reads `/dev/tty`, not stdin, so a redirected domain list can still prompt. If `/dev/tty` cannot be opened (cron, many CI jobs, some agent sandboxes), the command throws and tells you to rerun with `--yes`.

Default answer is no. Only `y` or `yes` (case-insensitive) continues.

### Domain rules (`lib/package.php`)

- `normalizeDomain()`: trim, strip a trailing `.`, lowercase
- `isValidDomain()`: non-empty, ≤253 chars, must contain `.`, `FILTER_VALIDATE_DOMAIN` + `FILTER_FLAG_HOSTNAME`
- Single-label names (`localhost`, `intranet`) are rejected
- Package membership is an **exact** match against `package.names`, not parent-zone inference
- A domain not on any package visible to this API key is “unattached”

### Working directory

Scripts bootstrap `lib/` with paths relative to `__DIR__`. Invoke them as `php scripts/...` from any cwd **or** with an absolute script path. `.env` is always loaded from the repo root next to `lib/`, not from the current working directory.

---

## 4. Safety model for mutations

Use this order for any write:

1. `--help`
2. `--dry-run` on one target
3. Live run on one controlled target
4. Small batch with `--yes` only after dry-run looks right
5. `--all` / large stdin only after that

`--dry-run` resolves packages and prints `WOULD ADD`. It does not POST mutations.

`--skip` changes fail-fast preflight into “omit already-done items and continue”.

`--yes` is for non-interactive runs of 10+ eligible items. It is not a substitute for `--dry-run`.

---

## 5. `scripts/domain/exists.php`

Read-only: is this domain attached to any visible package?

```text
php scripts/domain/exists.php [--verbose] <domain>
```

### Options

| Option | Effect |
|---|---|
| `--verbose`, `-v` | Print domain, status, and on hit package id + names |
| `--help`, `-h` | Help on stdout, exit 0 |

Exactly one positional domain is required.

### Exit codes (not the shared table)

| Code | Meaning |
|---:|---|
| 0 | Attached |
| 1 | Not attached to any visible package |
| 2 | Usage, invalid domain, API, or other operational error |

Default success/failure is silent. Automations should branch on `$?`, not stdout.

```bash
if php scripts/domain/exists.php example.com; then
  echo attached
else
  status=$?
  if [ "$status" -eq 1 ]; then echo missing; else echo failed:"$status"; fi
fi
```

### Edges

- Invalid domain → exit 2, not 1
- Unknown option → exit 2
- Extra positionals → usage, exit 2
- API 401 / network error → exit 2
- Verbose not-found still exits 1 after printing `Status: not attached`

---

## 6. `scripts/domain/attach-domain-to-package.php`

Adds domain names to an existing hosting package.

```text
php scripts/domain/attach-domain-to-package.php [--dry-run] [--yes] [--skip] \
    <package-domain> <new-domain>

php scripts/domain/attach-domain-to-package.php [--dry-run] [--yes] [--skip] \
    <package-domain> < domains.txt
```

The first positional name is **any domain already on the target package**, not a package numeric id.

### Options

| Option | Effect |
|---|---|
| `--dry-run` | Classify and print `WOULD ADD`; no POST |
| `--yes`, `-y` | Skip confirm when ≥10 domains will be added |
| `--skip` | Skip names already on any package; list them at the end |
| `--help`, `-h` | Help |

### Standard cases

One add:

```bash
php scripts/domain/attach-domain-to-package.php \
    package-example.com additional-example.com
```

Batch from a file (`#` comments allowed):

```text
# domains.txt
new-one.example
new-two.example
```

```bash
php scripts/domain/attach-domain-to-package.php --dry-run package-example.com < domains.txt
php scripts/domain/attach-domain-to-package.php --yes package-example.com < domains.txt
```

### Processing

1. `GET /package`, find target by `package-domain`
2. Classify each requested name: unattached / already on target / on another package
3. Without `--skip`, **any** name on another package aborts with exit 1 and **no** writes
4. Eligible names POST one at a time to `/package/{id}/names` with `add`, empty `rem`, `chg: null`
5. Each add is re-checked up to 3 times with 1s sleep (`VERIFICATION_ATTEMPTS` / `VERIFICATION_DELAY_SECONDS`)
6. Per-line progress: `SUCCESS` or `ERROR: ...`
7. Exit 0 if every add verified; exit 3 if any add failed

### Edges

| Situation | Result |
|---|---|
| Package selector unknown | Exit 1, no writes |
| No stdin domains | Exit 1 `No domains were provided.` |
| Invalid name in the list | Exit 1 immediately (fail-fast; earlier items not sent if this is preflight) |
| Duplicate lines | Deduped, order kept |
| Already on target, no `--skip` | Treated as skip-class; not a foreign-package abort |
| Already on another package, no `--skip` | Abort entire run, exit 1 |
| Already on another package, `--skip` | Omitted; listed last as `package {id} ({selector})` |
| All already attached | `No domains need to be added.`, exit 0 |
| ≥10 eligible, no TTY, no `--yes` | Throws; use `--yes` |
| API accepted but membership not visible after 3 probes | Counted as failure for that domain |
| Mixed success/failure | Exit 3; failed map printed |

This command does **not** create a new hosting package. It only attaches names to one that already exists.

---

## 7. `scripts/email/create-forward.php` (legacy)

Do not use this script in new admin, cron, or agent workflows.

Observed defects:

- Requires `vendor/autoload.php` (no root Composer project)
- Hardcodes `$general_api_key = "<REPLACE-WITH-YOUR-API-TOKEN>"` and ignores `.env`
- No `--dry-run`, `--yes`, `--help`, or shared exit codes
- Refetches `GET /package` once per distinct from-domain
- Invalid lines are skipped; process often still exits 0

Intended invocation if it were repaired:

```text
php scripts/email/create-forward.php user@example.com dest@elsewhere.example
# or stdin lines: <from>@<domain> <to>
```

Until it is rewritten onto `lib/bootstrap.php`, create forwards in the 20i control panel or a one-off PHP snippet that uses `$api_key` from bootstrap.

---

## 8. `scripts/dns/add-records.php`

Adds **one TXT record** to one or more domains that are already on a 20i package. Additive only: no delete, replace, or other RR types.

```text
php scripts/dns/add-records.php [--dry-run] [--yes] [--skip] [--force] <domain> \
    --name <dns-name> --type TXT --value <string>

php scripts/dns/add-records.php [--dry-run] [--yes] [--skip] [--force] \
    --name <dns-name> --type TXT --value <string> < domains.txt

php scripts/dns/add-records.php [--dry-run] [--yes] [--skip] [--force] \
    --all <package-domain> --name <dns-name> --type TXT --value <string>
```

`--name`, `--type`, and `--value` are required. `--type` must be `TXT` (case-insensitive).

### Options

| Option | Effect |
|---|---|
| `--name` | Owner. `@` = zone apex. See name table. |
| `--type TXT` | Only TXT is accepted |
| `--value` | Non-empty after trim |
| `--all` | One positional **package** domain; apply to every name on that package |
| `--dry-run` | Preflight + `WOULD ADD`; no DNS POST |
| `--yes`, `-y` | Skip confirm when ≥10 **eligible** domains |
| `--skip` | Published identical TXT → omit instead of aborting the whole run |
| `--force` | Ignore the 60-minute local submission journal |
| `--help`, `-h` | Help |

`--force` never overrides an identical TXT already visible on StackDNS. Use `--skip` for that.

### Standard cases

Apex sale banner:

```bash
php scripts/dns/add-records.php example.com \
    --name @ --type TXT --value "This domain is for sale" --dry-run
```

Verification owner on a list:

```bash
php scripts/dns/add-records.php \
    --name _verification --type TXT --value "token-value" --skip --yes \
    < domains.txt
```

Every domain on a parking package:

```bash
php scripts/dns/add-records.php --all lowpricereseller.com \
    --name @ --type TXT --value "This domain is for sale" --skip --yes
```

### Owner-name forms (zone `example.com`)

| `--name` | Relative host sent to 20i |
|---|---|
| `@` or empty | `@` |
| `example.com` / `example.com.` | `@` |
| `_verification` | `_verification` |
| `_verification.example.com` / `...com.` | `_verification` |
| `_verification.example.net.` | Rejected (outside zone) |
| `*.example.com` | Rejected (wildcards unsupported) |

Labels: `[a-z0-9_]` with interior `-`/`_`; max 63 per label, 253 overall.

`--value` is trimmed. Empty after trim is fatal. Comparison of “already exists” uses that trimmed value.

### Processing

1. Resolve packages (`GET /package`)
2. Build targets (explicit list, stdin, or `--all`)
3. Normalize `--name` **per target zone**
4. Preflight each domain against authoritative StackDNS (`ns1`–`ns4.stackdns.com`, 5s, UDP then TCP fallback) and the local journal
5. Without `--skip`, any `EXISTS` aborts before mutation (exit 1)
6. Eligible domains POST `/package/{packageId}/dns/{domain}` with `conflictPolicy=reject`, `insertPolicy=append`
7. Successful POSTs are journaled for 60 minutes
8. One immediate StackDNS read: `ACCEPTED; VERIFIED` or `ACCEPTED; PUBLICATION PENDING`

Preflight tokens: `READY`, `EXISTS`, `RECENTLY SUBMITTED`, `ERROR`.

Pending publication is **not** an API failure and does not by itself yield exit 3.

### Submission journal

Path, first match:

1. `$XDG_STATE_HOME/20i-cli/dns-submissions.json`
2. `$HOME/.local/state/20i-cli/dns-submissions.json`
3. `%LOCALAPPDATA%` or `%APPDATA%\20i-cli\dns-submissions.json`
4. `sys_get_temp_dir()/20i-cli/dns-submissions.json`

Directory mode `0700`, file mode `0600`, atomic replace. Key is SHA-256 of package id, domain, FQDN, `TXT`, value. Entries older than 3600s are ignored.

The journal only protects processes that share that file. Another laptop, `--force`, or the 20i panel can still create duplicates during the ~30+ minute StackDNS delay.

### Edges

| Situation | Result |
|---|---|
| Domain not on a visible package | Preflight `ERROR`; contributes to exit 3 unless the run aborted earlier |
| `--all` and selector missing | Exit 1 |
| `--all` with 0 or 2+ positionals | Exit 1 |
| Two positionals without `--all` | Usage, exit 1 |
| No positionals and empty stdin | Exit 1 `No domains were provided.` |
| Non-TXT `--type` | Exit 1 |
| Identical published TXT, no `--skip` | Exit 1, no writes |
| Identical published TXT, `--skip` | Omitted; other domains continue |
| Journal hit, no `--force` | Treated as protected; not submitted |
| Journal hit, `--force` | Eligible if StackDNS does not already have it |
| StackDNS inspect failure | Inspection failure; exit 3 if any remain at end |
| Dry-run with inspect/unresolved errors | Exit 3 even though nothing was written |
| API accept, journal write fails | Warning; do not blindly rerun |
| Immediate verify fails / NXDOMAIN | `PUBLICATION PENDING` — wait; do not resubmit |
| Mixed API failures | Exit 3 |
| PHP 8.5 deprecations on stderr | Ignore for `$?`; do not parse them as errors |

Conflict policy `reject` means 20i itself may refuse a conflicting insert even if local preflight passed.

---

## 9. Admin runbook

### Daily checks

```bash
php scripts/domain/exists.php --verbose customer-domain.example
```

Use this before attaching or writing DNS so you know which package you will hit.

### Attach a sold domain to a parking/hosting package

```bash
php scripts/domain/exists.php newdomain.example          # expect 1
php scripts/domain/attach-domain-to-package.php --dry-run \
    park.example newdomain.example
php scripts/domain/attach-domain-to-package.php \
    park.example newdomain.example
php scripts/domain/exists.php --verbose newdomain.example  # expect 0
```

### Publish the same TXT on many names

1. Build `domains.txt` (one FQDN per line)
2. Dry-run with `--skip`
3. Live with `--skip --yes` if the eligible count is ≥10
4. Treat `PUBLICATION PENDING` as success-with-wait
5. Re-query later with `--dry-run`; expect `EXISTS` or `RECENTLY SUBMITTED`

### Incident: 401 Invalid Authentication

- Confirm `.env` has only the general API key
- No quotes, no `+`, no auth-client key
- Key from https://my.20i.com/reseller/api

### Incident: command hung after printing a domain

It is waiting on `/dev/tty` because ≥10 items are eligible. Ctrl-C is exit-not-clean; rerun with `--yes` or a smaller batch.

### Incident: duplicates in the control panel

Usually `--force` or a second workstation during publication delay. This CLI cannot delete records. Remove extras in the 20i panel.

---

## 10. Automation (cron, CI, shell)

### Hard rules

- Always `--dry-run` in a prior job or in the same script before the live invocation
- Always `--yes` on unattended mutation of 10+ items
- Prefer `--skip` on idempotent “ensure this TXT exists” jobs
- Never `--force` on a schedule
- Parse **exit codes**, not log phrases
- Redirect stdout/stderr to a file; do not email `.env`
- `cd` to the repo or use absolute `php` + script paths; `.env` still loads from repo root

### Idempotent TXT ensure (cron)

```bash
#!/bin/sh
set -eu
ROOT=/Users/kritananda/Projects/20i_php_cli_toolkit
cd "$ROOT"
php scripts/dns/add-records.php \
    --name @ --type TXT --value "This domain is for sale" \
    --skip --yes \
    < /var/lib/20i/sale-domains.txt
```

Expected steady-state: exit 0, `No DNS records need to be added` or only new names `ACCEPTED`.

### Attach batch (CI)

```bash
php scripts/domain/attach-domain-to-package.php --dry-run --skip \
    "$PACKAGE_DOMAIN" < domains.txt
php scripts/domain/attach-domain-to-package.php --yes --skip \
    "$PACKAGE_DOMAIN" < domains.txt
```

### Wrapper pattern for `exists.php`

```sh
php scripts/domain/exists.php "$domain"
case $? in
  0) ;;  # attached
  1) echo "missing $domain" >&2; exit 1 ;;
  *) echo "lookup failed $domain" >&2; exit 2 ;;
esac
```

### Scheduling notes

- DNS publication ≥30 minutes: do not loop `add-records` every minute on the same value
- `GET /package` is account-wide; avoid hammering it in tight loops (`exists.php` does a full list each call)
- Journal file must live on a persistent home; ephemeral CI disks will not protect against duplicate POST

---

## 11. Contract for AI agents

### Allowed

- Read this handbook, `scripts/*/README.md`, and `--help`
- Run `--help`, `exists.php`, and `--dry-run` without extra confirmation
- Propose exact argv arrays
- After a human or policy allows writes: run with `--skip` / `--yes` as documented

### Forbidden

- Printing or committing `.env` or key material
- Combining general + auth-client keys
- Inventing A/AAAA/MX/CNAME/NS/wildcard support
- Deleting or replacing DNS
- Using `create-forward.php` as if it were production
- Treating `exists.php` exit 1 as a crash
- Treating DNS `PUBLICATION PENDING` as failure
- Using `--force` unless the operator explicitly asked to override the journal
- Assuming cwd must be the repo for `.env` (it is not) or that Composer is required (it is not)

### Recommended tool sequence

1. `php scripts/<cmd>.php --help`
2. `php scripts/domain/exists.php --verbose <domain>`
3. Mutation command with `--dry-run`
4. Show the operator the dry-run summary
5. Live command only after approval

### How to parse results

- Prefer `$?`
- For DNS/attach: `0` ok, `1` stop and read stderr, `2` cancelled, `3` inspect per-domain `ERROR` lines
- For exists: `0` / `1` / `2` as in §5
- Do not regex deprecation lines from PHP 8.5

### Non-interactive checklist

- [ ] `.env` present, `API_KEY` only
- [ ] Submodule checked out
- [ ] `--yes` if eligible count may be ≥10
- [ ] `/dev/tty` not required (because `--yes` or batch <10)
- [ ] stdin is a domain list, not a TTY prompt
- [ ] Writes were dry-run first

### Copy-paste agent prompt

```text
You are operating the 20i PHP CLI in this repo.
Read docs/cli-handbook.md first.
Use only documented flags.
Never read or echo .env.
Use exists.php and --dry-run before any mutation.
exists.php exit 1 means unattached, not a tool failure.
DNS ACCEPTED; PUBLICATION PENDING is success; do not resubmit.
Do not run create-forward.php.
```

---

## 12. Capability matrix

| Need | Command | Notes |
|---|---|---|
| Is domain on a package? | `exists.php` | Full package list each call |
| Attach name to existing package | `attach-domain-to-package.php` | No new-package create |
| Add TXT | `add-records.php` | Apex or relative; no wildcards |
| Add other RR types | — | Not implemented |
| Delete / replace DNS | — | Panel only |
| List packages as a table | — | Use `exists --verbose` or a custom `GET /package` snippet |
| Email forward | `create-forward.php` | Legacy; do not automate |
| SSO / Stack user login | — | Needs auth client key; not in these CLIs |

---

## 13. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `API_KEY is not defined in .env.` | Missing file or empty key | Write repo-root `.env` |
| HTTP 401, type User ID | Wrong or combined key | General API key only |
| `Unable to open /dev/tty` | Cron/agent + batch ≥10 | Add `--yes` |
| Exit 1 “identical record already exists” | Published TXT | Add `--skip` or change value |
| Always `RECENTLY SUBMITTED` | Journal <60m | Wait, or `--force` only if you intend a second POST |
| `not attached to any visible package` | Name not in `package.names` | Attach first; check spelling |
| `create-forward.php` fatal on autoload | No Composer vendor tree | Do not use; see §7 |
| Exit 3 after dry-run | Inspect/unresolved errors | Fix those domains; dry-run can still be 3 |

---

## 14. Source map

| Behavior | File |
|---|---|
| `.env` / `API_KEY` | `lib/env.php`, `lib/config.php` |
| Shared exits, stdin, confirm | `lib/cli.php` |
| Package scan and domain match | `lib/package.php` |
| TXT validation, StackDNS, payload | `lib/dns.php` |
| 20i HTTP client | `lib/20i-api-modules/lib/TwentyI/API/` |
