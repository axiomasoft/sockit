# SocKit documentation

The architecture notes are in Russian. The guides below describe the APIs and the panel workflow a host actually runs.

## Start here

- [Social modules and host recipe](guide/social.md)
- [Panel install, upgrade, and recovery](guides/panel-install-upgrade.md)
- [Requirement traceability](architecture/traceability.md)

## Architecture

- [Theses](architecture/theses.md)
- [Philosophy](architecture/philosophy.md)
- [Core boundary](architecture/core-boundary.md)
- [Layering](architecture/layering-rules.md)
- [Structure](architecture/structure.md)
- [Ownership](architecture/ownership.md)
- [Extension axes](architecture/extension-axes.md)
- [Invariants](architecture/invariants.md)
- [Privacy](architecture/privacy.md)
- [SPI](architecture/spi.md)
- [Glossary](architecture/glossary.md)
- [Ecosystem practices](architecture/ecosystem-practices.md)
- [Panels and storage](architecture/panels-and-storage.md)
- [External discussions](architecture/external-discussions.md)
- [Neighbor work](architecture/neighbor-work.md)

## Decisions

ADR-0001 through ADR-0016 are in [docs/adr](adr/0001.md). Each record has status, context, decision, consequences, and alternatives.

`php scripts/check-docs.php` checks that every architecture page and ADR is present and non-empty.
