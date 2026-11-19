# Владение состоянием

| State identity | Writer | Storage/read boundary |
|:--|:--|:--|
| PanelDefinition + storage/model/settings snapshot | host provider + compiler | code/immutable registry |
| Surface instance + incarnation | SocKit kernel | panel.surface_instances; public Surface API |
| Receipt/outbox/tombstone | kernel envelope на connection владельца операции | panel tables, без cross-connection atomicity |
| Post/revision/feed | posts module | panel.posts/post_revisions; Posts API |
| Social reaction membership/counter | reactions module; counter rebuildable | panel.reactions/reaction_counts; target identity = surface+target type/key+actor+kind |
| Group/membership | groups module | panel.groups/group_members |
| Host target identity/visibility/lifecycle | host TargetResolver | current verdict, не чужие SQL reads |
| Comment body/tree/revisions/stats/moderation | Chatom Comments/Forum | только публичный API владельца; в SocKit таблиц нет |
| Message reaction / forum vote | Chatom / Forum | отдельные state identities; не зеркалить в SocKit |
| Optional Forum link / announcement outcome | adapter package | собственные mappings/inbox; не копии контента |
| Host identity/consent/permissions/tenancy | host, optional AzGuard | SPI |
| Media bytes | media owner, optional Vaulter | SPI |
| Generated panel provider/models/migrations | consumer host | consumer-owned files; без перезаписи upgrade-командой |

Production schema modules are explicit consumer opt-ins. Register
`SocKit\Reactions\ReactionsServiceProvider` and/or
`SocKit\Posts\PostsServiceProvider`, enable their canonical IDs
`sockit.reactions` / `sockit.posts` in `sockit.modules.enabled`, then select the
required subset on each panel. These internal providers offer declarations only;
package discovery registers only the kernel provider. Registration, boot and
`config:cache` execute no SQL. CLI `--modules` accepts the canonical IDs.

Each module owns its source root, settings and append-only v1 contribution:

| Module root | Feature | Entities | Settings |
|:--|:--|:--|:--|
| `packages/reactions/src` | `reactions` | `reactions`, `reaction_counts` | `kinds`: nonempty unique list of stable aliases, default `['like']` |
| `packages/posts/src` | `posts` | `posts`, `post_revisions` | empty; unknown keys rejected |

The kernel never imports either module. Modules use public kernel contracts and
cannot import a sibling or foreign `Internal`. The manifest explicitly excludes
the social namespace prefixes from the kernel owner and assigns each to its
source root. The architecture gates check both roots, their markers, public
signatures and dependencies; PHPStan, Pint and Rector include them.

Reaction membership retains its UUID, active flag and positive revision across
removal. Full installation/panel/surface identity, both incarnations, lossless
target alias/key, actor reference and kind are retained. The sole membership
unique index uses a bounded SHA-256 of canonical ordered identity columns; counts
use the same identity without actor. `ReactionIdentity::verify()` must check every
stored identity column after every digest lookup or unique-conflict recovery,
before use. Mismatch is rejected, never merged. Digests confer no authority.
Counts are bigint, accept zero and reject negatives using the existing `matches`
predicate on the decimal representation. Numeric `matches` uses the same frozen
renderer as text predicates. Configured kinds are operation-time module policy;
the immutable schema constrains their syntax and never embeds mutable settings.

Posts keep `publication_state` (`pending`, `published`, `held`, `rejected`)
independent of the `visibility` overlay (`visible`, `hidden`, `deleted`). Thus hide
and restore preserve the previous publication state. `published_at` records first
publication; chronology is `(surface_digest, published_at, id)`. Full surface
identity is retained alongside the digest and must be matched before reads.
`content_state=erased` is the terminal erasure marker: its body is null and
`erased_at` is required; retained bodies are nonnull (empty string for media-only
posts). Media bytes remain outside SocKit. Revisions carry actor, action, reason,
publication/visibility, audience and privacy revision metadata with unique
`(post_id, revision)` and a restrictive FK to this panel's mapped posts only.
Append-only revision writes, transition fencing and erasure authorization belong
to the social operation owner; these persistence models confer no authorization.

Generated concrete models contain frozen casts, connection/table bindings and
post/revision relations. They are consumer-owned persistence extensions. The
snapshot embeds the resolved contribution and physical DDL; changing settings,
disabling a module or changing the registry cannot redirect an old rollback.
Disabling preserves installed tables/data; a fresh panel without a selection
creates none of that module's tables. Schema registration does not publish a post
target resolver or authorize social writes; their guarded runtime is separate
from this schema foundation.
