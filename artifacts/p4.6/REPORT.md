PLAN1.P4.6 — исправление F1 Major после RED от reviewer `01a18650-f40e-70c1-a701-d17bd7030171` (codex/gpt-6.1-sol/xhigh, read-only; finding передан владельцем). Owning session прежняя: `01a18642-887a-7ff0-9077-9a1aed0aab00`, codex/gpt-6.1-sol/xhigh, danger-full-access. Base исправления: `2de17368962128d0fd96799aefe7637c281e7de4`; повторный planner start не выполнялся.

Generation production PostTarget больше не хэширует sampled host verdicts, expires_at или lifecycle. Hash содержит стабильные owner installation/surface/post ID/incarnation, revision/privacy/safety/consent и host installation/target+incarnation/privacy/generation. Текущие read/write права, TTL, lifecycle, safety, audience и локальные fences проверяются прежними guards отдельно от generation; kernel/registry/routes не изменены.

Регрессия на прежнем коде воспроизвела меняющуюся generation и сохранённую reaction с отклонённым acknowledgement; RED V1 сохранён среди *-attempt-*. Production registry tests теперь проверяют fixed/renewed host/admission TTL, scalar/summaryMany counts, успешный initial acknowledgement, same-key replay, module/generic lookup и переход в допустимый read_only. Отрицательные проверки сохраняют отказ при expiry/revoke, запрет новых writes в read_only и invalidation при изменении host privacy/generation.

| Проверка после F1 | Exit | Результат |
|---|---:|---|
| V1 | 0 | 30 tests, 618 assertions |
| V2 | 0 | 10 tests, 493 assertions; реальные PG barrier races |
| V3 | 0 | 72 tests, 1430 assertions; P2 guards/receipts/atomicity/tombstones |
| V89 | 0 | 53 arch tests, 584 assertions |
| V90 | 0 | PHPStan max, memory-limit=512M, без suppressions |
| V91 | 0 | Pint |

Все команды выполнены буквально из item. PostgreSQL — только через pg-run.py: owned container/loopback endpoint/exact *_test databases/run marker/PIDs доказаны до тестовых мутаций; B/decoy sentinels сохранены. Skipped/incomplete/warning/risky не засчитываются pass.

Актуальные V1/V2/V3/V89/V90/V91.json и .log содержат command/cwd/exit/timestamps/source fingerprint/log SHA-256 по schema check-evidence и один и тот же source digest исправленного кандидата. Предыдущие попытки, V92 и initial finalization/finish logs — исторические evidence прежнего кандидата, не подтверждение исправления F1. Source maps сохранены в source-fingerprint.json и source-fingerprints/.

Исправленный кандидат требует нового independent review. Reviewer не запускался исполнителем; review receipt не создавался, finish и blocked не вызывались. После dry-run создаётся scoped executor commit; после него закоммиченные файлы остаются неизменны.
