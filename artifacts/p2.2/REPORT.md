# PLAN1.P2.2 — Panel-bound operation storage

Выполнен только P2.2. Owning executor: `codex/gpt-6.1-sol/high`, session `01a182d8-41bf-7ab3-a620-bdd0476c60b5`. Actual model/effort взяты из runtime turn context (`runtime.json`), а не из назначения route. Native start выполнен через project launcher. Base: `50469600b6ffde7b863cdf5db41cbc1939961796`. `review.mode=none`; другие агенты и независимое ревью не запускались.

`OperationHandles::forSurface()` получает текущий trusted ActorContexts, проверяет panel/kind/host binding и полноту четырёх kernel table maps. Readonly handle содержит surface, actor/installation и immutable panel/surface definitions. Handle — снимок, не authorization token: runtime entry сравнивает его с текущими trusted facts и registry; caller construction, PHP serialization, чужие actor/installation/generation/storage/namespace не дают authority. Action authorization/admission, target resolution и полный operation envelope остаются соответствующим будущим P2 items.

Внутренний OwnerStorage выбирает explicit connection из frozen descriptor. OwnerConnection предоставляет repositories только известных logical entities, собственную owner transaction и owner afterCommit. Active nested owner transaction отвергается до callback. Driver prefix добавляется Laravel grammar ровно один раз к имени таблицы, schema квалифицируется отдельно. Итоговые ASCII identifiers проверяются на 63 bytes без усечения/нормализации; смена prefix после binding отвергается. Нет global config/database.default/currentPanel mutations, Eloquent model switching, SQL/DDL в boot или distributed transaction promise. Lazy scoped bindings не захватывают текущий контекст в singleton.

## Проверки

Все команды, cwd, exit, timestamps, environment identity, отдельные stdout/stderr и hash общего лога находятся в `V*.json` / `V*.log`. `run-checks.py` сохраняет native mode/blob source fingerprint и проверяет его неизменность после каждого check. `source-manifest.json` охватывает все implementation/test files P2.2; evidence artifacts исключены из самоссылочного source digest.

| Check | Actual result |
| --- | --- |
| V1 | exit 0; 5 tests, 20 assertions; trusted factory, immutable refs/storage, unknown panel/kind/host/map, forged and serialized snapshots, foreign namespace, stale installation/generation |
| V2 | exit 0; 4 tests, 93 assertions; real PostgreSQL 16.14, owner/default-decoy DBs, schemas/prefixes, four logical entities, rollback/commit/afterCommit, B and decoy preservation, A→B→A/nesting/Fiber interleaving, before-SQL refusals and 63-byte boundary |
| V3 | exit 0; 56 tests, 550 assertions; boot fail-on-connect spy with three declared panels, existing real config:cache/fresh bootstrap and architecture gates |
| V90 | exit 0; PHPStan level max, No errors; no suppressions or baseline changes |
| V91 | exit 0; Pint passed |

Skipped/incomplete/warning/risky fail flags включены в каждом обязательном Pest запуске. Дополнительный scoped Rector dry-run завершился без изменений; `git diff --check` прошёл. PHPStan/Rector сохраняют существующее окруженческое PHP startup warning об optional turbo extension; команды завершились с exit 0, diagnostics PHPStan отсутствуют. Исходные неуспешные итерации сохранены отдельно и не выдаются за pass.

## Изоляция до мутаций БД

Каждый PG запуск проходил P2.1 runner/bootstrap: новый owned PostgreSQL 16 Docker container, exact run label + loopback endpoint, случайные owner/decoy `*_test` names, database comment marker и server version. Только после проверки этих фактов устанавливаются test-only schemas. Laravel connections отдельно проверены SQL `current_database()` и реальными backend PIDs. `isolation.json` и V2 streams содержат наблюдавшиеся container/DB/marker/PIDs; credential manifest не копируется в evidence. Ambient DB env удаляется bootstrap; dev/prod не использованы. После запусков owned test containers отсутствуют.

На owner DB тесты используют `panel_a.driver_a_*` и `panel_b.other_b_*`, а другая DB содержит те же `panel_a.driver_a_*` имена. SQL listener проверяет routing и fully resolved names; mutation/rollback A сохраняют B и decoy. Одинаковые surface kind/host `article:42` различаются panel identity. В negative-map fixture используется допустимый низкоуровневый P1 descriptor с неполной map: fluent P1 builder всегда дополняет kernel defaults. Это намеренный fixture registry, не изменение production compiler.

Test-only schema, acme.runtime fixture и interleaved handles не заявляются social e2e, production schema readiness или полной Fiber isolation host resolvers. DDL/generators/models принадлежат P3, targets — P2.3, lifecycle/receipts/outbox/envelope — следующим P2 items. Cross-panel A+B atomicity не обещается.

## Сохранность и завершение

`preservation.json`: 410 чужих dirty/untracked файлов сохранены побайтно. Только task_files P2.2 включаются в scoped commit; plan state меняется native API, generated views вручную не правились. `plan-check.json` сохраняет native plan validation: 0 ошибок, 9 существующих execution_prompt_heavy предупреждений. Native finish использует V1/V2/V3/V90/V91 evidence; дальнейшие items не исполняются. Push/tag/publish не выполнялись.
