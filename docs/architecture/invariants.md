# Инварианты

| ID | Инвариант | Enforcement | Bypass, который запрещён | Проверка |
|:--|:--|:--|:--|:--|
| I-01 | Один writer на state identity | ownership manifest + arch-тест владения таблицами | запись модулем/хостом в чужую таблицу; ALTER чужой таблицы | arch-тест, review миграций |
| I-02 | Актор и контекст выводятся сервером | `ActorContexts` — единственный источник; команды не принимают author/installation из payload | конструктор команды с `ActorRef $author` | arch-тест сигнатур команд (P2), SN-02 |
| I-03 | Текущая аудитория перед каждым защищённым выходом | `AudienceGate` на всех путях чтения/записи; проекции дают кандидатов | чтение тела в обход Gate (прямой Eloquent в публичном коде) | revoke-тесты на PHP/worker/feed (SN-11) |
| I-04 | Идентичность мутации = namespace + key + digest | unique (namespace, key) в receipts; digest после канонизации | повтор с новым ключом при unknown | SN-09 |
| I-05 | Состояние + ревизия + receipt + outbox атомарны | одна транзакция соединения владельца | outbox вне транзакции; сеть внутри транзакции | fault injection SN-08 |
| I-06 | CAS: правка только по expected revision | `WHERE revision = :expected`; 0 строк → lookup, без авто-повтора | last-write-wins | barrier-тест параллельной правки |
| I-07 | Tombstone + incarnation против воскрешения | удаление оставляет non-content tombstone; событие со старой revision/incarnation отбрасывается | upsert по событию без проверки tombstone | SN-14 reordered replay |
| I-08 | Расширение только сужает | стадии/резолверы могут Deny/hold, но не Allow вместо Deny ядра | callback, меняющий deny на allow | тест «расширение пытается разрешить» |
| I-09 | Rebuild проекций не повторяет side effects | ReplayMode::ProjectionOnly | уведомления при rebuild | SN-16 |
| I-10 | Публичный API без Eloquent | arch-тест сигнатур | возврат модели | arch-тест |
| I-11 | Unknown ≠ success | OperationResult::isSuccess только Confirmed | маппинг unknown → 200 | unit |
| I-12 | Panel/target isolation before authorization shortcuts | trusted installation + frozen panel + surface boundary | equal actor/target ID in another panel/installation | two-panel / cross-installation tests |

## Конверт

1 decode/validate; trusted actor/isolation; resolve panel-bound storage/target/surface.
2 current ActionAuthorizer + SurfaceAudiencePolicy admission/containment + audience + safety вне tx.
3 BEGIN на panel owner connection. INSERT receipt claim с UNIQUE(namespace,key), не SELECT FOR UPDATE отсутствующей строки. Concurrent same key ждёт уникальный конфликт; после него в корректном DB savepoint/transaction прочитать winning receipt и сравнить digest/current access. Не продолжать SQL в aborted PostgreSQL transaction.
4 lock receipt → surface → group (optional) → own post/target → reaction; recheck revision/lifecycle/incarnation/local policy generation.
5 Если remote host authority/generation изменилась или не доказана, abort/retry с re-resolve ВНЕ tx. Не вызывать сеть под lock и не обещать атомарность отзыва в другой DB. Same-DB host adapter может обеспечить fence; иной профиль явно фиксирует consistency boundary, deny при unknown.
6 persist owner mutation + revision + receipt + outbox; COMMIT. afterCommit лишь wake-up relay, durable poller восстанавливает pending. External delivery at-least-once, inbox dedup complete identity.
7 Receipt replay проходит current hard guards до выдачи resource refs/body. Rebuild projection-only без deliveries.
Обязательные guards не могут быть выключены module extension. Любая ошибка/unknown deny/reject с причиной.

## Локи и мешающие пары

Within one panel/connection: receipt row after successful claim → surface instance → group when used → owned post/target → reaction. ID order within same class deterministic. Receipt uniqueness, not absent-row lock, serializes duplicate keys.
Own-post reaction-vs-hide/delete serialized by post lock; external host targets require current resolver/revision/fence contract and cannot promise cross-database row locks. Cross-panel operations are separate transactions. Reordered replay respects panel+source generation+incarnation+tombstone. Comment tree/parent locks are Chatom/Forum responsibility, outside this plan.

## Неизвестный исход

Анонс (P8): outbox SocKit → bridge inbox (PK consumer,event_id + digest) → текущий snapshot поста через owner API → текущая аудитория канала ⊆ аудитории поста (иначе deny/quarantine) → durable send intent со stable key → вызов Chatom **вне** транзакции SocKit → при таймауте lookup по тому же ключу → mapping+receipt → AudienceShrunk/PostDeleted → idempotent redact/delete; stale событие отбрасывается по privacy revision/tombstone.

Неизвестный внешний исход: owner пишет durable intent + digest → execute(originalKey) → ответ потерян → reconciler lookupOutcome(originalKey): confirmed → receipt; absent → retry тем же key/payload; conflict/unknown → quarantine. Если у backend нет idempotency/outcome lookup — мост не объявляет exactly-once эффекта.
