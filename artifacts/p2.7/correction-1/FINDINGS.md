# P2.7 — ответы на два подтверждённых Major, round 1

Источник: последний agent_message из
`plans/2026.10.17-№1-SOCKIT-FOUNDATION/artifacts/autopilot/p2.7-review.jsonl`,
точная копия `review-round1-message.json`, SHA-256 источника в `review-source.json`.
Reviewer сообщил RED, 2 Major, 0 Blocker, round 1/2; actual session
`01a1837a-61ea-7db3-bb67-66043bbba52e`, route codex/gpt-6.1-sol/high.
Receipt был проверен reviewer-ом в памяти, но файл не создавался. Owning executor
не изготавливал и не дополнял receipt от его имени. R1-M1/R1-M2 ниже — локальные
идентификаторы ответов на нумерованные findings, не выдуманные native finding keys.

## R1-M1 — stale grant после UNIQUE wait и rollback holder-а

Подтверждён: прежний INSERT мог стать winner после ожидания/rollback, после чего
persist использовал pre-wait authorization; post-commit denial не отменял эффект.

Исправление: обязательный host SPI `MutationAuthorityFence` для каждого guarded write,
включая target-less kernel lifecycle. `capture` вызывается вне tx до текущих guards;
`recheck` — локально на точном owner connection после всех owner locks, до persist.
Хост доказывает актуальность action/admission/audience/containment и удерживает их
fence до commit; revoke writer участвует в той же локальной границе. Target/surface
version не используется как подмена action authority. Missing provider, Unknown,
stale token и revoke не дают эффект. При stale/deny claim откатывается; текущие
guards разрешаются снова вне tx. Автоматического повторного persist нет: явный
retry использует первоначальные namespace/key/digest. Definitive denial может
получить только minimal Rejected receipt по ProblemSpec, без confirmed result/outbox.

Реальное PG evidence: V2-final (11 tests / 421 assertions).

- `revoke_rollback`: holder PID 274, waiter PID 281.
- `lifecycle_revoke_rollback`: holder PID 295, waiter PID 302.
- В обоих случаях pg_stat_activity/pg_blocking_pids подтверждают реальный UNIQUE
  wait именно на INSERT ... ON CONFLICT до revoke и rollback holder-а.
- После отзыва/rollback: effects=0, fixture revision=1, surface live/revision=1,
  confirmed receipts=0, outbox=0, consumer calls=0. Один minimal Rejected receipt.
- `generation_rollback`: holder PID 316, waiter PID 323; stale generation даёт
  authorization_unknown, effects=0/receipt=0/outbox=0. Только отдельный явный вызов
  с исходным key/digest и fresh generation даёт effect/receipt/outbox=1.
- Fence capture transactionLevel=0; recheck=1; current target/authorizer resolver
  calls transactionLevel=0, включая refresh после rollback. Нет timeout/deadlock/retry-until-green.

Старый committed-winner revoke check сохранён по смыслу и assertions: original
mutation заранее committed, holder удерживает только receipt read lock, revoke
происходит во время ожидания replay. Ни один claimant не раскрывает winner после
revoke; effect/receipt/outbox остаются 1. Удерживать mutation authority fence и
одновременно завершать revoke в том же fence теперь невозможно по контракту,
поэтому прежний pre-commit barrier для этого отдельного read-сценария заменён.

## R1-M2 — lookup ошибочно требует commandType grant

Подтверждён: команда `acme.runtime.mutate` может законно объявить authorization
`acme.content.edit`; прежний lookup требовал лишний grant до чтения receipt.

Исправление: после trusted context/surface/admission/audience baseline lookup читает
только identity-checked non-content descriptor. Для существующего owned receipt
replay авторизует сохранённый action, независимо от commandType или текущего mapping
для отсутствия. Чужой actor key обрабатывается той же policy, что absent key.

Для отсутствующего module key добавлен явный scoped `MissingReceiptPolicy`:
хост сопоставляет namespace с настоящим action согласованно с OwnerMutation.
Нет неявного commandType=action, fallback allow или null при Unknown. Missing
provider/unmapped action fail closed. Известные kernel lifecycle commands имеют
точное intrinsic mapping к собственным actions. Публичная lookup-сигнатура и P1
ActionAuthorizer/Actor/Audience contracts не менялись.

Реальное PG evidence: V3-final (44 tests / 755 assertions).

- Execute `commandType=acme.runtime.mutate`, action `acme.content.edit` разрешён,
  commandType явно denied: confirmed. Lookup возвращает точный исходный receipt/ref/revision,
  реальные SQL receipt reads > baseline; отсутствие policy не проверяется для existing result.
- При revoke `acme.content.edit` и Allow commandType lookup denied: Type grant не обход.
- Absent lookup с явным mapping на edit и denied commandType возвращает null;
  revoke mapped action → deny; missing mapping → Unknown; missing policy → provider_unavailable.
- Existing result не зависит от изменённого absent mapping. Foreign actor/namespace,
  revoked/hidden/held/deleted/unknown/old incarnation/generation и containment negatives
  сохранены. Missing/unprovable local authority fence также проверен до owner эффекта.

## Targeted re-review

Исправления проверены owning executor-ом; это не независимое принятие findings.
Нужен один targeted round 2/2 по этим двум findings и новым связанным SPI/guards.
Не повторять полный audit фазы/P1. Сверить новый candidate и V*-final evidence,
`targeted.diff`, права до persist и авторизацию stored/absent lookup. Создать настоящий
native receipt на новый digest; V99/finish до такого receipt не pass.
