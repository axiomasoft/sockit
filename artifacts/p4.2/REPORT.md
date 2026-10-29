# PLAN1 P4.2

Article reactions работают в reactions-only панели без Posts. Public `ReactionCommand` и
`Reactions::{digest,add,remove,lookup}` используют trusted operation handle и P2 envelope.
Actor/installation не входят в payload. Frozen module/feature/kind checks, current admission,
локальный host target fence и CAS предшествуют записи. Kernel P2 и routes не менялись.

Первый add создаёт revision 1. Remove и re-add сохраняют UUID membership, увеличивая revision.
Для существующей membership нужен expected revision. Свежий ключ для уже active add либо
inactive/absent remove даёт revision_conflict без изменения count/outbox. Identity включает
installation/panel/surface/target/incarnations/kind/actor; digest lookup проверяет полную строку.
Membership, неотрицательный counter, receipt и refs-only outbox атомарны на owner connection.
Replay/lookup заново проверяют текущие полномочия и target. Counts в receipt не копируются.

Проверки выполнены буквально, без skipped/incomplete/warning/risky тестов:

| Check | Exit | Результат |
|---|---:|---|
| V1 | 0 | 39 PG tests, 547 assertions: CAS, spoofing, current guards/replay, rollback на четырёх SQL stages |
| V2 | 0 | 8 PG tests, 236 assertions: independent processes, реальные PID/lock barriers, duplicate/different keys, add/remove, rollback, revoke/generation while waiting, lost response |
| V89 | 0 | 51 arch tests, 508 assertions |
| V90 | 0 | PHPStan max, no errors; без suppressions/baseline |
| V91 | 0 | Pint pass |

PG запускался только через `python3 scripts/testing/pg-run.py`. До DDL harness подтвердил
invocation-owned container label, loopback endpoint, точные *_test имена, PG 16 и run marker.
Production snapshots создают схему; host adapter владеет host SQL. Точные SQL cardinalities,
B/decoy sentinels, PID и блокировки сохранены в V1/V2.log. Timeout не использован как pass.
V90 также выводит существующее PHP startup warning о необязательном PHPStan turbo extension;
анализ завершился exit 0/no errors, окружение и настройки не менялись.

JSON evidence проверены по native check-evidence schema; команды, hashes логов и общий
source fingerprint проверены `verify-evidence.py`. Ранние failures исправлены и checks повторены.
Runtime pin подтверждён session log: `01a18605-3df9-7f80-bfb1-46b7c12f8ba5`,
`codex/gpt-6.1-sol/high`, `danger-full-access` (runtime.json). Review mode none;
reviewer/subagents/следующий пункт не запускались. Чужие dirty/untracked не изменялись.
