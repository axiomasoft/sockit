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
