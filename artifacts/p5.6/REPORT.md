# PLAN1 P5.6

Session `01a18760-969d-7e63-b42f-13500ad87716`; actual runtime `codex/gpt-6.1-sol/high`, `danger-full-access` (runtime-proof.json). Base HEAD `b8eae8e9455531db30ed09f36192c9b3d9604927`.

Реализованы installed-consumer acceptance и upgrade regression для production groups. Обновлён только SocKit path-package в consumer composer.lock: старый lock не содержал PSR-4/ownership metadata Groups и реальная установка не могла загрузить модуль. Production schema/runtime и routes не менялись.

Consumer устанавливает отдельную nonsymlink-копию текущего пакета, проверяет каждый установленный файл и dependency graph. Две панели с одинаковым host key `42` используют concrete models, свои tables и connections. Проверены config:cache без SQL, public create/join/read/replay, отсутствие creator membership, текущий host admission, casts, FK relations, A→B→A и шесть восстановлений queue models в отдельных процессах. Это локальный consumer сценарий clubs/subjects, не живой Edufy pilot.

На заполненных reactions-панелях добавлены groups/group_members через реальные additive migrations. Сохранены reactions/receipts/outbox, old migrations, provider/custom model bytes. Offered-but-disabled groups не создаёт таблиц. Alias connection не обходит storage collision. Реальный make-panel с запретом записи после установки provider откатывает частичные файлы, возвращает `GENERATION_WRITE_FAILED`, точный `recovery=[]`; retry успешен, повторный conflict сохраняет custom marker.

Disable сохраняет groups/members/schema history/envelopes и запрещает membership/read. Два настоящих pending group events переходят через public relay в durable quarantine с `outbox.consumer_unavailable`; re-enable не теряет и не доставляет их молча. Frozen additive down при изменённом prefix сохраняет reactions, затем initial down удаляет только Article. Product data/receipts/outbox и host sentinel сохранены; public Product leave после rollback работает.

Проверки выполнены буквальными командами context, без skip/incomplete/warning/risky tests:

| Check | Exit | Результат |
| --- | --- | --- |
| V1 | 0 | 1 test, 839 assertions |
| V2 | 0 | 36 tests, 1345 assertions; Groups upgrade и P3 additive/frozen/disabled regressions |
| V89 | 0 | 57 tests, 878 assertions; architecture и negative controls |
| V90 | 0 | PHPStan: no errors, без baseline/suppressions |
| V91 | 0 | Pint passed |

PG запускался исключительно через `python3 scripts/testing/pg-run.py`. До DDL/DML подтверждены invocation-owned container label, loopback endpoint, точные *_test database names, PostgreSQL 16.14 и database marker; owner/decoy identities находятся в V1/V2.log. Wrapper удалил свои containers/volumes. Каждый V*.json соответствует native check-evidence schema; log SHA256 и 872 source hashes перепроверены. Source digest: `2fa536cc774adacddec40a1bad89258df8822b5730e5d0b00cac6c611ce66648`. Installed package digest в V1/V2.log: `3ec992a045d6d2049ac6fe9d4ab2d200e4f2009845a7c1220f55922fae1d8d82`.

Первичный red: отсутствовал GroupsConsumer (exit 2); далее исправлены устаревший consumer lock и ошибки нового harness. Зачётные V1/V2 выполнены повторно на финальных source bytes. V90 сохраняет существующее нефатальное PHP Startup warning о недоступной PHPStan turbo extension; анализ завершён с exit 0 и без ошибок.

Independent review requested: reviewer не запускался, receipt не создавался. После native commit выполняется finish done с реальными checks/evidence; ожидаемый отказ из-за отсутствующего review фиксируется отдельно без изменения committed files и без blocked. Чужие dirty/untracked и следующие пункты не затронуты.
