# PLAN1.P2.7 — correction 1, candidate для targeted round 2/2

Оба подтверждённых Major основного RED review исправлены в рамках P2.7.
Независимое принятие исправлений ещё не получено: **native status in_progress,
V99 pending, finish не вызывался**. Другие агенты/LLM-сессии не запускались.
Actual owning runtime: codex/gpt-6.1-sol/xhigh, session
`01a18356-1ebf-7ef1-877f-01d40534fd40` (`runtime.json`). Project launcher start
возобновил P2.7 с route=ok (`start.json`).

Последний agent_message основного reviewer прочитан и сохранён без изменений:
`review-round1-message.json`, источник/хеш в `review-source.json`. Reviewer сообщил
round 1/2 RED, 2 Major, 0 Blocker; receipt-файл не создавал. Новый receipt за reviewer
не фабриковался; normal native start возобновлён в уже авторизованном owning scope.

## Исправления

R1-M1: обязательный scoped MutationAuthorityFence подтверждает текущую composite
host authority локально после всех owner locks и до persist. Capture расположен
перед current guards вне tx; revoke writer должен участвовать в той же локальной
fence границе. Target/surface token сам по себе не доказывает action authority.
Revoke/stale/unproven/missing provider откатывают claim до state/revision/outbox;
fresh resolution выполняется вне tx, без автоматического повторного persist.
Kernel lifecycle использует ту же проверку. Подробные SQL assertions и оба
revoke+holder rollback barrier — в FINDINGS.md и V2-final.log.

R1-M2: existing lookup авторизует сохранённый action, не commandType. Для absent
module key введена явная scoped MissingReceiptPolicy, сопоставляющая namespace с
реальным action; Unknown/missing provider/mapping fail closed. Чужой actor key
получает ту же политику, что отсутствие. Kernel lifecycle mapping точен и встроен.
P1 contracts, public execute/lookup signatures и problem registry не менялись.

Изменены только 14 source paths исправлений (correction-delta.json): envelope,
два новых P2.7 host SPI, узкие PG fixtures/tests и owning architecture docs.
Native task_files расширены на эти SPI; expectations V2/V3 уточнены. Другие items
не исполнялись. Независимое review и дальнейший P2.8 не запускались.

## Изоляция и actual checks

Все PG проверки запускались неизменённым P2.1 scripts/testing/pg-run.py в отдельных
owned PostgreSQL16 containers. До fixture DDL проверены loopback endpoint/container
label, уникальные owner/decoy *_test, mode-0600 manifest, actual current_database,
server_version_num=160014, run marker и реальные PDO backend PIDs. Ambient DB env
очищается bootstrap; dev/prod/.env не использовались. PG-preflight.log и каждый
PG final log содержат proof. Cleanup удалил только соответствующие owned runs.

PG-missing.log: реальный отказ без manifest, exit 2 до DDL; negative safety result,
не product pass. Commands/cwd/exit/stdout/stderr/time/environment fingerprint и SHA-256
логов сохранены runner-ом, не переписаны из ожиданий.

| Check | Final actual result |
|---|---|
| V1 atomicity/faults/nested tx | exit 0; 12 tests / 196 assertions |
| V2 PG races, including revoke + rollback | exit 0; 11 tests / 421 assertions |
| V3 auth/lookup/generation/lifecycle | exit 0; 44 tests / 755 assertions |
| V4 targeted + all Arch | exit 0; 47 tests / 413 assertions |
| V5 affected surface/receipt primitives | exit 0; 29 tests / 520 assertions |
| V6 outbox/lease/crash regression | exit 0; 28 tests / 339 assertions |
| V7 SQL consumer inbox | exit 0; 7 tests / 103 assertions |
| V8 boot/config-cache/target/handle regression | exit 0; 61 tests / 178 assertions |
| V90 PHPStan max | exit 0; No errors |
| V91 Pint | exit 0; passed |
| V99 independent final receipt | **pending targeted round 2/2** |

Все final evidence относятся к одному source check digest
`f9488523f6b2e58eb4b47b9d76deb37dd91d770a4c060838519633621c4a8165`.
Native check-evidence/defs schema validation и хеши логов проверены
(`evidence-verified.json`). Docs checker: OK. Native plan check: 0 errors / 4
context-weight warnings (plan-check.log). Старый optional phpstan_turbo startup
warning сохранён; анализ завершён exit 0, без suppressions/baseline и перестройки env.

Исходные failed прогоны сохранены: исправлена test instrumentation для target-less
waiter, которому не требуется target resolver, и типизация SQL read counter. Финальные
checks выполнены после исправлений. Кодовые defects основного review и эти проверки
не выдаются за независимое acceptance. Fake/SQLite/social e2e доказательств нет.

## Новый замороженный candidate

- Base/HEAD: `03c852c9ef08bb0294a4facb17394d5453d3fabd`.
- Новый digest: `sha256:3632920a06b541e0939e275f5d526a65f7da4ed39bc15bd0f54aa5ec0db62d74`.
- Предыдущий reviewed digest: `sha256:1d2b93821807b52daf9cb42340219e996dd6d7d9540110ad1c76e5cef8842040`.
- candidate.json: HEAD + 148 expanded actual source paths с native mode/git blob hashes.
- candidate-source.tar.gz: точные новые source bytes; source-manifest.json: SHA-256.
- targeted.diff: только round-1 reviewed source → исправления (14 paths).
- candidate.diff: полный patch относительно owning base.
- review-request.json: единственный targeted round 2/2, fresh independent SOL/high,
  конкретные R1-M1/R1-M2 и новые affected invariants; source-bound receipt обязателен.

Старый candidate, его archive, report, evidence и все 104 primary-review artifacts
сохранены byte-for-byte. Сохранены 410 исходных чужих файлов и 535 защищённых файлов
текущего dirty workspace (`foreign-preserved.json`). Исходники остаются в working tree
и точном архиве; commit/push/tag/publish/finish не выполнялись.

Проверка готовой передачи:

```
python3 artifacts/p2.7/correction-1/verify-candidate.py
python3 -W ignore::DeprecationWarning artifacts/p2.7/correction-1/verify-evidence.py
python3 artifacts/p2.7/correction-1/verify-handoff.py
```

После единственного targeted re-review owning executor должен проверить настоящий
native receipt: subject P2.7, exact new base/head/digest/paths, actual reviewer != writer,
round<=2 и unresolved Major/Blocker=0. Только после принятого receipt — V99/native finish
и точный scoped commit. Старый RED candidate/receipt нельзя переименовать в новый.

Практическая граница нового контракта: host явно предоставляет composite local fence;
remote-only/unprovable authority не может разрешить запись. Для absent module lookup
нужна явная policy/mapping. Permissive defaults не добавлены. Это SQL fixture conformance,
не social domain или remote delivery e2e, не production schema/generator и не новый RBAC engine.
