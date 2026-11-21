# SocKit

SocKit is an embeddable social engine for Laravel. The source repository is public. The license is proprietary: no license to use, modify, or distribute is granted yet. The project is pre-1.0, and its public API is not released.

## Requirements

- PHP 8.4
- Laravel 13 (`illuminate/*` ^13.0)
- PostgreSQL 16 for mutations, relay, and generated migrations
- [`axiomasoft/packman`](https://github.com/axiomasoft/packman) 0.1.2 and `axiomasoft/packman-plugins` 0.1.2

SQLite is enough for panel-bound Eloquent models and the architecture suite. Social writes, receipts, and the outbox require PostgreSQL.

## Packages

| Package | Role |
|---|---|
| `axiomasoft/sockit` | Laravel kernel: panels, surfaces, audience, operations, schema generation |
| `axiomasoft/sockit-groups` | Groups and membership |
| `axiomasoft/sockit-posts` | Posts and feed |
| `axiomasoft/sockit-reactions` | Reactions and count rebuilds |
| `axiomasoft/sockit-testkit` | Development dependency for Testbench and conformance tests |

Install the kernel and register only the module providers you offer through Packman. Enable each module in configuration and select it on the panels that use it. The packages are not on Packagist yet. This repository is the public source, and the root project installs them through a path repository.

```sh
php artisan vendor:publish --tag=sockit-config
```

The host supplies actor, installation, surface, and target adapters. A command payload does not choose the author. Generated models are persistence rows. Authorization stays in the audience gate and operation envelope, not in Eloquent mass assignment.

## Commands

`php artisan sockit:diagnose --json` reports every registered panel. Add `--probe-storage` to check live schema and runtime readiness. `sockit:doctor --panel=<id>` is the panel-specific live check.

The host supplies trusted maintenance handles through `sockit.relay.handles`.

- `sockit:outbox:run --panel=<id> --replay-mode=projection_only` replays projections. Omitting `--panel` visits each registered panel.
- `sockit:relay --panel=<id> --once` runs one delivery pass.
- `sockit:reactions:rebuild` rebuilds counts for selected reaction panels. `--target=type:key --incarnation=1` restricts the target. An expired target admission is refused and does not rewrite counts.

## Documentation

- [Documentation index](docs/README.md)
- [Social modules](docs/guide/social.md)
- [Panel install, upgrade, and recovery](docs/guides/panel-install-upgrade.md)
- [Requirement traceability](docs/architecture/traceability.md)

P1 is contracts, fixtures, and architecture checks. P2 is the isolated PostgreSQL runtime. P3 is generated schema. P4 is reactions, posts, and groups. Host qualification, external adapters, and release erasure remain open. See the traceability matrix for what a test actually proves.

## Development

Clone this repository and place Packman 0.1.2 beside it so `../packman/packages/*` resolves:

```sh
git clone --branch v0.1.2 --depth 1 https://github.com/axiomasoft/packman.git ../packman
composer install
composer test
composer gate
composer docs
```

`composer test` runs the architecture, unit, testkit, and SQLite model checks. `composer test:pg` runs the Laravel consumer and transactional PostgreSQL suites in a disposable container started by `scripts/testing/pg-run.py`. Docker must be able to pull `postgres:16-alpine`.

```sh
composer lint
composer analyse
composer refactor:check
```

Continuous integration is GitHub Actions on PHP 8.4. It checks out Packman `v0.1.2`, then runs lint, PHPStan, the SQLite suite, both PostgreSQL suites, the Packman gate, the documentation check, and Rector. Dependency updates are reviewed by maintainers. This repository does not use Dependabot.

## Security

Report vulnerabilities through GitHub private vulnerability reporting on [axiomasoft/sockit](https://github.com/axiomasoft/sockit). Do not open a public issue. Details are in [SECURITY.md](SECURITY.md).
