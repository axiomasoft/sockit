# PLAN1 P3.3 — production kernel DDL и frozen snapshots

Owning session: `01a184a5-b763-7d83-90c3-f68b92c342b9`. Actual runtime из `turn_context`: `codex/gpt-6.1-sol/xhigh`, sandbox `danger-full-access`, approval `never` (`runtime.json`). Base HEAD: `3d4fbb8c9aebc05d4992fa3ece969c9e9919ca72`. `planner start` выполнен один раз с `--no-scout`, exit 0; после capacity не повторялся. Единственный plan-input — `artifacts/autopilot/p3.3-context.json` плана. Другие executors/subagents/reviewer не запускались.

## Реализация

`KernelSchema` доведён от draft P3.1 до первого production baseline v1: четыре owned entities `surface_instances`, `receipts`, `outbox`, `tombstones`. Ранее production migrations не существовали. В descriptor зафиксированы PostgreSQL UUID7, timezone-aware timestamps, positive incarnation/revision/generations, lifecycle/deleted consistency, lossless host identity и DTO byte bounds. Для host key проверяются именно 512 bytes, в том числе UTF-8. Receipt namespace ≤384, command key ≤128, actor metadata/digest/guard_context и UNIQUE(namespace,command_key). In-flight outcome допускает NULL; стабильные confirmed/rejected связаны с nullable resource/revision/problem через CHECK. Outbox хранит source generation, envelope/digest/deliveries JSON object, unique event identity и индекс по фактическому relay identity/order. Tombstones имеют настоящий составной PK, сохраняются после recreation, не связаны CASCADE с live row и не изображают single-id Eloquent CRUD.

Tooling SPI `SnapshotRenderer::render()` выполняет только explicit read-only physical preflight и возвращает consumer-owned PHP migration. В файл встроены canonical JSON descriptor, format version, component versions/histories, source/physical digests, конечные physical names, connection selector, trusted deployment cluster/endpoints и буквальные forward/reverse SQL programs. Целостность base64/JSON проверяется SHA-256 до DDL. Runtime execution migration использует Laravel и PDO, не вызывает latest package schema helper, module provider, models, PanelDefinitions или текущий panel config. Старый snapshot протестирован в свежих PHP-процессах, где автозагрузка current Schema/Modules/Panels/test-module classes явно запрещена.

Forward создаёт tables/indexes в dependency order и добавляет только FK к snapshot-owned entities. Reverse сначала снимает собственные FK, затем удаляет свои таблицы в обратном порядке с RESTRICT. Ни CREATE/DROP SCHEMA, ни CASCADE в generated program нет. Pre-existing relations, включая names будущих indexes, не присваиваются snapshot. Уже занятый initial namespace отклоняется до создания первой таблицы. Published vendor migrations и DDL at boot/request не добавлены.

Connection binding сверяет frozen selector, normalized endpoint и фактически наблюдаемую write-PDO database/server/schema; перенаправление alias или подмена PDO отклоняются до mutation. Credentials не попадают в descriptor/digests/diagnostics и могут ротироваться. Текущие prefix/search_path не перенаправляют frozen table names. PostgreSQL-only driver guard выполняется до записи. Проверка raw read PDO закрывает случай, когда Laravel ConnectionFactory удаляет `read/write` из resolved config; тем же guard усилен generation preflight.

Snapshot владеет только созданными объектами. Durable table comments содержат snapshot digest, table OID и structural catalog signature (columns/defaults/constraints/indexes/triggers/policies/rules/automatic dependents/ownership metadata). Перед down блокируется весь owned set и сверяются все receipts до первого удаления. Изменённые/заменённые таблицы, новые columns/indexes/owned sequences и потерянные receipts блокируют rollback. Скопированный comment не даёт владение same-name replacement с другим OID. Поздние независимые таблицы сохраняются, FK descendants блокируют DROP RESTRICT. Транзакции/вложенные savepoints обеспечивают rollback всего partial DDL; injected middle-up failure и поздний reverse failure проверены реальным PostgreSQL.

Schema predicate additions — только типизированные data predicates `byte_length`, `matches`, `json_object`; произвольного module SQL нет. Literal defaults экранируются независимо от `standard_conforming_strings`, identifiers проверяются conservative policy. Обязательный frozen SQL contract имеет приоритет над общим skill-шаблоном миграций через current model table name. Применены skills package-testing/isolation/preflight, `php:database` и `php:named-arguments`; публичный domain API остаётся без ORM/Internal.

## Проверки

Все PostgreSQL-запуски — через `python3 scripts/testing/pg-run.py`. До fixture mutation PgTarget подтверждает label собственного контейнера, exact private loopback endpoint, точное owned `*_test` database name, PG version и run marker. Каждый законченный wrapper очищает только свой контейнер. Два прерванных capacity прогона не засчитаны: partial outputs находятся в `interrupted/`, orphan containers/manifests удалены по exact IDs и ownership proofs (`cleanup-interrupted.log`). Новый `planner start` не выполнялся.

Все пять обязательных команд из context pack повторены буквально после продолжения той же owning session. PHP — установленный `/usr/bin/php8.4` 8.4.27 через временный PATH shim; env digest и actual stdout/stderr сохранены.

| Check | Результат | Exit |
|:--|:--|--:|
| V1 | 7 passed, 200 assertions: production-generated catalog/constraints/indexes, module v1/v2 CRUD, P2 surface/receipt/envelope/outbox, replay, unique claim duplicate/rollback с реальным lock barrier, атомарный rollback | 0 |
| V2 | 19 passed, 117 assertions: fresh-process old snapshot, changed config/prefix/search_path, aliases/PDO/driver/routing, integrity, unmanaged objects, ownership/descendants, atomic failure/savepoint | 0 |
| V3 | 48 passed, 442 assertions: реальные arch gates и negative controls | 0 |
| V90 | PHPStan No errors, без baseline/suppressions | 0 |
| V91 | Pint passed, без ослабления правил | 0 |
| V92, дополнительный | Schema + Runtime regression: 265 passed, 3861 assertions; strict teardown baseline=0 final=0 и retained-client negative control | 0 |

Финальные evidence JSON соответствуют native `check-evidence` + `defs`: actual command/cwd, exit/timestamps, actor/session, base HEAD, source fingerprint, aggregate log SHA-256 и отдельные stdout/stderr. Source inventory: 613 файлов. Source fingerprint: `6f63bb03e3a2135eacd72670a644225b170eee224ce64acf60e9dfdda974cf36`. Проверки с missing/skipped/incomplete/warning/risky/timeout не засчитываются как pass.

Начальные failures сохранены в `red/`, `attempt-1/…attempt-4/`, `pre-teardown-fix/`: отсутствующий renderer, неполная decoy fixture, типы Laravel Migration в тестах, raw read/write routing guard. Все исправления проверены повторно.

Повторный failure старого ConnectionCleanupTest был диагностирован без изменения его исходного assertion: в 200-цикловом count-based reproducer на итерации 133 наблюдался count=1, а pg_stat_activity показал `autovacuum: VACUUM ANALYZE pg_catalog.pg_class`, PID 1040. Через 0.093 секунды worker завершился; 200 циклов закончились `baseline=0 final=0 transient_observations=1 persistent_leaks=0` (`cleanup-count-diagnostic.log`). Раннее предположение о задержке закрытия PDO не подтвердилось: проверка ошибочно считала PostgreSQL background worker утечкой client connection.

По явной директиве владельца при продолжении к task_files добавлен только `tests/Integration/Runtime/Infrastructure/ConnectionCleanupTest.php` (`teardown-taskfile-registration.json`). Предикат ограничен `backend_type='client backend'`; все 35 циклов teardown и точное равенство baseline сохранены. Новый negative control открывает реальное дополнительное owned PDO: оно обязательно увеличивает число clients на 1; после close число возвращается к baseline. Никаких allowlists PID, отключения autovacuum, sleep/retry, skip или ослабления leak detection нет. Временная DEBUG-инструментация из source удалена. Пять обязательных проверок повторены после этого исправления; финальный расширенный regression завершился exit 0: 265 tests, 3861 assertions; реальный retained-client negative control passed.

Чужие `.gitignore`, `.serena/project.yml`, `CLAUDE.md` и 430 исходных outside dirty/untracked файлов сохраняются по SHA-256 inventory. Routes/global policy/следующий item не изменены. Social modules, comments, генерация моделей и additive upgrade commands находятся вне P3.3; fixture acme.demo-targets не является social e2e.

Review mode остаётся requested. Fresh read-only review и native review receipt принадлежат координатору; writer их не создаёт. После проверки evidence — только разрешённый planner commit с предварительным dry-run, затем finish с фактическими checks/evidence/commit. Post-commit результат finish сохраняется отдельно в finalization/finish logs, без подделки review и без blocked из-за ожидаемого review.

`verify-evidence.py` завершился exit 0: проверены native schema, literal commands/exits, SHA-256 всех логов, 613 source files, SQL assertions и сохранность 430 outside files. `git diff --check` — exit 0; после всех PG checks собственных контейнеров не осталось.

Planner commit dry-run — exit 0. Проверены все 13 selected source paths: только Schema/stub, новые owned schema fixtures/tests и явно разрешённый teardown test. Native workspace selection: только log.jsonl и P3.3 item. Все 430 outside paths оставлены вне commit; preview находится в `commit-preview.json`.
