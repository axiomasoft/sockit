# PLAN1.P2.5 — Receipt claims

Исполнен только P2.5. Native start принят; фактический runtime — `codex/gpt-6.1-sol/high`, session `01a1832f-7b78-7db0-a966-18eee2934db4` (runtime.json). Другие агенты не запускались. Independent review отсутствует по `review.mode=none`; обязательные checks выполнены.

## Реализация

- Internal `ReceiptLedger` использует owner connection/table map неизменяемой панели. Claim: `INSERT … ON CONFLICT (namespace, command_key) DO NOTHING RETURNING id`; явный conflict target не скрывает другие integrity errors. После ожидания победителя отдельный SELECT в READ COMMITTED видит его committed row; отсутствующая строка не используется как claim lock.
- Ledger владеет полной owner transaction. Callback выполняет локальную mutation на том же owner; stable receipt записывается до commit. При исключении claim и effect откатываются. Отдельный committed pending claim не создаётся. Nested owner transaction и иной isolation profile отклоняются до эффекта.
- Namespace включает installation/panel/integration/commandType; indexed namespace/key в test snapshot используют `COLLATE "C"`, без усечения/нормализации. Digest пересчитывается по отдельно переданным server-validated commandType/payload; caller digest проверяется. Initiating actor, kind и onBehalfOf привязаны к receipt; foreign actor проверяется до digest/result.
- `ReceiptSnapshot` содержит только stable confirmed/rejected metadata, refs/revision/problem и key hash; тела и произвольных problem details нет. Pending/Unknown и problems с `receiptRecorded=false` не могут стать stable receipt. В логах SQL bindings и plaintext command keys отсутствуют.

## Изоляция до SQL mutations

Использован неизменённый P2.1 `scripts/testing/pg-run.py`: отдельный owned `postgres:16-alpine`, unique run label, private loopback port, случайные owner/decoy `*_test`, mode-0600 manifest. Ambient DB env удаляется bootstrap; `.env` не служит источником подключения. Перед fixture DDL проверены container label/endpoint, `current_database()`, PostgreSQL `160014`, database marker и реальные PDO backend PIDs; Laravel connections сверены с owner/decoy identity. Начальная read-only проверка: PG-preflight.log. V1 и V2 создавали собственные контейнеры и повторяли proof; cleanup удалил только принадлежащие этим runs контейнеры. Dev/prod не использованы.

Негативный запуск без manifest — PG-missing.log: реальный exit 2 в bootstrap до fixture DDL; это ожидаемый отказ guard, не зелёный product check.

## Реальные проверки

| Check | Результат | Evidence |
|---|---|---|
| V1 | exit 0; 5 tests / 143 assertions | V1.json, V1.log |
| V2 | exit 0; 10 tests / 123 assertions | V2-final.json, V2-final.log |
| V3 | exit 0; 9 tests / 1415 assertions | V3.json, V3.log |
| V90 | exit 0; PHPStan max: No errors | V90.json, V90.log |
| V91 | exit 0; Pint passed | V91-final.json, V91-final.log |
| Дополнительно Arch | exit 0; 45 tests / 250 assertions | ARCH.json, ARCH.log |

Команды, cwd, фактические exit/stdout/stderr, environment identity, candidate digest, timestamps и SHA-256 логов сохранены. Пять обязательных evidence проверены по native check-evidence/defs schemas и checksum логов (evidence-verified.json). Native plan check: 0 ошибок, 6 существующих `execution_prompt_heavy` warnings; они не продуктовые failures.

Четыре barrier races подтверждены `pg_stat_activity` и `pg_blocking_pids`: same key/digest — один callback/effect и одинаковые receipt/resource/revision; different digest — один effect и idempotency_conflict; rollback holder — waiter становится единственным committed writer; foreign actor — отказ без result. В каждом процессе SELECT 42 работает внутри той же tx непосредственно после ON CONFLICT, а также после завершения; unfinished receipts=0, decoy sentinel сохранён.

Lost response — отдельный PHP process commit-ит, закрывает PDO и завершается до выдачи snapshot; original-key lookup/replay находит тот же resource/revision без повторной mutation. Проверены panel/installation/integration/commandType, case-sensitive keys и предельные длины; invalid digest/type/bindings/anonymous, foreign actor lookup/replay, callback fault, non-recordable rejection, Pending/Unknown и content details не дают committed success. Посторонний unique index вызывает настоящий 23505 и rollback, не duplicate recovery.

Первичные ошибки PHPStan и последующие исправления типов сохранены в initial/second/failed logs; suppressions/baseline не добавлены. Последняя правка V2 была null-safe проверкой SQLSTATE; V2 и Pint повторены после неё. V1/V3/Arch уже прошли на неизменённой проверяемой реализации. Существующий optional phpstan_turbo startup warning остаётся в логе; PHPStan завершился exit 0 / No errors, environment не перестраивался.

## Границы и сохранность

Это внутренние receipt primitives и test-only acme.runtime effects, не публичный guarded replay и не social e2e. Текущие action/admission/containment/target/lifecycle guards на всех защищённых выходах и полная state/revision/receipt/outbox atomicity принадлежат P2.7. Pending external outcomes, retention, production DDL/generation не реализуются этим item. P2.7 должен встроить локальные replay guards под owner locks перед выдачей результата; snapshot не даёт права доступа.

410 исходных чужих файлов сохранены byte-for-byte (foreign-preserved.json). Изменены только заявленные новые P2.5 source/tests и artifacts/p2.5. Native finish и scoped commit выполняются project launcher; records — finish.json и native commits. Push/tag/publish не выполнялись.
