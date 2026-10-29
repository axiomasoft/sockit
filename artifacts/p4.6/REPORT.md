PLAN1.P4.6 — owning writer: codex/gpt-6.1-sol/xhigh, session `01a18642-887a-7ff0-9077-9a1aed0aab00`, sandbox `danger-full-access`. Runtime подтверждён текущим turn_context; base HEAD `84c39f38916ce3599333e92a2e508d80ea41d091`.

Posts регистрирует canonical target `sockit.post` через public ModuleRegistrar. Совместимый `post` остаётся read-only для существующего generic receipt lookup и не создаёт второй reaction identity. Reactions использует существующие public contracts; sibling imports, SQL в posts и изменения kernel/routes отсутствуют.

Scoped resolver каждый раз проверяет текущие host/post audience, publication, lifecycle, installation, panel, surface и permanent incarnation=1. Одноразовая подготовка локального fence включает host snapshot: receipt → surface → host fence → post lock → reaction. Fence повторно проверяет post privacy/safety/consent generation; actor/audience/remote resolve под lock запрещены. Unprovable host fence отклоняется.

| Проверка | Exit | Результат |
|---|---:|---|
| V1 | 0 | 22 теста: host-only/enabled/disabled registry, refs/isolation, current guards, replay/rebuild, replacement |
| V2 | 0 | 10 тестов: 6 PG barrier orders hide/delete/shrink, revoke while waiting, restore/terminal/stale events, Held/Pending |
| V3 | 0 | 72 теста P2 envelope/receipt/generation/tombstone |
| V89 | 0 | 53 arch tests, включая negative sibling/SQL fixture |
| V90 | 0 | PHPStan max, memory-limit=512M, без suppressions |
| V91 | 0 | Pint |
| V92 (дополнительно) | 0 | Затронутые production regressions P4.2–P4.5; команда в V92.json |

Каждая PostgreSQL-команда выполнена через `python3 scripts/testing/pg-run.py`. До DDL/мутаций проверены invocation-owned container, loopback endpoint, exact *_test databases, run marker, PostgreSQL 16 и backend PIDs; B/decoy sentinels проверены. В barrier tests подтверждён `pg_blocking_pids` и реальное ожидание Lock, без skipped/incomplete/warning/risky pass.

Persisted event wires в обратном порядке проверены через текущий owner target lookup; stale refs/receipts не дают protected outputs и не меняют terminal state. External delivery/новый event consumer этим пунктом не реализуются. Rebuild изменяет только projection и не возвращает скрытые totals. Revoke может сохранить rejected receipt без resource/revision согласно P2; membership/count/outbox при этом не возникают.

Команды/cwd/exit/timestamps/merged stdout+stderr/log SHA-256 — V*.json и V*.log по schema check-evidence. Source maps — source-fingerprint.json и source-fingerprints/. После успешных V2/V3 изменена только подготовка cross-installation case в PostTargetTest.php; затронутые V1/V89/V90/V91 повторены. Неуспешные первоначальные попытки сохранены как *-attempt-*; они не засчитаны pass.

Independent review requested: reviewer не запускался, receipt не создавался. После scoped commit будет выполнен native finish с реальными checks/evidence/commit; ожидаемый отказ из-за отсутствующего review сохраняется отдельным post-commit finalization artifact без изменения этого отчёта.
