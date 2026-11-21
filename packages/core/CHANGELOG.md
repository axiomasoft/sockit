# Changelog

## Unreleased

- Panel and migration planning reuses directory listings and scans each Composer classmap once.
- Schema compilation and migration history hash each panel id once.
- An outbox append encodes the envelope once, including the conflict check.
- `AudienceGate::publish()` now denies an allow whose admission or content verdict is already expired.
- Register the social module extension point, typed SocKit settings and problem definitions with Packman.
- Expose Laravel service providers for the reactions, posts and groups modules.
