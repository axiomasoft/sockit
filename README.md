# SocKit

SocKit is an embeddable social engine for Laravel. The project is pre-1.0; its public API is not yet released.

## Packages

Install axiomasoft/sockit for the Laravel kernel. The optional axiomasoft/sockit-groups, axiomasoft/sockit-posts and axiomasoft/sockit-reactions packages each provide a Laravel service provider. Register the providers you want to offer through Packman. Select each module in the application configuration and its panels. Use axiomasoft/sockit-testkit as a development dependency for Testbench and conformance tests.

## Development

Run composer test for the architecture, unit, testkit and SQLite Eloquent checks. Run composer test:pg for the Laravel consumer and transactional PostgreSQL suites in disposable containers. Use composer gate to check each package’s public contracts and ownership.

## Статус и что дальше

For a configuration report across all registered panels run `php artisan sockit:diagnose --json`. Add `--probe-storage` to check live schema and runtime readiness. `sockit:doctor --panel=<id>` remains the panel-specific live check.

The host supplies trusted maintenance handles through `sockit.relay.handles`. `sockit:outbox:run --panel=<id> --replay-mode=projection_only` replays projections. Omitting the panel visits each registered panel. `sockit:relay --panel=<id> --once` runs a delivery pass. `sockit:reactions:rebuild` visits selected reaction panels, enumerates stored targets and rebuilds counts through the guarded domain operation. Use `--target=type:key --incarnation=1` to restrict the target.

P1 defines the core contracts, panel and surface configuration, fixtures, architecture boundaries and checks. These are contract-level evidence, not end-to-end or production readiness. The P2 core runtime has an isolated PostgreSQL and standalone Laravel consumer fixture; P3 generated schema infrastructure is exercised by an isolated consumer; P4 supplies reactions and posts/feed with isolated consumer evidence; host qualification follows in P6; Forum and Chatom integrations are conditional. See the [traceability matrix](docs/architecture/traceability.md) for requirements, evidence and pending work.


## Standalone consumer fixture

`tests/Fixtures/Consumer` installs the package copy into its own Laravel 13 vendor. The
consumer declares panel and surface providers, host actor and policy adapters, an owner
mutation and an SQL inbox. Run its PostgreSQL acceptance with
`python3 scripts/testing/pg-run.py composer test -- tests/Feature/Consumer/StandaloneRuntimeTest.php --fail-on-skipped --fail-on-incomplete --fail-on-warning --fail-on-risky`.
The runner creates an isolated disposable PostgreSQL target. The fixture exercises public
runtime APIs; it is not a production schema generator or a reactions/posts implementation.

[Panel install, upgrade and recovery runbook](docs/guides/panel-install-upgrade.md) documents the verified fixture workflow and its limits.

[Social modules and host recipe](docs/guide/social.md) describes the actual P4 APIs and their limits.
