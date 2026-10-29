# PLAN1.P2.7 — candidate для независимого review

Реализован только P2.7 как owning executor. Native project launcher `start` принят;
фактический runtime — `codex/gpt-6.1-sol/xhigh`, session
`01a18356-1ebf-7ef1-877f-01d40534fd40` (`runtime.json`, `start.json`).
Другие агенты и независимый reviewer не запускались. **Пункт остаётся in_progress;
V99 pending, native finish не вызывался, review receipt не создавался.**

## Реализация

- Публичные Operations execute/lookup, subject-bound digest и узкий OwnerMutation SPI.
  MutationRequest/Snapshot/Effect/Event — final readonly Api DTO без ORM/Internal/closures.
  Полные surface/target refs, incarnation, audience, CAS/action/intent входят в digest;
  несовпадающие payload refs, commandType, author/actor/installation отклоняются до claim.
- Current ActorContexts/isolation, immutable panel binding, action, hard admission,
  AudienceGate, publication containment, lifecycle и safety обязательны. TargetResolver
  работает вне owner transaction; TargetWriteFence и local generation recheck — под lock.
  Unknown/error/revoked не становятся success. Module target обязателен; sockit.* lifecycle
  нельзя подменить сторонним OwnerMutation.
- INSERT UNIQUE receipt → surface lock → target fence/owner lock → CAS → state/revision →
  final receipt → ref-only outbox → собственный commit. Core сверяет affectedRows=1,
  resource identity и next revision; пустые event proposals откатывают операцию.
  Ни consumer, ни wakeup не вызывается из execute. Вложенный owner transaction отвергается
  до guards/эффекта; транзакция вызывающего кода сохраняется.
- Receipt guard descriptor содержит только refs, action/intent/audience/generations/digest,
  без command payload/body. Replay/lookup повторяют текущие guards до и после чтения receipt,
  в том числе после UNIQUE wait. Чужой actor key выглядит отсутствующим при lookup.
  Минимальный definitive Rejected записывается отдельно, только где ProblemSpec разрешает;
  winner не заменяется и не раскрывается. Unknown/provider/payload/conflict не сохраняются.
- Публичный SurfaceInstances get/create/archive/resume/delete использует тот же envelope.
  Create требует отсутствующий instance, explicit first incarnation и отсутствие tombstone;
  остальные команды требуют CAS. Archive остаётся read_only; resume требует отдельной
  текущей write authority. Delete возвращает только собственный разрешённый post-commit
  non-content acknowledgement; последующий replay/lookup удалённого ресурса запрещён.
- OwnerConnection явно обрабатывает rollback, включая исключение непосредственно перед
  PDO commit. Surface storage добавляет локальный informational snapshot без authority.
  Новые runtime services scoped; boot не делает SQL/DDL. Архитектурные гейты не ослаблены.

## Изоляция PostgreSQL до мутаций

Неизменённый P2.1 `scripts/testing/pg-run.py` создал отдельные owned postgres:16-alpine
containers для checks: случайный run label/private loopback port, owner/decoy *_test,
mode-0600 manifest. PgTarget и RuntimeDatabase проверили label/endpoint, actual database,
server_version_num=160014, database run marker и реальные PDO/Laravel backend PIDs **до
fixture DDL**. Ambient DB env очищается bootstrap; dev/prod и .env не использовались.
Proof находится в PG-preflight.log и каждом final PG log. Cleanup удалил только owned runs.

PG-missing.log подтверждает реальный отказ без private manifest: exit 2 до DDL.
Это ожидаемый negative bootstrap result, не product pass/skip. Fixture state/revisions,
host target/fence и consumer schemas — test-only, production migrations не создавались.

## Final checks

Все строки ниже — фактические запуски одного финального source check digest
`7827b85c6f37abe6d428e2236d72453911b89a767d704a9265f1939da62243fc`.
Commands/cwd/exit/stdout/stderr/timestamps/environment identity и SHA-256 логов сохранены
в соответствующих V*-final.json и V*-final.log. PG command запускается через owned harness.

| Check | Actual result |
|---|---|
| V1 atomicity | exit 0; 12 tests / 196 assertions |
| V2 concurrency | exit 0; 8 tests / 268 assertions |
| V3 authorization/fences/lifecycle | exit 0; 39 tests / 682 assertions |
| V4 targeted + all Arch | exit 0; 47 tests / 411 assertions |
| V5 affected surface/receipt primitives | exit 0; 29 tests / 520 assertions |
| V6 affected outbox/leases/crashes | exit 0; 28 tests / 339 assertions |
| V7 SQL consumer inbox | exit 0; 7 tests / 103 assertions |
| V8 affected boot/config-cache/targets/handles | exit 0; 61 tests / 178 assertions |
| V90 PHPStan max | exit 0; No errors; без suppressions/baseline |
| V91 Pint | exit 0; passed |
| V99 independent review | **pending; не выполнялся** |

Final native check-evidence/defs schema validation и проверка хешей логов:
evidence-verified.json. Source fingerprint каждого final check сверён с текущими исходниками.
Документация: docs-check.log — OK. Native plan check: 0 errors / 4 context-weight warnings
(plan-check.log). Существующий optional phpstan_turbo startup warning сохранён; PHPStan
завершился exit 0 / No errors, глобальное окружение не перестраивалось.

Пять SQL fault points: после state, revision, receipt finalization, outbox append и перед
commit — state effects=0/revision=1, receipts=0, outbox=0, consumer calls=0, transactionLevel=0.
Panel B и default-decoy сравниваются полными row snapshots всех kernel tables; неизменны.

Семь реальных holder/waiter PG barriers с разными backend PIDs и pg_blocking_pids доказывают:
same digest → effect/receipt/outbox=1; другой digest → conflict; CAS winner=1;
archive/delete сериализуются с mutate; rollback winner допускает следующего claimant;
revoke во время UNIQUE wait не раскрывает committed winner. Нет retry-until-green.
Lost response после реального commit/закрытия PDO восстанавливается оригинальным key/ref,
без второго эффекта. Auth/target resolver instrumentation подтверждает transactionLevel=0.

Replay/lookup отказывают после revoke, actor removal/подмены, cross-installation/panel,
held/hidden/deleted/unknown target, old incarnation/privacy/generation и surface deletion.
Read-only/archive разрешают только текущий readable replay без нового write. Custom allow
resolver не обходит admission/containment; unrelated class/group и unknown containment deny.
Положительная contained direct publication проходит реальный AudienceEvaluator. Local surface
privacy change и target fence change/unproven откатывают claim до эффекта.

Исходные failures и исправления оставлены в initial/second/third logs: NOT NULL guard metadata
перенесена в первоначальный receipt INSERT; исправлены fixture reason/import ошибки и
сброс test-only application cycles, вызвавших too-many-clients. Все final checks выполнены
после этих исправлений. Self-inspection и проверки не выдаются за независимое review.

## Замороженный candidate и продолжение

- Base/HEAD: `03c852c9ef08bb0294a4facb17394d5453d3fabd`.
- Candidate digest: `sha256:1d2b93821807b52daf9cb42340219e996dd6d7d9540110ad1c76e5cef8842040`.
- `candidate.json`: 144 expanded actual source paths, native mode/git-blob fingerprints,
  scope P2.2–P2.7 только как supporting context изменённых инвариантов.
- `candidate-source.tar.gz`: точные source bytes, включая новые untracked files;
  `candidate.diff`: полный patch; `source-manifest.json`/`source-integrity.json`: byte proof.
- `review-request.json`: fresh read-only codex/gpt-6.1-sol/high, session != writer,
  rounds_completed=0, rounds_max=2. `review-receipt-schema.json` — native receipt schema.
- Artifacts исключены из source digest во избежание self-reference; evidence/снимок связаны
  отдельным artifact-sha256.json. Один исторический P2.4 glob Surfaces/Internal/Instances/**
  пуст: actual repository находится в Operations/Internal/Storage и включён в fingerprint.
  Принятый P2.4 item не менялся.

Сохранены исходные 410 dirty/untracked чужих файлов byte-for-byte (foreign-preserved.json).
Native task_files P2.7 адресно включают затронутые OwnerConnection/SurfaceInstances storage;
V5–V8 добавлены как affected checks через native API. Другие items не исполнялись/не менялись.
Candidate оставлен в рабочем дереве и архиве; commit/push/tag/publish не выполнялись.

Следующий шаг — независимая fresh SOL/high read-only сессия проверяет candidate/evidence
и выдаёт настоящий native review receipt по final base/head/digest/paths. После findings —
только scoped correction и при необходимости второй round; прежний receipt не переименовывать.
Owning executor сверяет receipt, отвечает на findings, выполняет V99 и лишь затем native
finish/точный scoped Conventional Commit. До receipt P2.7 не закрывать и P2.8 не начинать.

Это standalone runtime envelope и SQL fixture conformance, не social/remote delivery e2e.
Сетевой adapter без local fence не получает write; cross-database atomic revocation/commit,
production schema/generators, social modules, external adapters, автоматические retry/purge
не обещаются. Actual module safety/visibility при lookup должен поставлять его owner target API;
P2.6 relay остаётся surface-event primitive до появления domain-owned current lookup.
