# PLAN1.P2.3 — Host target SPI и capability registry

Owning executor: `codex/gpt-6.1-sol/high`, session `01a182e5-87da-7130-9539-ae8774485e5f`.
Actual route прочитан из runtime `turn_context`, не выведен из plan pin: `runtime.json`.
Launcher start: `start.json`. Независимое review не назначено (`mode=none`); другие агенты не запускались.
Native start/finish выполнены: `start.json`, `finish.json` (status done). Scoped commit идентифицируется Git trailers PLAN1.P2.3 и native project journal; selection — `source-paths.json` и `artifacts/p2.3/**`.

## Реализация

- Public `TargetRef`, `TargetResolution`, `TargetLifecycle`, `TargetResolver`, optional `TargetWriteFence` в namespace Surfaces. Lossless host keys, incarnation, explicit surface/panel/installation, read/write verdicts, privacy revision/generation, versioned wire и 15 fixtures. Новые ProblemCode не добавлялись.
- `ModuleRegistrar::target(alias,resolver)` собирает immutable FQCN declaration. Private graph компилируется вместе с панелями/surfaces; только selected-on-panel capability доступен. Duplicate aliases в registrar/одной панели дают configuration_invalid до publish. Одинаковый alias на двух независимых панелях разрешён. Late target registration отвергается; прежние P1 declaration snapshots сохраняют совместимость.
- Explicit `sockit.resolvers.targets` binding — scoped host fallback для объявленных host aliases; module resolvers разрешаются явно через container на каждый вызов. Singleton registry хранит только FQCN, не actor/installation/adapter. Config cache и fresh bootstrap сохраняют bindings без SQL.
- `OperationHandles::resolveTarget()` выполняет current trusted context/panel/surface checks и admission до host resolve вне owner transaction; не доверяет foreign refs/installation, unknown/lifecycle/expired visibility. Read-only admission не превращается в write, конечный admission expiry сохранён. `Surfaces` coordinator использует те же guards и не предоставляет незащищённого runtime dispatch.
- `recheckTarget()` допускает только local fence в активной owner transaction; missing fence/errors → Unknown. Adapter владеет своими host SQL и lock, проверяет текущие privacy revision/generation/incarnation/visibility. Remote resolver, actor/tenant/admission callbacks под lock не вызываются. FakeTargetResolver/SocKitFake default deny и не заявляют local fence.

Через native API расширены только task_files P2.3: `Modules/Modules.php` (аддитивная форма declarations) и существующий `Surfaces/Surfaces.php` (boot coordinator и guarded dispatch). Данные плана не редактировались напрямую. Sources: `source-paths.json`, hashes: `source-candidate.json`; base `307ffcd`, final check dirty_digest `675ae6ca20f56907…` одинаков у всех V1/V2/V3/V4/V90/V91.

## Выполненные проверки

| Check | Actual result | Evidence |
| --- | --- | --- |
| V1 (required) | exit 0; 32 tests / 1475 assertions | V1.json + stdout/stderr/log |
| V2 (required arch) | exit 0; 45 tests / 250 assertions | V2.json + stdout/stderr/log |
| V90 (required PHPStan max) | exit 0; No errors; без suppressions/baseline | V90.json + stdout/stderr/log |
| V91 (required Pint) | exit 0; passed | V91.json + stdout/stderr/log |
| V3 (additional real PG fence) | exit 0; 1 test / 28 assertions | V3.json + stdout/stderr/log |
| V4 (affected boot/config/cache/modules/panels/surfaces/handles) | exit 0; 60 tests / 777 assertions | V4.json + stdout/stderr/log |

Все Pest команды использовали fail-on-skipped/incomplete/warning/risky; 0 skipped. Evidence содержит actual argv/cwd, exit, timestamps, platform/PHP/session, source digest и SHA256 log; stdout/stderr сохранены раздельно. PHPStan сообщает прежний optional turbo extension startup warning; он сохранён в raw log, analyse завершился exit 0. Initial syntax/typing failures и previous passes сохранены (`test-*.log`, `analyse-*.log`, `before-final/`), не засчитывались как final pass.

## Изоляция PostgreSQL доказана до test DDL/DML

Использован неизменённый P2.1 `scripts/testing/pg-run.py` и fail-closed `PgTarget` bootstrap/RuntimeDatabase:
новый owned Docker container с run label, private `127.0.0.1:32798`, exact owner
`sockit_p2_add4b04c6bc41b0c_test`, exact decoy `sockit_p2_decoy_add4b04c6bc41b0c_test`.
Container ID `1d48821531282b772a562e1c68493caab378b3bd974fac3a14fedeb74801c2a5`.
Server `160014`; run marker `sockit-p21-add4b04c6bc41b0c`. PDO bootstrap/owner/decoy backend PIDs 102–105;
Laravel `owner_a=106`, `owner_b=107`, `decoy=108`. Identity/version/marker проверены **до** fixture schema creation.
Ambient DB env очищен bootstrap; `.env` dev/prod не использован; все мутации только test-owned targets.
Нет production migrations/generators и `migrate:fresh`.

SQL assertions: 4 локальных SELECT FOR UPDATE на `owner_a`, точная host fixture table
`panel_a.driver_host_targets`; committed stale generation, отдельно recycled incarnation и privacy revision
не проходят recheck; remote-only/failing fence не разрешает запись; remote resolve count остаётся 1 под lock;
owner receipt сохраняется, decoy sentinel неизменён. После теста fixture schema и exact owned container удалены harness.

Negative bootstrap: `PG-missing.json/log` — actual exit 2 `Missing private PostgreSQL run manifest`, до DDL;
это ожидаемый отказ защиты, не зелёная native validation. Credentials/private manifest в evidence отсутствуют.

## Границы результата и сохранность

Это SPI/runtime seam, не social e2e: Reactions/Posts/Chatom/Forum не реализованы и не импортированы.
Remote read — sampled current resolution, без cross-DB atomic revoke. Полный порядок receipt→surface→target,
action/admission/containment и commit envelope принадлежат P2.7; Allow target fence сам по себе не авторизует
всю операцию. Будущий P4 posts использует тот же public capability slot. Подробности: `docs/architecture/spi.md`.

Первоначальные 54 индивидуально перечисленные dirty file fingerprints не изменены (`foreign-preserved.json`).
Initial git status сворачивал untracked directories, поэтому это не полный подсчёт всех чужих файлов.
Scoped commit выбирает только 47 source paths этого item и `artifacts/p2.3/**`; чужие paths не включаются.
Plan check: 0 errors, 8 execution_prompt_heavy warnings — `plan-check.json`. P2.4 не запускался.
