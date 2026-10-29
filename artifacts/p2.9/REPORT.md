# PLAN1 P2.9 — Worker rehydration и isolation

Исполнитель: `codex/gpt-6.1-sol/high`, session `01a183ec-e1a6-7ba1-a3a5-b485dfb55f0b`.
Исходный HEAD: `af35b2fb110b916c9edf7b4e3bbbac6913184568`.
Выполнен только P2.9; review.mode=none. Второй executor/reviewer/subagent не запускался.

Реализованы `OperationJobReference` и `OperationJobs::execute` с явной trusted operation factory.
Wire v1.0 сохраняет panel/surface/target/incarnations, actor ref, installation/operation type
в namespace, source epoch и исходные key/digest/CAS revision. Actor facts, permissions,
модели, registry objects и callbacks не сериализуются. Некорректные fields/version отклоняются.
Host активирует isolation до consume; job не переключает глобальную БД/config.

Каждый consume сравнивает trusted current installation и frozen source epoch, затем вызывает
`ActorContexts::forWorker`. Приватная копия binding maps содержит собственные actor/handle/storage/
relay instances; исходный container не получает worker actor и не сбрасывается. Immutable adapter
повторно разрешает explicit actor на защищённых входах. Отдельный mutable current frame не нужен:
локальный runtime не переносится на следующий вызов и не очищает caller context при throw.
Host adapters/connection manager остаются host-owned. Один mutable PDO между Fibers не поддерживается.

Factory получает свежий worker handle, её command type/target проверяются по исходному job,
канонический digest проверяет прежний envelope P2.7. Текущие target/admission/privacy/containment,
authority fence и replay authorization сохраняются. Активная caller transaction отвергается
до actor IO/factory и остаётся у caller. Runtime P2.7 не изменён; fixture ports получили optional schema
с прежним default, чтобы проверить разные maps/соединения реальным SQL.

Проверки выполнены буквально командами validation item; полные command/cwd/stdout/stderr,
UTC timestamps, exit и identity executor сохранены в `V*.json`/`V*.log` этого каталога.
`procedure.cwd=.` обозначает корень репозитория; абсолютный cwd присутствует в каждом логе.

| Check | Exit | Результат |
|---|---:|---|
| V1 | 0 | WorkerIsolationTest: 6 passed, 135 assertions |
| V2 | 0 | WorkerRevocationTest: 21 passed, 388 assertions |
| V3 | 0 | Actors + Configuration + Arch: 95 passed, 532 assertions |
| V4 (дополнительно) | 0 | EnvelopeAtomicity/Concurrency/ReplayAuthorization: 60 passed, 1269 assertions |
| V90 | 0 | PHPStan: No errors, без suppressions/baseline |
| V91 | 0 | composer lint / Pint passed |
| V99 (дополнительно) | 0 | Native check-evidence schema, log SHA-256, PG identity, source manifest, outside hashes |

V1 доказал A→B→A с одинаковыми host IDs, разными actors/connections/table maps,
свежие age/generation actor, сохранение caller context/config/default connection,
реальные actor_ref в receipts и panel в outbox. Exception фабрики и SQL exception B не оставили
state/revision/receipt/outbox; после них A выполнил следующий job. Nested B handle и reentrant
calls одного container с другим actor сохраняют outer context после success/throw. Fiber barriers
удерживали одновременно две реальные owner transactions на независимых PDO/connection managers;
затем обе завершились со своими строками. Original-key retry вернул тот же receipt без второго эффекта.

V2 проверил revoke permission, удаление actor, смену installation/source epoch, surface/target
incarnation и admission между serialization и consume, как до записи, так и после committed receipt.
Отказ не возвращает resource/revision и не добавляет effect/outbox; другая panel и decoy сохранены.
Смена target generation на первом consume разрешена при текущих guards; replay старого результата
при смене generation запрещён. Это отличается от source epoch, mismatch которого всегда запрещён.
Подмена operation type, principal, target и digest не создаёт эффект.

Все PostgreSQL запуски — исключительно через `python3 scripts/testing/pg-run.py`.
До fixture DDL проверены owned container label/loopback endpoint, random *_test names/run markers,
server_version_num=160014 и реальные PDO/Laravel backend PIDs. Логи V1/V2/V4 содержат эти факты
до строк DDL и SQL assertions после commit/rollback. Runner удалил принадлежащие ему контейнеры.
V4 повторил P2.7 UNIQUE wait + revoke + holder rollback, local shared authority fence,
stored action при lookup, atomic receipt/outbox и повторную авторизацию replay.

Первый V1 завершился exit 2 из-за слишком короткого тестового CommandKey; исправлен и повторён.
Первый V90 завершился exit 1 с 9 type errors; исправлены без suppressions. Начальные логи сохранены.
Проверка evidence сначала выявила schema errors в metadata (absolute cwd и string actor);
metadata приведены к native contract: relative cwd и structured actor/session, затем V99=0.
Эти неудачные проверки не выдаются за pass. PHPStan выводит startup warning о недоступном optional
Turbo extension; штатный анализ завершился exit 0 / No errors. Настройки toolchain не менялись.

`source-manifest.json` фиксирует SHA-256 13 source/doc/test paths. `check-evidence-schema.json`
и `defs-schema.json` получены native planner schema; V99 проверяет их и hashes логов.
`commit-dry-run.json` содержит scoped selection и outside. Все 411 чужих outside файлов
зафиксированы в `outside-before.json`; V99 проверил их неизменность перед commit.
`.gitignore`, `.serena/project.yml`, `CLAUDE.md` и чужие untracked artifacts находятся outside.
Commit и finish receipts сохраняются рядом после выполнения соответствующих native commands.

PANEL-06 закрыт только в runtime части P2: generated model hydration/relations/queue restore
остаются P3, host installation qualification — P6. Fixture acme.runtime не является social e2e.
Routes, следующий item, production DDL, HTTP/UI и соседние репозитории не изменялись.
Push/tag/publish/deploy/внешние сообщения не выполнялись.
