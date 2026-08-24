# 20i

**Software that helps 20i.com resellers**

This repository provides reusable PHP libraries and command-line tools for automating common 20i reseller operations. The project emphasizes safe batch processing, deterministic progress reporting, dry-run support, minimal runtime dependencies, and small reusable components.

## Current Capabilities

### Domain management

- Discover packages and the domains attached to them.
- Resolve a domain to its hosting package.
- Attach one or more domains to a package.
- Detect duplicate domain attachments.
- Process explicit domains, standard-input batches, or all domains in a package.

### DNS management

- Add TXT records to domains attached to 20i hosting packages.
- Query the authoritative StackDNS nameservers directly.
- Detect already-published identical TXT records.
- Protect against accidental resubmission while a DNS change is awaiting publication.
- Verify records through authoritative DNS when they become visible.

### Email management

- Create email forwards through the 20i API.
- Support individual and batch-oriented workflows.

### Shared CLI infrastructure

- Load API credentials from a local `.env` file.
- Provide common exit codes, failure handling, confirmation prompts, and standard-input processing.
- Keep reusable business logic under `lib/` and executable command wrappers under `scripts/`.

## Requirements

- PHP 7.4 or later
- Git
- A 20i reseller account
- A 20i API key
- The 20i PHP API modules included by this repository as a Git submodule

The DNS implementation does not require `dig`, Python, `dnspython`, or PHP's `dns_get_record()` function. Authoritative TXT queries are implemented directly in PHP over DNS UDP, with TCP fallback when required.

## Installation

Clone the repository and initialize its submodules:

```bash
git clone --recurse-submodules https://github.com/softwarewrap/20i.git
cd 20i
```

For an existing clone that does not yet contain the API submodule:

```bash
git submodule update --init --recursive
```

## Configuration

Create a `.env` file in the repository root:

```dotenv
API_KEY=your-20i-api-key
```

The bootstrap process loads this value into the CLI scripts. Keep `.env` private and do not commit it to source control.

See [`docs/cli-handbook.md`](docs/cli-handbook.md) for the full CLI contract (admins, automation, agents). See [`configs/README.md`](configs/README.md) and [`docs/setup.md`](docs/setup.md) for additional setup information.

## Quick Start

### Attach a domain to a package

```bash
php scripts/domain/attach-domain-to-package.php \
    example.com \
    --package-domain package-example.com
```

Use the command's built-in help for its complete invocation syntax:

```bash
php scripts/domain/attach-domain-to-package.php --help
```

### Add a TXT record

```bash
php scripts/dns/add-records.php \
    example.com \
    --name @ \
    --type TXT \
    --value "This domain is for sale"
```

Preview the operation without changing DNS:

```bash
php scripts/dns/add-records.php \
    example.com \
    --name @ \
    --type TXT \
    --value "This domain is for sale" \
    --dry-run
```

For DNS-specific behavior, propagation considerations, and batch examples, see [`scripts/dns/README.md`](scripts/dns/README.md).

## Repository Structure

```text
20i/
├── lib/                    Reusable PHP libraries
│   ├── 20i-api-modules/    20i PHP API Git submodule
│   ├── bootstrap.php       Shared API bootstrap
│   ├── cli.php             Common CLI helpers and exit codes
│   ├── config.php          API credential loading
│   ├── dns.php             DNS validation, packets, queries, and helpers
│   ├── env.php             Lightweight .env loader
│   └── package.php         Package and domain discovery helpers
├── scripts/
│   ├── dns/                DNS automation commands
│   ├── domain/             Domain and package commands
│   └── email/              Email-management commands
├── configs/                Configuration documentation and templates
├── docs/                   Setup and API documentation
├── tests/                  Test documentation and utilities
├── archive/                Deprecated or retained historical content
├── .github/                GitHub-specific project files
├── LICENSE                 GNU GPL v3 license
├── CONTRIBUTING.md         Contribution guidelines
├── CODE_OF_CONDUCT.md      Contributor code of conduct
└── README.md               Repository overview
```

## Design Principles

- **Reusable libraries:** Shared logic belongs under `lib/` rather than being duplicated across commands.
- **Thin CLI wrappers:** Scripts should focus on argument parsing, orchestration, progress reporting, and exit status.
- **Safe automation:** Commands should support dry runs, preflight checks, and confirmation for consequential batch operations.
- **Deterministic output:** Batch commands should report consistent per-item progress and final summaries.
- **Minimal dependencies:** Prefer PHP implementations over external executables when doing so remains reliable and maintainable.
- **Additive operations first:** DNS automation currently adds records only; it does not automatically delete or replace existing records.
- **Authoritative validation:** DNS inspection is performed directly against StackDNS authoritative nameservers rather than a recursive resolver.

## Documentation

- [`lib/README.md`](lib/README.md): reusable library reference
- [`scripts/dns/README.md`](scripts/dns/README.md): DNS command guide
- [`scripts/email/README.md`](scripts/email/README.md): email command guide
- [`configs/README.md`](configs/README.md): configuration information
- [`docs/cli-handbook.md`](docs/cli-handbook.md): operator, automation, and agent CLI handbook
- [`docs/setup.md`](docs/setup.md): setup guidance
- [`tests/README.md`](tests/README.md): testing information

## Contributing

Contributions are welcome. Read [`CONTRIBUTING.md`](CONTRIBUTING.md) for coding standards, project expectations, and pull-request guidance. Contributors must also follow the [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md).

## License

This project is licensed under the GNU General Public License v3.0. See [`LICENSE`](LICENSE) for the complete license text.

## Support and Feedback

Questions, defects, and enhancement requests may be submitted through the project's [GitHub issue tracker](https://github.com/softwarewrap/20i/issues).
