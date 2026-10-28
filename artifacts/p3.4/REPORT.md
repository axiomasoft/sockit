# PLAN1 P3.4 — concrete panel models

Выполнен только P3.4. Owning session: `01a184cd-327a-7ae1-8660-9fdb92c2f7b6`.
Исполнитель: `codex/gpt-6.1-sol/high`, sandbox `danger-full-access`.
Исходный HEAD: `7a46820e2c6e49e1b8d16ff2f1288503f9b3dc9b`. `planner start` выполнен один раз, exit 0, без scout/take-over/strict-route. Review mode none; других агентов не запускал.

## Реализация

- `Schema/ModelEmitter` и model stub возвращают детерминированные consumer-owned PHP sources по уже скомпилированному FQCN map. Записи/overwrite файлов и DDL внутри emitter нет. BelongsTo/HasMany строятся из явных single-column FK; composite FK и коллизии имён методов отклоняются.
- `Schema/PanelModel` — SPI persistence extension. Binding константа фиксирует panel/entity/schema/table/connection/primary/key policy/casts. Hydration, newInstance/newFromBuilder, replicate и queue identifiers сохраняют класс и binding; попытки перенаправить table/connection/key semantics отклоняются. UUID7 создаётся для UUID7 PK; incrementing=false, string IDs и host keys без числового преобразования. JSON, integer, boolean и immutable datetime casts выводятся из descriptor.
- `Schema/ModelBindings::validate` явно проверяет весь скомпилированный map до install/use: autoloadable, instantiable compatible class и точное совпадение binding/casts/key flags. Это отдельный persistence preflight; data-only boot compiler не изменён.
- Tombstones сохраняют составной PK P2. Eloquent query/find/save/delete/queue restoration запрещены; `readIdentity` принимает только полную identity с собственной panel. Фиктивного id нет.
- Test-only `DemoModelContribution` добавляет target/revision FK к fixture acme.demo-targets. Production social modules не реализовывались. Domain API, ActorContexts/OperationHandles и routes не менялись.

## Реальные проверки

Все пять команд выполнены буквально из context item, cwd `.` относительно `/home/vostrikov/projects/packages/sockit`.
Финальный toolchain: системный PHP 8.4.27 через `artifacts/p3.4/toolchain/php`; CLI `register_argc_argv=On` зафиксирован в локальном ini. Composer/Pest/PHPStan/Pint — установленные зависимости репозитория. Настройки/уровень анализа, baseline и lint rules не ослаблялись.

| Check | Exit | Фактический результат |
|---|---:|---|
| V1 | 0 | 3 tests, 140 assertions: emitted CRUD всех writable entities, hydration/replicate, target/revision и link relations, UUID7/string/0042, JSON/datetime, custom misbindings, composite tombstone misuse |
| V2 | 0 | 3 tests, 161 assertions: 12 fresh-process restores для всех single-key entities двух панелей; Illuminate ModelIdentifier/SerializesModels; A→B→A с nested target/revision, rollback B; wrong connection/missing row отказ; deleted actor/permission revocation после restore |
| V3 | 0 | 48 arch tests, 445 assertions; public domain signatures остаются без ORM/Internal |
| V90 | 0 | No errors; финальный прогон без startup warnings |
| V91 | 0 | Pint passed |

Каждый PG-прогон запускался исключительно через `python3 scripts/testing/pg-run.py`. Wrapper создавал собственный контейнер PostgreSQL 16; PgTarget проверял label/run marker, loopback endpoint, точные owner/decoy *_test и server version до fixture DDL. Все SQL assertions выполнялись на этих verified targets; decoy и host sentinels сохранены. Контейнеры удалены wrapper после тестов.

Model restore не предоставляет domain authority: текущий WorkerActorResolver и OperationJobs заново получают actor/authorization. Deleted actor не вызывает factory; permission revocation оставляет текущего actor 7, нулевые effects/outbox и rejected receipt. System/anonymous fallback не возникает, caller context восстановлен.

`V*.json` соответствуют check-evidence schema; `evidence-validation.json` фиксирует успешную локальную проверку полей и log hashes. У всех пяти финальных checks один source fingerprint `25e3607ef462735b007fb4005911b464d447caacd6a330bed844dcfdf05f571b`.

## Исправления во время проверки и границы

Сохранены исходные неуспешные прогоны с их реальными exits: ошибка schema-qualified Eloquent column с driver prefix исправлена в qualifyColumn; JSONB assertion учитывает порядок ключей объекта; assertion отказа permission учитывает rejected receipt. Типизация исправлена без suppressions/baseline. Herd Lite PHP не поддерживает динамическую загрузку Turbo extension PHPStan: даже exit 0 с этим warning не принят как финальная проверка. Попытка системного PHP 8.5 выявила deprecations старого Composer и отключённый argv; финальный PHP 8.4.27 с явным CLI argv прошёл чисто. Все затронутые checks повторены на одном финальном source.

Генерация полного provider/CLI и orchestration install относятся к другим items; здесь поставлены emitter, stub и обязательный explicit binding preflight. Composite FK relations fail closed. Это persistence/fixture evidence, не social e2e P4. Чужие dirty/untracked файлы, .gitignore, .serena/project.yml и CLAUDE.md не изменялись и не включаются в commit. Push/tag/publish/внешних сообщений не было.

Commit выполняется только после проверок и dry-run штатной командой planner commit; фактический SHA и finish будут сохранены отдельными новыми artifacts после commit, без изменения этого отчёта и прочих закоммиченных файлов.
