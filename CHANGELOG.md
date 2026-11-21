# Changelog

## Unreleased

- Reuse one directory listing while planning a panel or migration, and scan each Composer classmap once when checking generated class names.
- Hash a panel id once per schema compile and once per migration history read.
- Encode an outbox envelope once when the append hits an existing event.
- Publish a GitHub Actions workflow for PHP 8.4. It checks out Packman 0.1.2, then runs Pint, PHPStan, documentation checks, Rector, the SQLite suite, disposable PostgreSQL suites, and the Packman gate. Dependabot is not used.
- Remove committed phase logs and local AI tooling from the tree. Requirement evidence stays in `docs/` and the test suite.
- Reject an expired audience allow on publish. Reads already failed closed. Reaction rebuilds already stop when `Surfaces::resolveTarget()` collapses an expired sample to a denial.
- Load reaction summaries with one query per table, and accept boolean columns returned as `0`/`1` by SQLite.
- Surface feed failures caused by a missing media resolver or a corrupt stored payload instead of shortening the page.
- Reuse the feed surface digest and UTC zone for the whole page.
- Mark internal implementation types explicitly for Packman surface checks without changing runtime behavior or the public API.

- Establish the development monorepo and package toolchain.
