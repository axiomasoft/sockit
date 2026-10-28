# Контракты SPI

## Шаблон документации SPI

Для каждого SPI при реализации укажите назначение и реализатора; точную сигнатуру; результаты и ошибки; default или его отсутствие; consistency boundary; fake и swap-пример; правила версионирования. До реализации сигнатура не считается подтверждённой.

## Реестр P1

- `TenantResolver`: доверенная инсталляция и изоляция.
- `ActorContextResolver`: доверенный текущий актор.
- `ContourResolver`: текущие факты контура.
- `AudienceResolver`: вычисление аудитории; обязательные guards не обходятся.
- `SurfaceAudiencePolicy`: admission и containment.
- `ActionAuthorizer`: ограничение действия.
- `TargetResolver`: видимость и lifecycle сущности хоста.
- `PanelProvider`, `SurfaceProvider`, `Module`: декларация и композиция.

Точные сигнатуры, ошибки и swap-примеры заполняются по мере реализации соответствующих пунктов P1.

## Host targets — P2.3

`TargetResolver::resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution`
поставляет текущую видимость host Article/Product для будущего L0 reactions и target capability P4 posts.
`TargetRef` содержит `HostEntityRef` (стабильный alias и lossless строковый ключ) и `Incarnation`.
Panel identity находится в обязательном `SurfaceRef`. Числовые ключи никогда не преобразуются в int;
`00042`, `42`, UUID и ключ с двоеточиями сохраняются буквально. Новая incarnation означает другую сущность.

`TargetResolution` — immutable снимок: точные surface/target, trusted installation, отдельные read/write
`AudienceVerdict`, `TargetLifecycle` (`live`, `read_only`, `hidden`, `deleted`, `unknown`), `Revision privacyRevision`
и непустой generation (1–128 байт, без управляющих символов). Тела контента и ORM отсутствуют.
Wire v1.0: `{version,surface,target,installation,read,write,lifecycle,privacy_revision,generation}`;
verdict: `{decision,reason,policy_version,expires_at}`; UTC expiry имеет микросекунды.
`TargetRef` wire: `{host,incarnation}`. Некорректная форма даёт `sockit.payload_invalid`,
неподдерживаемая major/minor — `sockit.wire_version_unsupported`. Векторы: `fixtures/targets/`.
Ни wire, ни сериализованный снимок не предоставляют полномочий.

Хост явно настраивает FQCN в `sockit.resolvers.targets` для aliases из `PanelDefinition::hostTypes`.
Отсутствующий/недоступный binding даёт `sockit.provider_unavailable`; Allow fallback отсутствует.
Модуль вызывает `ModuleRegistrar::target(alias: 'acme.target', resolver: MyTargetResolver::class)`;
регистрация содержит только FQCN, а не instances. Будущий posts зарегистрирует собственный alias тем же SPI;
ядро и reactions не импортируют Posts. Только selected-on-panel модули участвуют в registry.
Совпадение alias у двух выбранных модулей одной панели — `sockit.configuration_invalid` до публикации графа;
на разных панелях допустимы разные adapters. Private draft фиксируется при compilation, поздний `target()`
и module registration отвергаются. Невключённый capability не получает host fallback, если его alias
отдельно не объявлен хостом в `hostTypes`. Boot/config:cache не создаёт resolver instances и не делает SQL.

Операционный вход: `$handles->resolveTarget(handle: $handle, target: $target)` через `OperationHandles`.
Он заново проверяет trusted ActorContexts/installation и immutable panel/surface, затем current admission
перед каждым resolve (включая read/replay/lookup). Ошибка адаптера или mismatch refs/installation →
`sockit.authorization_unknown`; admission deny → `sockit.authorization_denied`.
Hidden/deleted/unknown/expired visibility не допускает чтения/записи; read-only admission/target не допускает
записи. Verdict не расширяет admission и сохраняет его конечный expiry. Факты актора не принимаются из payload.
Instances host binding — scoped; module FQCN разрешается явно на каждый вызов через container, без хранения
актора/installation/resolver в singleton registry. Хост может зарегистрировать свой FQCN scoped.

Resolver вызывается вне owner transaction; активная транзакция выбранного connection (и любая открытая
транзакция известного Laravel manager) отвергается до вызова resolver. Адаптер сам задаёт ограниченные
network timeouts; автоматических retries/batch и кеша полномочий между входами нет. Remote reads являются
текущим sampled resolution: атомарный отзыв в другой БД не обещается.

Для записи выбранный adapter дополнительно реализует
`TargetWriteFence::recheck(OperationHandle $handle, TargetResolution $resolved): AuthorizationDecision`.
`$handles->recheckTarget(...)` допускает только активную owner transaction и соответствующие panel/refs,
installation/live/write snapshot. Нет optional fence/проверяемой local generation → Unknown
(`sockit.authorization_unknown` через `requireAllowed()`); Throwable также Unknown. Fence исполняется только
локально, без actor/tenant/admission/remote resolve и без сети. Adapter владеет своими host-запросами,
проверяет incarnation, lifecycle, visibility, privacy revision/generation и удерживает local lock/fence
до commit на **точном owner connection**. При stale/unknown требуется rollback, затем новый resolve вне tx.
Иной consistency profile требует отдельного явного контракта.

Порядок receipt → surface → target и остальные action/admission/containment/lifecycle guards обеспечит
операционный конверт P2.7; успешный target fence сам по себе не означает авторизацию всей операции.
Хост обязан обеспечить локально проверяемую boundary; сетевые callbacks под lock запрещены.
Добавление методов в stable SPI требует major; новые возможности — отдельным capability-интерфейсом.

`SocKitFake::install($app)->targets` даёт `FakeTargetResolver`: `returns(actor: ..., resolved: ...)`,
`failWith(...)`; всё ненастроенное deny. Ключ включает весь actor context, installation, panel/surface,
target и incarnation. Fake не реализует local fence. Swap/registry fixtures `acme.runtime` и `acme.other`
и локальный PostgreSQL host adapter проверяют seams, **не являются social e2e**.

## Durable events and consumers — P2.6

`ModuleRegistrar::outboxConsumer(id: 'acme.inbox', consumer: MyConsumer::class)` declares a
concrete `OutboxConsumer` FQCN. IDs are globally unique across enabled modules; declarations freeze
with the graph. Effective consumers are restricted to modules selected on the explicit panel.
Boot and config caching collect class names without constructing consumers or touching SQL.
Consumer instances are resolved per attempt and must not retain actor/installation in a singleton.

`EventEnvelope` wire v1.0 contains UUIDv7 `event_id`, `source_installation`, `panel`,
`source_generation`, complete `surface` including incarnation, `source_revision`, `privacy_revision`,
`event_type`, UTC `occurred_at`, `audience`, optional `target`, `payload` and `digest`.
Payload is a map of typed `ResourceRef` values serialized to scalar reference fields; arbitrary body,
actor facts and unknown fields are rejected. Digest covers all immutable envelope fields except itself.
Unknown wire version and conflicting digest fail closed with existing problem codes.
`sockit.events.source_generation` is a nonempty server-owned string of at most 64 bytes (development
default `'1'`), frozen in `SocKitConfig::events`. Actor generation and payload never select that epoch.
Rotation invalidates old source events for dispatch; clone/restore qualification belongs to P9.

The internal `OutboxStore::append()` joins an existing owner transaction and uses the panel's immutable
logical `outbox` mapping. The caller obtains server-compiled consumer IDs from
`OutboxRelay::consumers($handle)` **before** entering the transaction. Append identity is
`(installation,panel,source_generation,event_id)`; identical replay preserves delivery state;
a conflicting immutable envelope raises `sockit.event_identity_conflict` and rolls back the caller's
transaction. It does not rewrite the accepted event. An event without consumers remains durably
quarantined. The owner envelope integrating state/revision/receipt/append is P2.7; production DDL is P3.
P2 uses PostgreSQL test-only snapshots, with one kernel logical outbox and no kernel inbox table.

`OutboxRelay::poll(handle: $handle, mode: ReplayMode::Delivery, limit: 100)` is scoped to the
explicit handle's installation/panel/surface incarnation. The host restores trusted isolation and
supplies the current principal; the relay re-resolves that actor with `ActorContexts::forWorker`,
checks current action `sockit.events.deliver`, admission/content audience, owner lifecycle/revisions,
and optional public target visibility/privacy before each consume. Surface lookup is performed by
the public relay through its owner repository; consumers do not read kernel tables. P2 surface-event
payload refs must match the current surface resource. Other resource refs fail closed until their
domain owner supplies a public current lookup; no generic foreign table access is inferred.
No body is dispatched. Access facts are sampled immediately before dispatch; atomic revocation across
separate authority/delivery databases is not promised. Network/resolvers/consumer never run under owner locks.

Claims use a short `FOR UPDATE SKIP LOCKED` transaction, PostgreSQL time, a random lease token,
expiry and durable attempt count per consumer, followed by commit. `lease_seconds` (1–3600),
`retry_seconds` (1–86400) and `max_attempts` (1–1000) are typed server scalars. ACK/retry/quarantine
requires the same full source/event/consumer identity, current token and unexpired lease under lock.
An old worker cannot overwrite a reclaimed lease. `DeliveryResult` distinguishes `confirmed`, `retry`,
`unknown` and `quarantine`; only confirmed becomes delivered. Unknown outcomes and exceptions retain
stable identity for retry. The relay is the sole retry owner with bounded backoff and attempt quarantine.
Repeated crashes also stop at the attempt budget. Missing/disabled consumers quarantine with a reason;
disable never deletes or silently acknowledges pending data. Recovery/redrive from quarantine is explicit
and is not an automatic policy in P2. Wakeups are optional hints; durable polling survives their loss.

`OutboxConsumer::consume(EventEnvelope $event, ReplayMode $mode): DeliveryResult` runs outside the
owner transaction and may be called repeatedly after crashes/timeouts. Implementations set bounded
IO timeouts, fail closed, own their SQL inbox and commit inbox identity/digest plus local effect in one
consumer transaction. UNIQUE must include `(consumer,installation,panel,source_generation,event_id)`;
same identity/digest is a no-op, another digest is quarantine. A queue ACK is not proof of a remote effect.
No batch or authorization cache is provided; stable SPI additions require separate capabilities.

`ReplayMode::ProjectionOnly` calls the same current guards and consumer port for idempotent local
projection only. It does not claim/ACK or advance live delivery state. Consumers must perform zero
network/notification/delivery effects and keep projection dedup separate from the live inbox, so a
rebuild cannot suppress a later live effect. Projection polling uses a bounded explicit surface batch;
large rebuild pagination/product scheduling is outside P2.6. Test fixtures demonstrate independent SQL
inbox/projection transactions, process crashes, fenced ACK and two-panel identity; they are not social
or remote delivery e2e evidence.
