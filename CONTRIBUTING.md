# Contributing

SocKit is source-available and proprietary. A contribution does not grant a license to the project, and the maintainers may decline it.

## Setup

Use PHP 8.4. Check out `axiomasoft/packman` at tag `v0.1.2` as a sibling directory named `packman`. The root `composer.json` path repository maps that checkout to version 0.1.2.

```sh
composer install
composer test
composer docs
composer gate
```

PostgreSQL checks need Docker:

```sh
composer test:pg
```

Do not pass extra arguments to `composer test` or `composer test:pg` when you want the full curated suites. Any argument replaces the file list in `bin/test` and `bin/test-pg`. To run one file, call Pest directly:

```sh
vendor/bin/pest tests/Unit/Audience/AudienceGateTest.php
```

## Checks

Match the CI job before opening a pull request:

- `composer lint` (Laravel Pint)
- `composer analyse` (PHPStan at the maximum level)
- `composer docs`
- `composer test`
- `composer test:pg`
- `composer gate`
- `composer refactor:check`

`composer check` runs lint, PHPStan, docs, the SQLite suite, and the gate. It does not start PostgreSQL.

## Dependencies

Do not add Dependabot configuration. Maintainers bump Composer dependencies deliberately and refresh `composer.lock` in the same change. Runtime code must not call `env()`, `config()`, or superglobals outside configuration, Laravel adapters, console commands, tests, and service providers.

## Package code

Keep service providers thin and put bindings in registrars. New public methods are a SemVer obligation. Prefer an internal class when callers inside the monorepo are the only users. Anonymous migrations stay anonymous. Do not edit a migration that has already shipped. Add a new one.

Generated Eloquent models persist rows. They are not the authorization boundary. Audience expiry, admission, and operation fences belong in the domain code those models do not run.
