# `scripts/dns`

The `scripts/dns/` directory contains command-line tools for managing DNS records on domains attached to 20i hosting packages.

The current implementation supports authoritative read-only DNS export through `dump-records.php` and additive TXT record creation through `add-records.php`.

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
| `api` | `GET /package/{packageId}/dns`, the stored zone | Every record type the zone holds, including SRV, wildcard, and subhost entries; reflects zone config even before StackDNS publication completes |
| `dns` | Authoritative StackDNS queries | Only the requested `--types`; the ground truth for "did this record publish yet?" |
| `both` | Merge of both (default) | Every record carries a `source` tag |

The `api` source resolves zones across packages: a subdomain attached to one package whose parent zone lives on another package is resolved by walking ancestor names until a covering zone is found. Zone roots return every record in the zone; subdomains return their exact records plus any wildcard records that can cover them. API records carry their raw fields (including the per-record `ref` id used by edit and delete operations) under `fields`.

### Output

- Read-only: never calls a mutation endpoint.
- Default types: `A,AAAA,CNAME,MX,NS,SOA,TXT,SRV`.
- Progress goes to stderr; stdout stays pure JSON Lines (PHP deprecation notices are suppressed in this script for that reason).
- Per-domain failure produces `{"ok":false,"errors":{...}}` with one message per requested source, and exit status `3` if any domain failed all of its sources; all-success is `0`.

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
- Records can be added but not deleted or replaced.
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
