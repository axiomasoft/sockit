# axiomasoft/sockit

SocKit embeds panel-scoped social features in Laravel. Applications declare panel and surface providers, configure actor and audience resolvers, and enable the domain modules used by each panel. Generated Eloquent models persist rows. They do not decide who may read or write.

Use `sockit:make-panel` to generate the application-owned models and `sockit:panel:migrations` to generate the initial or additive snapshot. PostgreSQL provides the locking, catalog introspection and receipt isolation used by the mutation and relay protocols. The configured application owns its generated migrations.

The Packman plugin `axiomasoft.sockit.core` exposes configured resolvers as capabilities. Extensions requiring the plugin can offer a `SocKit\Modules\Contracts\Module` through its contributor slot. Enable its module ID in `sockit.modules.enabled` to use it.

AudienceSpec supports nested allOf, anyOf and exclude expressions. Reads stop when a branch proves the decision. Exclusions require a confirmed negative membership or address match. Group verdicts carry local state checks through the completed response. Publication validates the privacy floor for every included branch and requires host containment for the complete expression.

## Databases and preview versions

Panel-bound Eloquent models support SQLite tables owned by the host. The social mutation, replay and generated migration protocols require PostgreSQL. The earlier local preview identified itself as 1.0.x-dev and used a different receipt and outbox schema. Its database files are incompatible with this PostgreSQL runtime. Preserve preview fixtures separately and generate a fresh panel schema for acceptance tests.

Before any changes, `sockit:panel:migrations <panel> --upgrade` checks a configured SQLite file in read-only mode for the preview receipt columns, including custom table names. `MIGRATION_PREVIEW_SQLITE_RECEIPTS_INCOMPATIBLE` identifies that format. Preview receipts lack the evidence needed to authorize replay. Preserve the SQLite source for historical access and create a separate PostgreSQL database for new writes.

Inspect the source with `sqlite3 -readonly preview.sqlite ".schema"`. Export a consistent historical snapshot with `sqlite3 -readonly preview.sqlite ".dump" > preview-export.sql` to a new destination file. This export belongs to the preview schema. The `archive/sockit-packman-preview` branch retains the matching code.
