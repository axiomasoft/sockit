# Security

## Reporting

Report a vulnerability through GitHub private vulnerability reporting on [axiomasoft/sockit](https://github.com/axiomasoft/sockit/security). Do not file a public issue, and do not include exploit payloads, credentials, or customer data in a public commit or discussion.

Include the package, the version or commit, the affected call path, and whether the issue is reachable by a host that followed the documented adapters.

## Scope

In scope:

- `packages/core`, `packages/groups`, `packages/posts`, `packages/reactions`, and `packages/testkit`
- audience, admission, feed, reaction, group, receipt, and outbox enforcement
- schema generation that can write or disclose another panel's data
- secrets in the repository or in default configuration

Out of scope:

- a host that binds a permissive actor resolver, authorizer, or target fence on purpose
- generated Eloquent models used as an authorization API. They are persistence rows. Domain writes go through the operation envelope
- absence of Dependabot. Maintainers run `composer audit` when they change `composer.lock`. As of this tree, upstream advisories remain in Laravel 13.39 and Symfony 8.1.9 (fixed in Laravel 13.40 and Symfony 8.1.11). They are not patched in this change because that bump is a separate lockfile update
- vulnerabilities that require a compromised host process or database superuser

The project is pre-1.0 and proprietary. A report does not grant a license. Maintainers will acknowledge a concrete report and ship a fix on `main` when the issue is confirmed.
