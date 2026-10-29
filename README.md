# SocKit

SocKit is an embeddable social engine for Laravel. The project is pre-1.0; its public API is not yet released.

## Статус и что дальше

P1 defines the core contracts, panel and surface configuration, fixtures, architecture boundaries and checks. These are contract-level evidence, not end-to-end or production readiness. The P2 core runtime has an isolated PostgreSQL and standalone Laravel consumer fixture; P3 generated schema infrastructure is exercised by an isolated consumer; social modules and host qualification follow in P4–P6; Forum and Chatom integrations are conditional. See the [traceability matrix](docs/architecture/traceability.md) for requirements, evidence and pending work.


## Standalone consumer fixture

`tests/Fixtures/Consumer` installs the package copy into its own Laravel 13 vendor. The
consumer declares panel and surface providers, host actor and policy adapters, an owner
mutation and an SQL inbox. Run its PostgreSQL acceptance with
`python3 scripts/testing/pg-run.py composer test -- tests/Feature/Consumer/StandaloneRuntimeTest.php --fail-on-skipped --fail-on-incomplete --fail-on-warning --fail-on-risky`.
The runner creates an isolated disposable PostgreSQL target. The fixture exercises public
runtime APIs; it is not a production schema generator or a reactions/posts implementation.

[Panel install, upgrade and recovery runbook](docs/guides/panel-install-upgrade.md) documents the verified fixture workflow and its limits.
