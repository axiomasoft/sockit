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

## Atomic owner mutations — P2.7

`Operations::execute(OperationHandle, CommandEnvelope, OwnerMutation): OperationResult` is the fixed
owner envelope. `lookup(handle, namespace, key): ?OperationResult` returns absent only after current
surface/admission/audience access and explicit absence authorization. Another actor's key
is subject to the same absence policy. Existing receipts authorize their stored action;
commandType is an idempotency identity and is not implicitly an authorization action. Neither entry trusts an old handle,
author or installation from command data. Construct a digest with `Operations::digest(handle,
mutation, expectedRevision)`; it binds the complete surface/panel/incarnation, target/incarnation,
subject and proposed audiences, action/intent, CAS revision and canonical module data. Namespace
commandType must equal mutation commandType. Reserved identity fields must match; author/actor/
installation payload fields are rejected. Keys/namespaces are lossless and never truncated.

`OwnerMutation` is a trusted owner implementation, not arbitrary client-supplied code. Its exact
methods are `commandType(): string`, `payload(): array`, `request(OperationHandle): MutationRequest`,
`lock(OperationHandle): MutationSnapshot`, and `persist(OperationHandle, MutationSnapshot): MutationEffect`.
Request/payload are side-effect-free outside transactions. Request names the action, trusted subject,
intent (`write`, `publish`, `read`), sampled owner safety/local generation, optional proposed audience
and target. Module mutations require a target; target-less kernel lifecycle is private to the kernel.
`read` is metadata for guards, never permission for execute to persist. DTOs are final readonly and
contain domain values; neither ORM, driver nor closures enter the protocol.

Action authorization, admission, audience, containment for publication, owner safety and current
target visibility are mandatory. Missing providers and Unknown deny. Surface local generations are
sampled before remote guards and compared under the surface lock. The host target adapter must
provide current owner lifecycle/safety/visibility for replay/lookup and a local `TargetWriteFence`
for writes. The host also binds the mandatory scoped `MutationAuthorityFence` described below;
target/surface generation alone does not prove current action/admission authority. A remote-only adapter cannot prove mutation and is denied. Owner lock/persist use only
local SQL on the exact panel connection: receipt → surface → optional group → target/post → reaction,
with deterministic order within a class. Lock returns current resource/revision/lifecycle/safety and
local generation; CAS requires the expected revision, or both resource/revision absent and expected
null for create. Persist reports real affectedRows=1, the same resource and old.next() (1 on create).
Malformed effects, generation/safety mismatch, empty event proposals or substituted refs roll back.
Trusted implementations must actually execute their SQL CAS and must not open/commit transactions,
write another owner's connection or perform network IO. The kernel cannot sandbox arbitrary PHP.

State/revision, finalized receipt and ref-only outbox append commit together. The kernel supplies
UUIDv7 event identity, panel/source epoch, time, audience and revision fields. Extensions cannot omit
outbox; at least one proposal is required. Consumers come from frozen selected module declarations;
no consumer is called by execute. There is no required wakeup: durable polling is authoritative.
Module resource events require the future domain owner's current lookup before relay dispatch;
P2.6's surface-event relay does not claim to hydrate arbitrary module resources.

Receipts store only a canonical guard descriptor (surface/installation, action/intent, audience,
optional target generations and digest), not payload content or authority. Replay and lookup resolve
current guards outside SQL transactions before and after reading a committed receipt. A UNIQUE wait
never reuses pre-wait permission. Read-only/archive permits a currently authorized readable outcome
without a new effect. Deleted/hidden/stale-incarnation resources disclose no old reference. A successful
surface delete returns its own authorized post-commit non-content acknowledgement; later replay and
lookup are subject to deleted visibility. Definitive safe denials are separately recorded as minimal
Rejected receipts where ProblemSpec permits; they never replace or disclose a winner. Unknown,
provider/payload/idempotency errors remain non-success and unrecorded.

`SurfaceInstances::get/create/archive/resume/delete` delegates to the same guarded Operations service.
Lifecycle actions are `sockit.surfaces.{get,create,archive,resume,delete}`. `digest(handle, action,
expectedRevision)` prepares a bound lifecycle command digest. Create requires a missing instance,
explicit first incarnation and no tombstone; archive/resume/delete require CAS. Resume is an explicit
administrative transition, still requiring current write admission and action permission. No deleted
instance is restored through this API. Generations and tombstones remain kernel-owned.

Nested active owner transactions are rejected with `sockit.configuration_invalid` before guards or
mutation. The caller's transaction is neither completed nor rolled back. Cross-panel atomic commit is
not promised. There is no automatic retry, last-write-wins, authorization cache, retention or purge.
Current reads are sampled authority; atomic revocation across remote databases is not promised.
The `acme.runtime` PostgreSQL fixture proves real CAS/state/revision/receipt/outbox and fault/race
behavior through the public SPI; it is not a reactions/posts product or social e2e test.


`MutationAuthorityFence::capture(OperationHandle, MutationRequest): string` runs outside the
owner transaction before current action/admission/audience/containment guards and returns a
nonempty bounded host generation for the complete request identity. Its
`recheck(OperationHandle, MutationRequest, string $generation): AuthorizationDecision` runs on
the exact owner connection after receipt/surface/target/module locks and immediately before
persist. It proves that all guarded authority facts still permit this operation and holds their
local fence until commit. Host revocation writers must share that fence and compatible lock
order. Recheck performs local SQL only, never remote authorizers/resolvers; host facts without
such proof cannot enable writes. Bind this SPI explicitly as scoped; no permissive default or
implicit capture at boot. Missing => provider_unavailable; revoked => authorization_denied;
stale/unproven => authorization_unknown. A failed claim rolls back before state/revision. The
kernel then re-resolves current guards outside tx without automatically retrying persistence;
explicit retry uses the original key/digest. This includes a UNIQUE waiter becoming the winner
after holder rollback, and applies equally to target-less lifecycle commands.

`MissingReceiptPolicy::decide(OperationHandle, CommandNamespace): AuthorizationDecision` is
the explicit scoped host policy for absent module outcomes. Map supported command types to
their actual OwnerMutation authorization actions consistently with execute; unknown mappings
fail closed. This policy is used for both absence and another actor's key, after trusted
context/surface/admission/audience checks. Missing provider => provider_unavailable; Unknown
never becomes null. Existing owned receipts instead reauthorize the stored action through
replay, irrespective of commandType permission or the current absence mapping. Known kernel
lifecycle commands have an intrinsic exact command-to-action mapping. No new grant is inferred
from a receipt or supplied by payload.

## Worker operation rehydration — P2.9

`OperationJobReference` is trusted scalar queue metadata. Wire v1.0 contains `surface`
(with panel/host/incarnation), `target` (host/incarnation), `actor`, `source_generation`,
original `namespace` (installation/panel/integration/operation type), `key`, `digest` and
`expected_revision`. It contains no actor facts, permissions, ORM, registry or operation closure.
`toWire()` / `fromWire()` preserve these values; unknown top-level fields and unsupported
versions fail closed. Queue authentication/metadata integrity belongs to the host.

The host first activates its trusted isolation and connection resolver, then calls
`OperationJobs::execute(job: $ref, factory: fn (OperationHandle $current): OwnerMutation => ...)`.
The local factory constructs the original owner operation from trusted application data;
it must be side-effect-free and must not choose isolation from the job. Namespace, command type,
explicit target and canonical digest must still match the original command. Retry retains the
original key/digest/CAS revision. There is no automatic retry or queue/Redis dependency.

Installation is compared with `TenantResolver::current()`, source epoch with frozen
`SocKitConfig::events`. `ActorContexts::forWorker()` re-resolves current actor facts for each
consume and each protected envelope entry. Deleted/missing/revoked principals never become
anonymous/system. Target/admission/privacy/containment and receipt replay authorization use
the existing P2.7 envelope, outside owner locks. A changed target incarnation cannot revive an
old reference. A freshly sampled target generation is permitted on a new mutation if all current
guards allow it; replay cannot disclose a receipt bound to a previous target generation.

The private runtime copies container binding maps and replaces only its own actor/handle/storage/
relay instances. It neither installs a current actor in the host container nor resets host scoped
instances. Its immutable actor adapter re-resolves the explicit job principal, preserving caller
context through nested success/throw and Fiber suspension. All per-call state expires with the
call; no global cleanup or container flush runs on success/failure. Host-owned adapters remain
responsible for explicit handle routing and their own isolation; copying container maps does not
make a mutable adapter or one PDO safe across Fibers. Interleaved transactions require independent
host connection managers/PDOs. Any already-open transaction in the current manager is rejected
before actor IO/factory execution, leaving the caller transaction intact.

PostgreSQL acceptance uses `acme.runtime` test-only schema, real commits/rollback, receipt and
outbox assertions. It covers same-process A→B→A, exceptions, reentrant calls on one container,
independent concurrent Fiber transactions and stale worker metadata/permissions. This closes
the P2 worker portion of PANEL-06; generated model relations/hydration/queue restoration remain P3,
and real host consumer qualification remains P6. These fixtures are not social e2e.


The standalone `tests/Fixtures/Consumer` shows host-supplied actor, installation, target,
authority fence and policy adapters with package discovery. It supplies its own module mutation
and inbox; these are test fixtures, not product adapters or production schema.

## Panel persistence extension — P3

Приложение владеет сгенерированным SocKit PanelProvider и concrete Eloquent models каждой панели; provider объявляется в SocKit panels config. Schema fragments принадлежат kernel или соответствующему module, а frozen migrations и ancestry принадлежат consumer. ORM не входит в domain API. Composite tombstone identity требует полного scoped key, а не обычного Eloquent single-key lookup. Процедура и ограничения: [runbook](../guides/panel-install-upgrade.md).
