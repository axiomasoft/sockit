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

## Runtime enforcement — P2.7

The public Operations envelope implements I-02/I-03/I-04/I-05/I-06/I-07/I-08/I-11/I-12.
It validates subject-bound canonical digest and trusted context before claiming a receipt; guards
execute outside the owner transaction. Receipt uniqueness serializes duplicate keys using PostgreSQL
ON CONFLICT, followed by receipt → surface → target/fence → owner CAS locks. Changed surface/local
generations abort; remote authority is re-resolved only outside the transaction. No automatic retry
hides a deadlock/timeout or CAS conflict. Snapshot/effect predicates and mandatory ref-only event
proposals cannot turn Unknown or zero affected rows into Confirmed.

The owner envelope requires a composite host authority fence after all owner locks and before
persist. Its generation is captured before current guards outside tx, then locally checked and
held through commit; a rolled-back receipt holder cannot hand a stale write grant to its waiter.
Missing/stale/unprovable authority rejects and rolls back the claim; fresh guards resolve only
after rollback, without an automatic persistence retry. Target-less lifecycle uses the same fence.

The owner envelope rejects nested owner transactions before effect. Its transaction boundary catches
faults including a failure immediately before PDO commit. State, revision, receipt and outbox either
commit together or leave no new rows/revision. Delivery is outside this envelope; rollback cannot
consume or wake a relay. Definitive rejected outcomes may be recorded in a separate minimal
transaction after rollback, according to ProblemSpec; this is never a successful mutation claim.

Both execute replay and lookup reauthorize current action, surface/audience/containment, lifecycle,
target incarnation/privacy/generation and safety-visible owner target before output, including after
waiting for a winner. Actor/namespace identity is checked before reading receipt results. Existing lookup authorizes the recorded action independently of commandType. Absent lookup
and another actor's key use the same explicit host action mapping/policy after current baseline
surface access; unknown mappings deny. Lookup hides another actor's receipt as absence only
when that absence policy allows. Receipt context contains no body/command payload and cannot grant
access. The original namespace/key/digest/resource remain stable after a lost response. Archive or
read-only access can expose only currently readable metadata, and never grants another write.
The originating authorized delete can acknowledge its own commit; deleted-resource replay remains denied.

`EnvelopeAtomicityTest`, `EnvelopeConcurrencyTest`, `ReplayAuthorizationTest` and
`GenerationFenceTest` exercise these through real isolated PostgreSQL with independent backend PIDs,
observable lock barriers, SQL counts and fault injection. Tests include state/revision/receipt/outbox/
pre-commit failures, rolled-back winner recovery, duplicate/different digest/CAS, archive/delete races,
current revoke, lost-response lookup and local generation fencing. Panel B and the default decoy
are compared as complete row snapshots. These fixtures do not claim social or remote delivery e2e.
