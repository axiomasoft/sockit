# PLAN1 P5.4 — исправление F1

Исправлен Major из RED review session `01a1872e-31f7-7613-9f15-cb6e877b3ec2`, переданный владельцем. `GroupTarget::recheck` теперь через kernel-owned `SurfaceInstances::recheck` блокирует текущую management SurfaceInstance на том же owner connection до host/group/post/reaction locks. Проверяются installation/panel/actor/frozen bindings, incarnation, live lifecycle, id, revision, privacy/source generations и остальные поля snapshot. Management lock удерживается до owner commit; host admission/authority остаются независимыми guards.

Добавлен узкий публичный kernel call API, без изменения implement-SPI, extension registry или импортов kernel Internal в Groups. Blocking surface locks следуют порядку installation/panel/kind/host type/key. Для dependency, стоящей раньше уже заблокированной primary surface, используется NOWAIT: contention отклоняет операцию с rollback, без обратного ожидания и автоматического retry. Groups не читает kernel tables напрямую; локальный путь не вызывает ambient actor/host resolvers.

Гонки PostgreSQL доказывают оба порядка archive/delete vs publication/reaction. Если management transition завершается после outside guards, owner effects (posts/revisions/reactions/counts/outbox) отсутствуют. Если content fence выигрывает, management transition наблюдаемо ждёт kernel surface lock до content commit. Проверены также изменения каждой generation/revision, оба порядка surface identities, NOWAIT contention/rollback и отсутствие ambient actor resolution. Прежние privacy/replay/leave checks сохранены; public leave теперь встречает более ранний management surface lock, отдельный group-lock race и group-before-post trace сохранены.

Контроль на исходном GroupTarget из `cd66297143fec431ad1be879652bff5484a588d7` воспроизвёл F1: 10 race cases обнаружили лишние durable content effects, 2 membership controls прошли. Исправленный кандидат проходит все проверки ниже.

| Check | Exit | Результат |
| --- | ---: | --- |
| V1 | 0 | 78 tests / 207 assertions |
| V2 | 0 | 42 tests / 718 assertions, включая F1 races |
| V89 | 0 | 57 architecture tests / 878 assertions |
| V90 | 0 | PHPStan max: no errors, без новых suppressions/baseline |
| V91 | 0 | Pint passed |
| V92 | 0 | весь Integration/Groups: 74 tests / 2720 assertions |
| V93 | 0 | generated P4.9 consumer: 1 test / 453 assertions |
| V94 | 0 | весь Unit/Groups: 7 tests / 120 assertions |
| V96 | 0 | kernel lifecycle/atomicity/generation: 30 tests / 506 assertions |

V1/V2/V89/V90/V91 выполнены буквально. Все девять `check-evidence/v1` JSON валидны по native schema/defs; проверены SHA256 логов и source fingerprint 861 файла (`f583f14004e71939bfa80537779b454ffcba8e108cc52209e020d902767471fb`). PostgreSQL — только `pg-run.py`, invocation-owned container/volume, loopback endpoint и точные owner/decoy `*_test`; run marker/version/connection identity проверяются до fixture DDL. Skipped/incomplete/warning/risky tests и timeout не считаются pass. В V90 сохранено прежнее PHP startup warning о turbo-extension; анализ завершился без ошибок.

Owning session: `01a1871a-9dc1-7690-a6f3-224ee4814e7b`; `codex/gpt-6.1-sol/xhigh`, `danger-full-access`. Correction base: `cd66297143fec431ad1be879652bff5484a588d7`. Повторный start, reviewer/subagents, finish и blocked не вызывались в correction turn. Для нового committed candidate требуется fresh review координатора; review receipt не создавался. Routes и foreign dirty/untracked не изменялись.
