# PLAN1 P4.3

Session: `01a18611-bab0-7fb3-93fc-2d5e19c54d73`; actual runtime: codex / gpt-6.1-sol / high / danger-full-access (session metadata verified in `runtime.json`). Base: `3f21fa8c6c69bf1ea93d6929fa3b55cb6a9dd975`.

`Reactions::summary/summaryMany` возвращают только counts и viewer-own kinds. Current trusted context, surface lifecycle/admission, action permission и target resolution проверяются до projection SQL и повторно после чтения всех batch-кандидатов. Изменение generation/privacy отклоняет результат. Missing/hidden/deleted/revoked/expired/error имеют одинаковый `ContentHidden`, без counts/membership; duplicate/missing batch keys отклоняются. Кэш разрешений и публичный roster отсутствуют.

`Reactions::rebuild` требует `ProjectionOnly`, явное host permission `reactions.rebuild` и current authority/target fences. Одна owner transaction блокирует постоянную surface-строку → trusted target → memberships, проверяет generations, заменяет только counts точной installation/panel/surface/target incarnation. Отозванный reactor не удаляется из authoritative membership. Receipt/outbox/delivery/host/surface state не изменяются. Отказ/ошибка прерывает операцию; rollback сохраняет прежнюю проекцию. Kernel, add/remove, schemas и routes не менялись.

| Literal check | Exit | Evidence |
| --- | --- | --- |
| V1 | 0 | `V1.json`, `V1.log` — guarded counts, scalar/batch, two panels, revoke/expiry, late batch revoke |
| V2 | 0 | `V2.json`, `V2.log` — exact repair, repeat, old incarnation, six independent-process PG barriers, rollback/SIGKILL, historical state unchanged |
| V89 | 0 | `V89.json`, `V89.log` — architecture gates |
| V90 | 0 | `V90.json`, `V90.log` — PHPStan, no errors/suppressions |
| V91 | 0 | `V91.json`, `V91.log` — Pint |

PG запускался исключительно через `scripts/testing/pg-run.py`: invocation-owned container/private endpoint/volume, `*_test`, server ≥16 и run marker доказаны до fixture DDL. Оба контейнера удалены wrapper. Логи сохраняют stdout/stderr; `check-evidence` schema, literal commands, log SHA-256 и final source fingerprint проверены `verify-evidence.py` (`evidence-validation.log`).

Исходные failures сохранены: V1 fixture admission во вторую панель; V2 Pest assertion с интерфейсом Throwable; V90 строгие типы; дополнительный batch-тест первоначально ожидал raw key вместо digest в SQL bindings. Исправлены и все обязательные checks повторены на финальном source. PHPStan печатает существующую startup diagnostic о недоступном optional turbo extension; анализ завершён без ошибок, exit 0, окружение/правила не изменялись.

Review mode none; другие агенты не запускались. Чужие dirty/untracked файлы сохранены. Push/tag/publish/внешних сообщений не было.
