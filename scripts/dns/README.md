# `scripts/dns`

The `scripts/dns/` directory contains command-line tools for managing DNS records on domains attached to 20i hosting packages.

The current implementation supports authoritative read-only DNS export through `dump-records.php`, additive TXT record creation through `add-records.php`, ref-based record deletion through `delete-records.php`, and atomic TXT replacement through `replace-records.php`.

## `dump-records.php`

`dump-records.php` reads public DNS records for domains attached to 20i packages and prints one JSON object per domain on stdout:

```bash
php scripts/dns/dump-records.php example.com
php scripts/dns/dump-records.php --source api --types SRV,A example.com
php scripts/dns/dump-records.php --types A,MX,TXT example.com other.example
php scripts/dns/dump-records.php --all package-example.com
php scripts/dns/dump-records.php < domains.txt
```

### Sources

Records can come from two independent sources, selected with `--source`:

| Source | Mechanism | Coverage |
|---|---|---|
| `api` | `GET /package/{packageId}/dns`, the stored zone | Every record type the zone holds — SRV, wildcard, subhost entries, CAA and beyond — with **no type filtering unless `--types` is given**; reflects zone config even before StackDNS publication completes |
| `dns` | Authoritative StackDNS queries | Only the requested `--types` (default `A,AAAA,CNAME,MX,NS,SOA,TXT,SRV`); the ground truth for "did this record publish yet?" |
| `both` | Merge of both (default) | Every record carries a `source` tag |

The `api` source resolves zones across packages: a subdomain attached to one package whose parent zone lives on another package is resolved by walking ancestor names until a covering zone is found. Zone roots return every record in the zone; subdomains return their exact records plus one-label wildcard coverage (`*.zone` covers `a.zone`, not `a.b.zone`, per RFC 4592). API records carry their raw fields (including the per-record `ref` id used by edit and delete operations) under `fields`.

Query names may carry leading underscores (`_dmarc.example.com`, `_sip._tcp.example.com`) so TXT and SRV owner checks work directly.

### Output

- Read-only: never calls a mutation endpoint.
- Default types: `A,AAAA,CNAME,MX,NS,SOA,TXT,SRV` for `dns` queries; the `api` source is unfiltered without an explicit `--types`.
- Progress goes to stderr; stdout stays pure JSON Lines. All PHP diagnostics — including notices from the vendored client such as its "404 on <url>" message — are routed to stderr or suppressed, never interleaved into stdout.
- API failure messages in `errors` are reduced to status and endpoint; full exception detail (including any response body) stays on stderr.
- Per-domain failure produces `{"ok":false,"errors":{...}}` with one message per requested source, and exit status `3` if any domain failed all of its sources; all-success is `0`.
- `--all` ignores standard input; it takes exactly one positional package domain.

Use it for audits, local inventories, and verifying that a submission published (compare the `dns`-source records against `add-records.php` expectations).

## Architecture

DNS automation uses separate read and write paths:

- **Read path:** the 20i stored-zone endpoint for full coverage (`--source api`) and pure-PHP authoritative queries sent directly to StackDNS nameservers for live state (`--source dns`).
- **Write path:** the 20i package DNS POST endpoint.
- **Verification path:** an authoritative StackDNS TXT query after a successful API submission.

For an external or 20i-registered domain attached to a package, the write request is sent to:

```text
POST /package/{packageId}/dns/{domain}
```

Read access to the same path family exists through:

```text
GET /package/{packageId}/dns            # all zones on the package
GET /package/{packageId}/dns/{zone}     # one zone root
```

These GET endpoints answer only for zone roots; querying a non-zone subdomain returns HTTP 404.

## `add-records.php`

`add-records.php` adds one TXT record to:

- one positional domain;
- a list of domains read from standard input; or
- every domain attached to a package selected with `--all`.

The command is additive. It does not delete, replace, or reconcile other DNS records.

## Usage

### One domain

```bash
php scripts/dns/add-records.php \
    example.com \
    --name @ \
    --type TXT \
    --value "This domain is for sale"
```

### Domains from standard input

```bash
php scripts/dns/add-records.php \
    --name _verification \
    --type TXT \
    --value "verification-value" \
    < domains.txt
```

### Every domain in a package

The positional domain identifies the package. The TXT record is then considered separately for every usable domain attached to that package.

```bash
php scripts/dns/add-records.php \
    --all package-example.com \
    --name @ \
    --type TXT \
    --value "This domain is for sale"
```

### Dry run

```bash
php scripts/dns/add-records.php \
    example.com \
    --name _verification \
    --type TXT \
    --value "verification-value" \
    --dry-run
```

A dry run resolves packages, normalizes owner names, checks authoritative DNS, consults the recent-submission journal, and reports what would be submitted. It does not call the DNS mutation endpoint.

## Options

| Option | Purpose |
|---|---|
| `--name <dns-name>` | TXT owner name. Supports `@`, an empty string, a relative name, the zone domain, or an in-zone FQDN. |
| `--type TXT` | Record type. TXT is currently the only supported type. |
| `--value <string>` | TXT value to add. |
| `--all` | Apply the record to all domains in the package identified by the positional domain. |
| `--dry-run` | Perform resolution and preflight inspection without changing DNS. |
| `--yes`, `-y` | Suppress the confirmation prompt for a batch of ten or more eligible domains. |
| `--skip` | Skip identical records already visible through authoritative DNS instead of stopping the run. |
| `--force` | Ignore the local recent-submission safeguard. It does not override an identical record already published in authoritative DNS. |
| `--help`, `-h` | Display the command's built-in help. |

## Owner-Name Forms

For a target zone of `example.com`, these forms are supported:

| Input | Result |
|---|---|
| `@` | Zone apex: `example.com.` |
| `""` | Zone apex: `example.com.` |
| `example.com` | Zone apex: `example.com.` |
| `example.com.` | Zone apex: `example.com.` |
| `_verification` | `_verification.example.com.` |
| `_verification.example.com` | `_verification.example.com.` |
| `_verification.example.com.` | `_verification.example.com.` |

A trailing-dot FQDN outside the target zone is rejected. For example, this is invalid when processing `example.com`:

```bash
--name _verification.example.net.
```

Wildcard owner names are not currently supported.

## Processing Model

### 1. Resolve targets

The command retrieves visible 20i packages and associates each requested domain with its package ID.

### 2. Normalize the owner name

The supplied `--name` value is interpreted separately for every target zone and converted to the relative host value used by the 20i API.

### 3. Perform preflight inspection

For each domain, the command:

1. confirms that the domain belongs to a visible package;
2. queries authoritative StackDNS for an identical published TXT record;
3. checks the local recent-submission journal unless `--force` is present;
4. marks the domain as eligible only when neither safeguard blocks it.

Typical preflight statuses are:

```text
READY
EXISTS
RECENTLY SUBMITTED
ERROR
```

### 4. Submit eligible records

Each eligible domain is submitted separately so that progress and failures are reported deterministically.

### 5. Attempt immediate verification

After the API accepts a record, the script checks authoritative StackDNS once. The result is reported as one of:

```text
ACCEPTED; VERIFIED
ACCEPTED; PUBLICATION PENDING
```

A pending publication result is not an API failure.

## DNS Publication Delay

The 20i API may accept and display a new record in the control panel well before the record becomes visible through the authoritative StackDNS nameservers. Allow at least 30 minutes for publication, and recognize that it may take longer.

Because authoritative DNS may still show no record immediately after a successful submission, rerunning the same command during this interval could otherwise create a duplicate record.

The command therefore records successful API submissions in a local journal for 60 minutes.

Typical journal location on Linux:

```text
~/.local/state/20i-cli/dns-submissions.json
```

When `XDG_STATE_HOME` is set, the journal is stored beneath that directory instead.

## `--skip` and `--force`

These options address different safeguards.

### `--skip`

`--skip` applies to a matching TXT record that is already visible through authoritative DNS.

Without `--skip`, any published duplicate stops the run before mutation begins. With `--skip`, the command excludes that domain and continues with other eligible targets.

### `--force`

`--force` applies only to a matching entry in the local recent-submission journal.

It is intended for deliberate recovery or specialized testing when the operator knows that a recent accepted submission may safely be repeated. It does not bypass an identical record that authoritative DNS already publishes.

Using `--force` during the normal publication interval can create duplicate records and should be uncommon.

## Exit Status

The script uses the shared CLI exit codes:

| Status | Meaning |
|---:|---|
| `0` | All requested work was accepted, safely skipped, or protected as recently submitted. |
| `1` | A fatal validation, usage, configuration, or other error occurred. |
| `2` | The operator cancelled a confirmed batch operation. |
| `3` | One or more domains failed resolution, inspection, or API submission. |

A successful API submission that remains pending in authoritative DNS does not by itself produce exit status `3`.

## Safety Characteristics

- Supports preflight-only dry runs.
- Stops before mutation when a published duplicate exists unless `--skip` is supplied.
- Protects recently accepted submissions during delayed publication.
- Requires confirmation before changing ten or more eligible domains unless `--yes` is supplied.
- Submits domains independently and reports per-domain progress.
- Distinguishes actual API failures from ordinary publication delay.
- Performs additive TXT writes only.

## Current Limitations

- TXT is the only supported record type.
- Wildcard owner names are rejected.
- The local journal protects only clients that share the same journal file.
- An operator using `--force`, another workstation, or the 20i control panel can still create duplicates during publication delay.
- Immediate authoritative verification is advisory rather than a guarantee of publication time.

## Conservative Testing

Before a production batch:

1. run `php -l scripts/dns/add-records.php`;
2. select one controlled domain;
3. use a unique TXT owner name and value;
4. run with `--dry-run`;
5. submit the single record;
6. immediately rerun without `--force` and confirm `RECENTLY SUBMITTED`;
7. wait for authoritative publication before broader testing;
8. use `--all` only after explicit-domain and small-batch tests succeed.

## `delete-records.php`

`delete-records.php` deletes DNS records matched by owner name, type, and (optionally) value from:

- one positional domain;
- a list of domains read from standard input; or
- every domain attached to a package selected with `--all`.

```bash
php scripts/dns/delete-records.php example.com --name _acme-challenge --type TXT
php scripts/dns/delete-records.php example.com --name @ --type TXT --value "old verification"
php scripts/dns/delete-records.php --all package-example.com --name @ --type TXT \
    --value "This domain is for sale"
php scripts/dns/delete-records.php --name _old --type CNAME < domains.txt
```

### Record identity and matching

Records are read from the 20i stored zone (`GET /package/{packageId}/dns`) and identified by their stable per-record `ref` (verified live: numeric, stable across calls, `null` only on SOA). The delete request is one atomic diff per domain:

```json
{"conflictPolicy":"reject","insertPolicy":"append",
 "new":{"AAAA":[],"A":[],"CNAME":[],"MX":[],"TXT":[],"SRV":[]},
 "delete":["<ref>", "..."]}
```

Matching rules:

- The owner name uses the same forms as `add-records.php` (`@`, empty, relative, zone domain, in-zone FQDN; wildcards rejected).
- Types compare case-insensitively. `--type` accepts `A`, `AAAA`, `CNAME`, `MX`, `TXT`, `SRV`.
- With `--value`, TXT values compare after normalization; other types compare case-insensitively after trimming (`MX` values look like `10 mail.example.com`, `SRV` like `5 0 5269 target.example.com`, matching `dump-records.php` output).
- Without `--value`, **every** record of that owner and type is deleted.
- Zero matching records is a per-domain error (exit `3`) unless `--skip` classifies it as a skip.

### Guards

- SOA records can never be deleted (`--type SOA` is refused, and a matched SOA is blocked again at mutation time).
- NS deletion is refused entirely, so the zone apex always keeps its delegation.

### Mandatory pre-change snapshots

Before each domain's POST, the full stored zone is written as JSON Lines — shaped like `dump-records.php` rows, including raw record `fields` and refs — to:

```text
$XDG_STATE_HOME/20i-cli/snapshots/<domain>-<utcstamp>.jsonl
```

(falling back to `~/.local/state`, then `LOCALAPPDATA`/`APPDATA`, then the system temporary directory; directory `0700`, file `0600`). A snapshot failure aborts that domain's mutation.

### Options

| Option | Purpose |
|---|---|
| `--name <dns-name>` | Owner name of the records to delete. |
| `--type <T>` | Record type: `A`, `AAAA`, `CNAME`, `MX`, `TXT`, `SRV`. |
| `--value <string>` | Optional value filter; omitted deletes all records of that owner and type. |
| `--all` | Delete matching records on every domain in the selected package. |
| `--dry-run` | Full preflight with `WOULD DELETE` lines, zero mutation. |
| `--yes`, `-y` | Suppress the confirmation prompt for ten or more eligible domains. |
| `--skip` | Treat zero-match domains as skips instead of failures. |
| `--force` | Ignore the local recent-deletion journal only. |
| `--help`, `-h` | Display the command's built-in help. |

Accepted deletions are journaled for 60 minutes (`20i-cli/dns-deletions.json` in the state directory) to prevent accidental duplicate resubmission during publication delay; `--force` bypasses only this journal. Post-change authoritative verification is advisory: `ACCEPTED; VERIFIED` or `ACCEPTED; PUBLICATION PENDING` — pending is a success, because StackDNS publication may take 30 minutes or longer.

Exit codes follow the shared convention: `0` success (including skips and pending publication), `1` fatal before mutation, `2` operator declined the batch confirmation, `3` one or more domains failed.

## `replace-records.php`

`replace-records.php` atomically replaces one TXT record on one positional domain or a list of domains from standard input. Version 1 supports TXT only.

```bash
php scripts/dns/replace-records.php example.com --name @ --type TXT \
    --old-value "old verification" --new-value "new verification"

php scripts/dns/replace-records.php --name _acme --type TXT \
    --old-value "token-1" --new-value "token-2" < domains.txt
```

### Exactly-one-match rule

Exactly one stored TXT record must match the owner and `--old-value`:

- zero matches fail that domain (`No record matches …`);
- multiple matches fail that domain (`N records match …; refusing an ambiguous replace`);
- identical `--old-value` and `--new-value` is a fatal usage error before any work begins.

### Atomicity

The replacement is a single DNS diff POST carrying both the new TXT record and the deletion of the matched ref, so the zone never holds zero or two copies between calls:

```json
{"conflictPolicy":"reject","insertPolicy":"append",
 "new":{"AAAA":[],"A":[],"CNAME":[],"MX":[],"TXT":[{"host":"…","txt":"…"}],"SRV":[]},
 "delete":["<matchedRef>"]}
```

### Options

| Option | Purpose |
|---|---|
| `--name <dns-name>` | Owner name of the TXT record to replace. |
| `--type TXT` | Record type; TXT is the only supported type in this version. |
| `--old-value <string>` | The exact current value; must match exactly one record. |
| `--new-value <string>` | The replacement value. |
| `--dry-run` | Full preflight with `WOULD REPLACE` lines, zero mutation. |
| `--yes`, `-y` | Suppress the confirmation prompt for ten or more eligible domains. |
| `--force` | Ignore the local recent-replacement journal only. |
| `--help`, `-h` | Display the command's built-in help. |

Pre-change snapshots, the 60-minute journal (`20i-cli/dns-replacements.json`), advisory verification of the new value, and the shared exit codes all work exactly as described for `delete-records.php`.

## Offline Tests

```bash
php tests/dns/dump-probes.php
php tests/dns/mutation-probes.php
```

Both run without network access or an API key and exit non-zero on any assertion failure. `mutation-probes.php` covers the delete/replace payload builders, ref extraction (including the SOA `null` ref), record matching, the SOA and apex-NS guards, zero-/multi-match classification, the replace exactly-one-match rule, snapshot writing, and the mutation journal.
