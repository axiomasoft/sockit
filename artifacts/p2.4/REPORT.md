# PLAN1.P2.4 — surface instance lifecycle / CAS / tombstones

Owning executor: `codex/gpt-6.1-sol/high`, session `01a182f8-ea14-73d1-b32e-5bb6d4f39f54`.
Actual model/effort взяты из runtime `turn_context` (`runtime.json`), а не из pin плана.
Native project launcher: `start.json`, `finish.json`. Только P2.4; другие агенты не запускались,
independent review не назначено (`mode=none`). Scoped native commit использует только 9 source paths
из `source-paths.json` и `artifacts/p2.4/**`; его SHA/selection записываются native journal и Git trailers.

## Результат

- Public immutable `SurfaceInstance` snapshot и string enum `SurfaceLifecycle` (Live/Archived/Deleted).
  UUIDv7, complete surface ref, trusted installation, revision, source/privacy generations,
  UTC creation/update/deletion timestamps. Archived допускает чтение, запрещает запись; Deleted не читается.
  Snapshot не является authorization token. Public lifecycle mutation API не добавлялся.
- Internal `SurfaceInstances` использует конкретный `OwnerConnection` и его единственный handle.
  SQL identity = installation + panel + kind + lossless host type/key; incarnation входит в полную ref/CAS.
  Permanent current row сохраняется при delete. Initial create использует INSERT UNIQUE ON CONFLICT,
  затем row lock; отсутствующая строка не используется как claim lock. Все writes требуют собственной
  активной owner transaction. Global config/default connection/static context не меняются.
- CAS по expected revision, без автоматического retry. Live↔Archived, Live/Archived→Deleted;
  archived policy edits и Deleted→Live того же incarnation отвергаются. Source generation и local privacy
  generation — положительные монотонные последовательности, понижение в пределах incarnation запрещено.
  Это локальные surface sequence numbers, не opaque generation string из TargetResolver.
- Delete атомарно пишет content-free tombstone: installation/panel/kind/host/key/incarnation,
  surface UUID, max revision, source generation, deletion time. Нет body/actor PII/auto purge.
  Tombstone блокирует создание даже при повреждённом restore с отсутствующей current row.
- Только явный `recreate` с CAS удалённой revision, ровно следующей incarnation и source generation
  строго выше tombstone создаёт новый UUIDv7/revision=1. Старый tombstone сохраняется.
  Старые refs получают surface_incarnation_mismatch; deleted current ref — content_deleted.
- Repository и exception размещены в разрешённом `Operations/Internal/Storage/**` рядом с owner
  transaction primitives: A3 запрещает импорт foreign Internal из Surfaces. Исходный вариант такого
  импорта был выявлен arch-check и исправлен перемещением реализации; arch-гейты не менялись.
  DTO/enum остаются публичными типами Surfaces. Production DDL/generators принадлежат P3.

## Обязательные checks на одном финальном source candidate

| Check | Actual result | Evidence |
| --- | --- | --- |
| V1 lifecycle / real PG races | exit 0; 11 tests / 207 assertions | V1.json, stdout/stderr/log |
| V2 tombstone/reorder/rollback | exit 0; 3 tests / 47 assertions | V2.json, stdout/stderr/log |
| V3 contracts + arch | exit 0; 46 tests / 1639 assertions | V3.json, stdout/stderr/log |
| V90 PHPStan max | exit 0; No errors, no suppressions/baseline | V90.json, stdout/stderr/log |
| V91 Pint | exit 0; passed | V91.json, stdout/stderr/log |

Все Pest commands включают fail-on-skipped/incomplete/warning/risky. Нет skipped/incomplete.
Actual command/cwd/exit/time/log SHA сохранены; безопасные environment identities — в
`V*.environment.json`, их hash связан с check evidence. Каждый final check имеет один source digest:
`3d68440a5d7133febdc0e8435e16aecc6dce30564388217fed74ad506be82c10`.
`source-candidate.json` содержит hashes 9 изменённых source files и base HEAD.
PHPStan сохранил прежнее optional turbo extension startup warning в raw stdout; analysis exit 0.
Initial PHPStan/arch failures и промежуточные PG passes сохранены отдельно и не подаются в finish
как final evidence. `git diff --check` exit 0.

## Изоляция PostgreSQL до DDL/DML

Неизменённый P2.1 `scripts/testing/pg-run.py` создал два независимых owned PostgreSQL 16 контейнера:

- V1 container `2065eba863e0ee597b7278c87126d178bd5d6338a40ade0f120635b935ec24e1`,
  loopback `127.0.0.1:32803`, owner `sockit_p2_b00853b834e7c192_test`,
  decoy `sockit_p2_decoy_b00853b834e7c192_test`, marker `sockit-p21-b00853b834e7c192`.
- V2 container `893d48b7b91bcff82e7509fc13e8d37caee893181f43599060c8239c60e58c77`,
  loopback `127.0.0.1:32804`, owner `sockit_p2_22903c1b1fa33d3c_test`,
  decoy `sockit_p2_decoy_22903c1b1fa33d3c_test`, marker `sockit-p21-22903c1b1fa33d3c`.

Server version `160014`; bootstrap PDO PID=102, fixture PDO owner/decoy=103/104;
Laravel owner_a/owner_b/decoy=106/107/108 перед первым test DDL каждого suite.
Каждый fixture/child повторно проверяет exact manifest, owned Docker label/endpoint, DB/version/marker.
DDL запускается после этих проверок; raw stderr отмечает порядок. Ambient DB variables очищены
bootstrap, dev/prod `.env` не использован. Credentials/private manifest отсутствуют в evidence.
Fixture tables/schema и exact owned containers удалены harness; последующая проверка Docker
не обнаружила контейнеров с `sockit.test.run` label.

Negative check `PG-missing.*`: actual exit 2, `Missing private PostgreSQL run manifest`, до DDL.
Это ожидаемый отказ защиты, не acceptance pass.

## Что доказано SQL и мешающими операциями

V1 запускает настоящий repository в двух независимых PHP/PDO процессах. Для каждой пары
наблюдается `pg_stat_activity.wait_event_type=Lock` до release, distinct backend PIDs и actual child exits:
create/create (118/124), archive/archive (136/142), edit/edit (154/160), edit/archive (172/178),
archive/write (190/196), write/archive (208/214), archive/edit (226/232),
delete/replay (244/250), replay/delete (262/268).
CAS pairs дают ровно один commit и revision_conflict; write после archive даёт surface_archived;
replay после delete даёт content_deleted. В обратном порядке write/replay завершается до lifecycle change.
Все UPDATE SQL содержат revision/incarnation и complete installation/panel/host identity на owner_a.

V1/V2 доказывают rollback initial create, rollback delete после записи tombstone, rollback recreate,
а также SQL fault в tombstone INSERT после CAS update: current lifecycle/revision и tombstone
откатываются вместе. Проверены Live→Archived→Live, Archived→Deleted, duplicate/reordered/old source,
new incarnation только explicit, stale refs после recreation, неизменность предыдущего tombstone,
independent panel B с тем же host/kind ID, other installation и lossless `00042:edge` key.
Decoy sentinel и decoy surface table остаются неизменны.

Это internal repository conformance, не полный operation envelope / receipt replay / social e2e.
Current authorization/admission/containment и commit outcome orchestration принадлежат P2.7;
repository не вызывает remote authority под lock. Production migrations и social modules не добавлялись.

410 исходных dirty files побайтово сохранены (`foreign-preserved.json`: changed=[]).
Plan check: 0 errors, 7 execution_prompt_heavy warnings (`plan-check.json`).
