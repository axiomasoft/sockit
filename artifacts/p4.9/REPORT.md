# PLAN1 P4.9

Отдельный Composer consumer устанавливает копию production-пакета, генерирует и мигрирует панели `acme.community` и `acme.neighbor` для одного `class:42`. Через публичные API выполнены публикация учителя и service actor onBehalfOf, feed родителя с read-only, реакция ученика, повтор исходного ключа и изоляция панелей. Host SPI хранит отношения и текущие решения, package не обращается к школьным таблицам.

- SN-02/03, PANEL-01/10: другой installation и удалённый автор отклонены; parent не публикует и не реагирует; учитель состоит в двух классах, но публикация в соседний класс отклонена по containment. Панели имеют отдельные таблицы, receipts и ресурсы; соседний feed содержит только свой пост.
- SN-05/07/10/11/15: archive сохраняет чтение допустимого выпускника, запрещает записи; pending и held не попадают в feed/reactions, hide/restore сохраняет их состояние; повтор promotion возвращает тот же ресурс, stale CAS не создаёт ревизию. Direct → direct сужение лишает исключённого получателя body, истории/media, counts и receipts. Simulated erased state не публикуется; это не реализация P9 erasure.
- SN-08/09/12/13/14, PANEL-09: после host consent revoke сбой outbox откатывает hide и связанные SQL effects, чтение и lookup закрываются сразу; повтор исходного ключа выполняет hide. Restore требует текущего разрешения; delete оставляет terminal content-free revision и поздний replay отклонён. Число строк community в конце: posts 5, revisions 15, reactions 1, counts 1, receipts 24, outbox 17; таблица tombstones пуста: terminal marker здесь — deleted revision, не утверждение о полном P9 purge. Rebuild не создаёт исторических событий.
- PANEL-08: проверен standalone dependency graph без Chatom/Forum/AzGuard/Vaulter; production reactions-only acceptance — ранее принятый P4.8. Внешние search/notification/bridge, concurrent delivery и полный retention/erasure в этой проверке не реализованы и не заявлены как закрытые.

PostgreSQL: только `pg-run.py`, собственный контейнер и loopback endpoint; обе точные `*_test` базы и run marker проверены до DDL. Команды, вывод и source fingerprint — V1/V2/V89/V90/V91 evidence; schema check-evidence и SHA256 логов проверены в `evidence-validation.json`.

| Проверка | Exit | Результат |
|---|---:|---|
| V1 | 0 | 1 test, 427 assertions |
| V2 | 0 | 2 tests, 461 assertions |
| V89 | 0 | 53 tests, 596 assertions |
| V90 | 0 | PHPStan No errors; startup warning optional turbo extension в логе |
| V91 | 0 | Pint passed |

Session `01a186af-2dae-7ce2-a2af-a931febc37d2`, runtime `codex/gpt-6.1-terra/high`, `danger-full-access`; review.mode=none.
