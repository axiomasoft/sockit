# PLAN1 P4.8

Выполнен отложенный P3 production smoke на настоящем `sockit.reactions`.
Свежий Composer consumer устанавливает копию пакета без symlink/root autoload;
lock обновлён реальным `composer update axiomasoft/sockit --no-install --no-scripts --no-interaction`.

- Article/Product с host ID 42: generate → explicit registration → config:cache/fresh boot без SQL → production migrate → public surfaces/reactions add, replay, lookup, counts, remove/CAS.
- Две матрицы: одна БД/разные prefixes и разные owner connections/БД. Panel, target alias/incarnation, actor/kind/key, receipts/outbox изолированы. Reactions-only boot не загружает PostsModule; posts tables и внешние пакеты отсутствуют.
- Custom model map, casts/hydration, A→B→A и fresh-process queue restore; generated posts relations/queue проверены после additive enable.
- Hide, revoke, archive и injected outbox failure сохраняют guards/atomicity. Additive posts enable только Article не меняет reactions/Product. Disable/re-enable сохраняет реальные post/revision rows и pending delivery без drain/duplicates.
- SHA256 custom files/истории сохраняются; повторные/dry-run команды не перезаписывают файлы. После prefix и consumer-only reactions schema v2 drift генератор отклоняет rebinding; frozen rollback точных Article paths/batches сохраняет Product data/receipts/outbox и host sentinels. Product public remove/re-add работает после rollback.
- PostgreSQL только через pg-run.py; container/endpoint/run marker/exact *_test доказаны до DDL/SQL мутаций.

| Check | Результат | Exit |
|---|---|---|
| V1 | 1 test, 438 assertions | 0 |
| V2 | 2 tests, 961 assertions | 0 |
| V3 | 1 test, 583 assertions | 0 |
| V89 | 53 architecture tests, 596 assertions | 0 |
| V90 | PHPStan: No errors | 0 |
| V91 | Pint passed | 0 |
| V92 (impact) | прежние generated/standalone consumers: 2 tests, 1277 assertions | 0 |

V1/V2/V3/V89/V90/V91 исполнены буквально; нет skipped/incomplete/warning/risky tests.
Существующий optional PHPStan turbo startup warning отражён в V90.log; анализ завершился No errors без suppressions/baseline.
Check-evidence JSON валидны по native schema; SHA256 логов и source fingerprint проверены.
Промежуточные failures и исправления записаны в iterations.json; их большие logs/dry-runs оставлены вне commit.
PANEL-05/08 production evidence и P4-части PANEL-01/06/07/09/11 подтверждены фактическим consumer.
Runtime/kernel/routes и принятые результаты P2/P3/P4.3/P4.6/P4.7 не изменялись.

Session: `01a1869b-5e00-7330-b9d5-9286df8d609f`; runtime `codex/gpt-6.1-sol/high`, `danger-full-access`, proof: runtime-proof.json.
Review mode none; без subagents/reviewer/push/tag/publish/внешних сообщений.
