# SocKit: детализированная референсная архитектура

**Дата актуализации:** 17 октября 2026 года.  
**Статус:** восстановленная и расширенная вторая версия.  
**Назначение:** конкретизация первой версии до структуры репозитория, классов, контрактов, SQL, тестов и эксплуатации. Примеры являются рекомендуемым baseline, а не замороженным API.

## 1. Архитектурный baseline

SocKit — embedded-first Laravel package. Ядро предоставляет нативные posts, comments, reactions, chronological feed, surfaces, current audience checks, revisions, receipts, tombstones и outbox. Chatom, Forum, ranking, search, media bytes, UI, admin и SDK являются optional delivery/integration tracks.

Главные инварианты:

1. Один authoritative writer на state identity.
2. Trusted actor/context создаётся сервером.
3. Current audience применяется перед каждым protected output.
4. Mutation identity = namespace + command key + canonical payload digest.
5. Domain state, receipt и outbox intent атомарны в embedded profile.
6. Revision/CAS предотвращает lost update.
7. Tombstone/incarnation предотвращают resurrection.
8. Extension может сузить решение, но не отключить обязательную проверку.
9. Projection rebuild не повторяет historical side effects.
10. Public API не выдаёт Eloquent models.

## 2. Структура репозитория

```text
sockit/
├── .github/workflows/
│   ├── ci.yml
│   ├── compatibility.yml
│   └── release.yml
├── config/
│   └── sockit.php
├── database/
│   ├── migrations/
│   │   ├── 0001_01_01_000001_create_sk_surfaces.php
│   │   ├── 0001_01_01_000002_create_sk_content.php
│   │   ├── 0001_01_01_000003_create_sk_reactions.php
│   │   └── 0001_01_01_000004_create_sk_delivery.php
│   └── factories/
├── docs/
│   ├── architecture/
│   │   ├── boundaries.md
│   │   ├── state-ownership.md
│   │   ├── privacy-model.md
│   │   └── failure-semantics.md
│   ├── contracts/
│   │   ├── public-api.md
│   │   ├── host-spi.md
│   │   ├── events.md
│   │   └── problem-codes.md
│   ├── operations/
│   │   ├── installation.md
│   │   ├── outbox.md
│   │   ├── backup-restore.md
│   │   └── erasure.md
│   └── adr/
│       ├── 0001-embedded-first.md
│       ├── 0002-native-comments.md
│       ├── 0003-current-audience.md
│       ├── 0004-command-receipts.md
│       └── 0005-postgresql-baseline.md
├── resources/
│   ├── compatibility/public-api-v1.txt
│   └── schema/
│       ├── events/v1/
│       └── http/v1/
├── src/
│   ├── Api/
│   │   ├── Attribute/
│   │   ├── Command/
│   │   ├── Query/
│   │   ├── Result/
│   │   ├── View/
│   │   ├── Event/
│   │   ├── Exception/
│   │   ├── Enum/
│   │   ├── Value/
│   │   └── SocKit.php
│   ├── Spi/
│   │   ├── Actor/
│   │   ├── Authorization/
│   │   ├── Audience/
│   │   ├── Content/
│   │   ├── Media/
│   │   ├── Safety/
│   │   ├── Projection/
│   │   └── Clock/
│   ├── Internal/
│   │   ├── Application/
│   │   ├── Domain/
│   │   ├── Persistence/
│   │   ├── Delivery/
│   │   ├── Projection/
│   │   ├── Configuration/
│   │   └── Diagnostics/
│   ├── Laravel/
│   │   ├── SocKitServiceProvider.php
│   │   ├── Facades/SocKit.php
│   │   ├── Console/
│   │   ├── Http/
│   │   └── Queue/
│   └── Support/
├── tests/
│   ├── Unit/
│   ├── Integration/Postgres/
│   ├── Concurrency/
│   ├── Contract/HostAdapters/
│   ├── Contract/EventSchemas/
│   ├── Architecture/
│   ├── Compatibility/
│   └── Fixtures/
├── workbench/
├── composer.json
├── phpstan.neon
├── phpunit.xml
├── pint.json
└── rector.php
```

## 3. Namespace и naming rules

- `SocKit\Api` — consumer contracts.
- `SocKit\Spi` — host/extension implementations.
- `SocKit\Internal` — private implementation.
- `SocKit\Laravel` — integration layer.
- `SocKit\Support` — только действительно нейтральные малые utilities.

Naming:

- `PublishPostCommand`, `PublishPostHandler`, `MutationResult`.
- `GetFeedQuery`, `FeedPageView`.
- `PostPublished`, `CommentEdited` — завершённые факты.
- `AudienceDecider`, `ContentSafetyProvider` — capability names.
- Избегать `Manager`, `Helper`, `Util`, `BaseService`.
- Eloquent records: `PostRecord`, `CommentRecord`; не путать с domain entities.
- Repository interfaces не публиковать в API без внешнего потребителя.

## 4. composer.json

```json
{
  "name": "vendor/sockit",
  "description": "Embeddable social core for Laravel applications",
  "type": "library",
  "license": "MIT",
  "require": {
    "php": "^8.4",
    "illuminate/container": "^13.0",
    "illuminate/contracts": "^13.0",
    "illuminate/database": "^13.0",
    "illuminate/events": "^13.0",
    "illuminate/support": "^13.0",
    "psr/clock": "^1.0",
    "psr/log": "^3.0"
  },
  "require-dev": {
    "larastan/larastan": "^3.0",
    "orchestra/testbench": "^11.0",
    "pestphp/pest": "^4.0",
    "phpstan/phpstan": "^2.0"
  },
  "autoload": {
    "psr-4": {"SocKit\\": "src/"}
  },
  "autoload-dev": {
    "psr-4": {"SocKit\\Tests\\": "tests/"}
  },
  "extra": {
    "laravel": {
      "providers": ["SocKit\\Laravel\\SocKitServiceProvider"]
    }
  }
}
```

Точные dev dependency versions проверяются при bootstrap. Public ID value objects не должны раскрывать выбранную UUID-библиотеку.

## 5. Конфигурация

```php
return [
    'connection' => env('SOCKIT_DB_CONNECTION'),
    'table_prefix' => 'sk_',

    'ids' => ['strategy' => 'uuid_v7'],

    'content' => [
        'max_body_bytes' => 65_536,
        'comment_max_depth' => 8,
        'default_format' => 'plain_text',
    ],

    'feed' => [
        'default_page_size' => 20,
        'maximum_page_size' => 100,
        'candidate_oversampling_factor' => 3,
        'maximum_refill_rounds' => 4,
    ],

    'outbox' => [
        'batch_size' => 100,
        'lease_seconds' => 60,
        'max_attempts' => 20,
    ],

    'drivers' => [
        'actor_context' => App\SocKit\EdufyActorContextResolver::class,
        'authorization' => App\SocKit\EdufyAuthorizationDecider::class,
        'audience' => App\SocKit\EdufyAudienceDecider::class,
        'safety' => SocKit\Internal\Safety\DisabledSafetyProvider::class,
        'media' => SocKit\Internal\Media\RejectAllMediaResolver::class,
    ],
];
```

Только scalars, arrays и class strings. Closures, objects и runtime callbacks запрещены. `DisabledSafetyProvider` допустим только для surface без обязательной moderation capability.

## 6. Service provider

```php
final class SocKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/sockit.php', 'sockit');

        $this->app->singleton(
            \SocKit\Api\SocKit::class,
            \SocKit\Internal\Application\InternalSocKit::class,
        );

        $this->app->singleton(
            TypedSocKitConfig::class,
            fn (Application $app) => ConfigFactory::fromArray(
                $app['config']->get('sockit', []),
            ),
        );

        $this->registerHostDrivers();
        $this->registerRepositories();
        $this->registerHandlers();
    }

    public function boot(): void
    {
        $this->app->make(TypedSocKitConfig::class)->assertValid();

        $this->publishes([
            __DIR__.'/../../config/sockit.php' => config_path('sockit.php'),
        ], 'sockit-config');

        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([
                OutboxRunCommand::class,
                OutboxInspectCommand::class,
                RebuildReactionCountsCommand::class,
                DiagnoseInstallationCommand::class,
            ]);
        }
    }
}
```

Tagged services разрешены для additive projection consumers. Security stages не собираются произвольным tag order.

## 7. Attributes и public boundary

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Api {}

#[Attribute(Attribute::TARGET_CLASS)]
final class Spi {}
```

Attributes — документация и input для architecture tests; сами по себе не обеспечивают совместимость.

Architecture checks:

- `Api` не импортирует `Internal`.
- `Spi` не импортирует `Internal`.
- Public signatures не содержат Eloquent/Builder/Request/Response.
- `Internal` classes не отмечаются `Api`.
- Host workbench не импортирует `Internal`.

## 8. Value objects

### CommandKey

```php
#[Api]
final readonly class CommandKey
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $value = trim($value);

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/', $value)) {
            throw InvalidCommandKey::becauseFormatIsInvalid();
        }

        return new self($value);
    }
}
```

Ключ сохраняется между retries. Namespace формируется сервером из installation, caller integration и command type.

### HostEntityRef

```php
#[Api]
final readonly class HostEntityRef
{
    public function __construct(
        public string $type,
        public string $value,
    ) {
        if ($type === '' || strlen($type) > 96) {
            throw InvalidHostEntityRef::invalidType();
        }
        if ($value === '' || strlen($value) > 512) {
            throw InvalidHostEntityRef::invalidValue();
        }
    }
}
```

External ID хранится lossless. Дополнительный hash может ускорять lookup, но не заменяет canonical value.

### Revision

```php
#[Api]
final readonly class Revision
{
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw InvalidRevision::mustBePositive();
        }
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }
}
```

## 9. Public command/result

```php
#[Api]
final readonly class PublishPostCommand
{
    public function __construct(
        public CommandKey $commandKey,
        public TrustedActorContext $actor,
        public SurfaceId $surfaceId,
        public BodyInput $body,
        public AudienceInput $audience,
        public ?HostEntityRef $source = null,
        public array $metadata = [],
    ) {}
}
```

```php
enum MutationStatus: string
{
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Pending = 'pending';
    case Unknown = 'unknown';
}

#[Api]
final readonly class MutationResult
{
    public function __construct(
        public MutationStatus $status,
        public ReceiptId $receiptId,
        public ?ResourceRef $resource,
        public ?Revision $revision,
        public ?Problem $problem,
    ) {}
}
```

Repeat same key+digest returns original result after current authorization. Same key+different digest returns `sockit.idempotency_conflict`.

## 10. Host SPI

```php
#[Spi]
interface ActorContextResolver
{
    public function resolve(TrustedInvocation $invocation): TrustedActorContext;
}

#[Spi]
interface AuthorizationDecider
{
    public function decide(ActionRequest $request): AuthorizationDecision;
}

#[Spi]
interface AudienceDecider
{
    public function decideRead(ReadAudienceRequest $request): AudienceDecision;

    public function assertCanPublish(
        PublishAudienceRequest $request,
    ): PublishAudienceDecision;
}

#[Spi]
interface BatchAudienceDecider extends AudienceDecider
{
    /** @return array<string, AudienceDecision> */
    public function decideMany(BatchReadAudienceRequest $request): array;
}
```

Decision содержит allow/deny/unknown, stable reason code, policy version/generation и optional expiry. Unknown fail closed. Explain не раскрывает существование скрытого content.

## 11. Surface registration

```php
final class EdufySocKitServiceProvider extends ServiceProvider
{
    public function boot(SurfaceKindRegistry $registry): void
    {
        $registry->register(new SurfaceKindDefinition(
            key: SurfaceKindKey::fromString('edufy.class.feed'),
            capabilities: CapabilitySet::of(
                Capability::Posts,
                Capability::Comments,
                Capability::Reactions,
            ),
            minimumAudience: AudienceConstraint::hostScope('class'),
            commentBackend: CommentBackend::Native,
            contentFormat: ContentFormatKey::fromString('plain_text'),
        ));
    }
}
```

Conflict same key/different digest — boot error. Same key/same digest — idempotent registration.

```php
$result = $socKit->surfaces()->create(new CreateSurfaceCommand(
    commandKey: CommandKey::fromString($requestId),
    actor: $trustedContext,
    kind: SurfaceKindKey::fromString('edufy.class.feed'),
    hostEntity: new HostEntityRef('edufy.class', (string) $class->id),
    audience: AudienceInput::hostScope('class', (string) $class->id),
));
```

## 12. Content state machine

```php
enum ContentState: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Hidden = 'hidden';
    case Deleted = 'deleted';
    case Erased = 'erased';
}
```

```text
draft -> pending_review -> published
  |             |             |
  +-> deleted   +-> deleted   +-> hidden -> published
                                  |           |
                                  +-> deleted +-> erased
published -> deleted -> erased
```

Retention hold — отдельное состояние/record, способное сосуществовать с hidden/deleted. Restore — новая mutation с текущими consent/audience/safety checks. Erased body обычным restore не возвращается.

## 13. Application pipeline

```text
1. Decode and structural validation
2. Resolve trusted actor/context
3. Validate surface/incarnation/backend
4. Current authorization
5. Audience containment/minimum policy
6. Safety/media preconditions
7. Begin SQL transaction
8. Lock/lookup command receipt
9. Compare canonical payload digest
10. Lock resource and recheck state/revision/tombstone
11. Apply owner mutation
12. Save receipt and outbox
13. Commit
14. Asynchronous delivery/projection
```

Remote network call внутри SQL transaction запрещён. Pre-transaction remote verdict должен иметь version/generation token, повторно проверяемый локально, или mutation оформляется как durable intent workflow.

## 14. Publish handler

```php
final class PublishPostHandler
{
    public function __construct(
        private ConnectionInterface $db,
        private ReceiptRepository $receipts,
        private SurfaceRepository $surfaces,
        private PostRepository $posts,
        private OutboxRepository $outbox,
        private AuthorizationDecider $authorization,
        private AudienceDecider $audience,
        private CanonicalPayloadHasher $hasher,
        private ClockInterface $clock,
        private IdGenerator $ids,
    ) {}

    public function handle(PublishPostCommand $command): MutationResult
    {
        $surface = $this->surfaces->getActive($command->surfaceId);

        $this->authorization->decide(
            ActionRequest::publishPost($command->actor, $surface),
        )->requireAllowed();

        $audience = $this->audience->assertCanPublish(
            PublishAudienceRequest::from($command, $surface),
        )->audience;

        $canonical = CanonicalPublishPayload::from($command, $audience);
        $digest = $this->hasher->hash($canonical);

        return $this->db->transaction(function () use (
            $command, $surface, $audience, $canonical, $digest
        ) {
            $existing = $this->receipts->lockByCommand(
                CommandNamespace::for($command, $surface),
                $command->commandKey,
            );

            if ($existing !== null) {
                return $existing->replayOrConflict($digest);
            }

            $surface = $this->surfaces->lockActiveIncarnation($surface->id);

            $post = Post::publish(
                id: $this->ids->postId(),
                surface: $surface,
                author: $command->actor->actor,
                body: $canonical->body,
                audience: $audience,
                now: $this->clock->now(),
            );

            $this->posts->insert($post);

            $event = PostPublished::fromPost(
                eventId: $this->ids->eventId(),
                post: $post,
            );

            $receipt = MutationReceipt::confirmed(
                receiptId: $this->ids->receiptId(),
                command: $command,
                digest: $digest,
                resource: $post->ref(),
                revision: $post->revision(),
                now: $this->clock->now(),
            );

            $this->receipts->insert($receipt);
            $this->outbox->append($event);

            return $receipt->toResult();
        }, attempts: 3);
    }
}
```

## 15. SQL: receipts

```sql
CREATE TABLE sk_command_receipts (
    id uuid PRIMARY KEY,
    command_namespace varchar(160) NOT NULL,
    command_key varchar(128) NOT NULL,
    command_type varchar(120) NOT NULL,
    payload_digest char(64) NOT NULL,
    status varchar(24) NOT NULL,
    resource_type varchar(64),
    resource_id uuid,
    applied_revision bigint,
    problem_code varchar(96),
    result_payload jsonb NOT NULL,
    created_at timestamptz NOT NULL,
    completed_at timestamptz,
    UNIQUE (command_namespace, command_key)
);
```

`result_payload` — stable minimal result, не ORM serialization. Receipt retention не короче retry/replay/backup horizon.

## 16. SQL: surfaces

```sql
CREATE TABLE sk_surfaces (
    id uuid PRIMARY KEY,
    kind varchar(160) NOT NULL,
    host_entity_type varchar(96) NOT NULL,
    host_entity_value varchar(512) NOT NULL,
    incarnation bigint NOT NULL,
    lifecycle_state varchar(32) NOT NULL,
    audience_spec_id uuid,
    created_at timestamptz NOT NULL,
    archived_at timestamptz,
    UNIQUE (kind, host_entity_type, host_entity_value, incarnation)
);

CREATE TABLE sk_surface_bindings (
    surface_id uuid NOT NULL REFERENCES sk_surfaces(id),
    incarnation bigint NOT NULL,
    backend varchar(64) NOT NULL,
    backend_config_version smallint NOT NULL,
    binding_digest char(64) NOT NULL,
    created_at timestamptz NOT NULL,
    PRIMARY KEY (surface_id, incarnation)
);
```

Binding не меняется config flip. Migration создаёт новое generation/incarnation или explicit binding migration record.

## 17. SQL: posts/revisions

```sql
CREATE TABLE sk_posts (
    id uuid PRIMARY KEY,
    surface_id uuid NOT NULL REFERENCES sk_surfaces(id),
    surface_incarnation bigint NOT NULL,
    author_type varchar(64) NOT NULL,
    author_id varchar(512) NOT NULL,
    state varchar(32) NOT NULL,
    body_format varchar(48) NOT NULL,
    current_body text,
    revision bigint NOT NULL,
    privacy_revision bigint NOT NULL,
    audience_spec_id uuid NOT NULL,
    published_at timestamptz,
    hidden_at timestamptz,
    deleted_at timestamptz,
    erased_at timestamptz,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    CHECK (revision >= 1),
    CHECK (privacy_revision >= 1),
    CHECK ((state <> 'erased') OR current_body IS NULL)
);

CREATE INDEX sk_posts_surface_feed_idx
    ON sk_posts (surface_id, published_at DESC, id DESC)
    WHERE state = 'published';
```

```sql
CREATE TABLE sk_post_revisions (
    post_id uuid NOT NULL REFERENCES sk_posts(id),
    revision bigint NOT NULL,
    state varchar(32) NOT NULL,
    body_format varchar(48) NOT NULL,
    body text,
    audience_spec_id uuid NOT NULL,
    actor_type varchar(64) NOT NULL,
    actor_id varchar(512) NOT NULL,
    reason_code varchar(96),
    recorded_at timestamptz NOT NULL,
    PRIMARY KEY (post_id, revision)
);
```

Erase workflow удаляет revision bodies по policy, оставляя минимальный audit digest/state transition.

## 18. SQL: comments

```sql
CREATE TABLE sk_comments (
    id uuid PRIMARY KEY,
    post_id uuid NOT NULL REFERENCES sk_posts(id),
    parent_id uuid REFERENCES sk_comments(id),
    root_id uuid NOT NULL,
    depth smallint NOT NULL,
    author_type varchar(64) NOT NULL,
    author_id varchar(512) NOT NULL,
    state varchar(32) NOT NULL,
    current_body text,
    revision bigint NOT NULL,
    privacy_revision bigint NOT NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    deleted_at timestamptz,
    CHECK (depth >= 0),
    CHECK (revision >= 1)
);

CREATE INDEX sk_comments_thread_idx
    ON sk_comments (post_id, root_id, created_at, id);
```

Handler под lock проверяет, что parent относится к тому же post, и рассчитывает depth. Deleted parent сохраняет structural tombstone.

## 19. CAS editing

```sql
UPDATE sk_comments
SET current_body = :body,
    revision = revision + 1,
    updated_at = :now
WHERE id = :id
  AND revision = :expected_revision
  AND state IN ('published', 'hidden')
RETURNING id, revision;
```

Ноль rows разрешается safe follow-up lookup с anti-enumeration policy. Нельзя автоматически повторять edit поверх новой revision.

## 20. SQL: reactions

```sql
CREATE TABLE sk_reactions (
    id uuid PRIMARY KEY,
    subject_type varchar(32) NOT NULL,
    subject_id uuid NOT NULL,
    actor_type varchar(64) NOT NULL,
    actor_id varchar(512) NOT NULL,
    reaction_type varchar(64) NOT NULL,
    created_at timestamptz NOT NULL,
    UNIQUE (subject_type, subject_id, actor_type, actor_id, reaction_type)
);
```

Если policy допускает только одну reaction на subject, `reaction_type` удаляется из unique key. Смена — deterministic replace в одной transaction.

```sql
CREATE TABLE sk_reaction_counts (
    subject_type varchar(32) NOT NULL,
    subject_id uuid NOT NULL,
    reaction_type varchar(64) NOT NULL,
    count bigint NOT NULL,
    source_revision bigint NOT NULL,
    PRIMARY KEY (subject_type, subject_id, reaction_type),
    CHECK (count >= 0)
);
```

Counter rebuildable; negative count — invariant violation, не значение для silent clamp.

## 21. Feed cursor и pagination

```php
final readonly class FeedCursorPayload
{
    public function __construct(
        public DateTimeImmutable $publishedAt,
        public PostId $postId,
        public SurfaceId $surfaceId,
        public int $queryVersion,
    ) {}
}
```

Cursor сериализуется canonical JSON/base64url и подписывается MAC. Raw client SQL values запрещены.

```sql
SELECT id, published_at, privacy_revision
FROM sk_posts
WHERE surface_id = :surface_id
  AND state = 'published'
  AND (published_at, id) < (:cursor_time, :cursor_id)
ORDER BY published_at DESC, id DESC
LIMIT :candidate_limit;
```

После batch audience filtering kernel делает bounded refill. Next cursor строится по последнему просмотренному candidate, а не последнему visible row.

## 22. SQL: outbox

```sql
CREATE TABLE sk_outbox_events (
    id uuid PRIMARY KEY,
    aggregate_type varchar(64) NOT NULL,
    aggregate_id uuid NOT NULL,
    aggregate_revision bigint NOT NULL,
    event_type varchar(160) NOT NULL,
    event_schema_version smallint NOT NULL,
    payload_digest char(64) NOT NULL,
    payload jsonb NOT NULL,
    occurred_at timestamptz NOT NULL,
    available_at timestamptz NOT NULL,
    attempts integer NOT NULL DEFAULT 0,
    lease_owner varchar(128),
    lease_until timestamptz,
    delivered_at timestamptz,
    last_problem_code varchar(96),
    UNIQUE (aggregate_type, aggregate_id, aggregate_revision, event_type)
);
```

Claim:

```sql
WITH claimed AS (
    SELECT id
    FROM sk_outbox_events
    WHERE delivered_at IS NULL
      AND available_at <= now()
      AND (lease_until IS NULL OR lease_until < now())
    ORDER BY occurred_at, id
    FOR UPDATE SKIP LOCKED
    LIMIT :batch_size
)
UPDATE sk_outbox_events e
SET lease_owner = :worker_id,
    lease_until = now() + :lease_interval,
    attempts = attempts + 1
FROM claimed
WHERE e.id = claimed.id
RETURNING e.*;
```

Queue uniqueness — оптимизация, не correctness boundary. Истина — outbox row + consumer inbox unique key.

## 23. Event envelope

```json
{
  "event_id": "019...",
  "event_type": "sockit.post.published",
  "schema_version": 1,
  "source": {
    "installation": "opaque-installation-id",
    "generation": "019..."
  },
  "aggregate": {
    "type": "post",
    "id": "019...",
    "revision": 1,
    "privacy_revision": 1
  },
  "surface": {
    "id": "019...",
    "incarnation": 3
  },
  "occurred_at": "2026-10-17T11:30:00.000000Z",
  "context": {
    "audience_spec_id": "019...",
    "body_available": true
  }
}
```

Generic event не содержит body, DOB, membership list или direct recipients. Authorized projector получает snapshot через owner API.

## 24. Consumer inbox

```sql
CREATE TABLE sk_consumer_inbox (
    consumer varchar(160) NOT NULL,
    event_id uuid NOT NULL,
    payload_digest char(64) NOT NULL,
    source_generation uuid NOT NULL,
    outcome varchar(24) NOT NULL,
    processed_at timestamptz,
    problem_code varchar(96),
    PRIMARY KEY (consumer, event_id)
);
```

Same event/same digest returns original outcome. Same event/different digest = conflict/quarantine.

## 25. Audience evaluation

```php
final readonly class ReadAudienceRequest
{
    public function __construct(
        public TrustedActorContext $actor,
        public SurfaceRef $surface,
        public ResourceRef $resource,
        public AudienceSpecRef $audience,
        public PrivacyRevision $privacyRevision,
    ) {}
}
```

Cache key:

```text
sockit:audience:v1:
  actor=<opaque actor digest>:
  context_generation=<host generation>:
  resource=<type/id>:
  privacy_revision=<n>:
  policy_version=<v>
```

Cache miss/timeout = unknown/deny unless explicit safe local fallback. Positive cache TTL должен быть коротким и/или generation-invalidated. Negative cache допустим дольше, если не ломает новые grants.

## 26. Edufy adapter example

```php
final class EdufyAudienceDecider implements BatchAudienceDecider
{
    public function __construct(
        private CurrentEnrollmentGateway $enrollments,
        private ParentObservationGateway $parents,
        private ConsentGateway $consents,
    ) {}

    public function decideRead(ReadAudienceRequest $request): AudienceDecision
    {
        $actor = $request->actor;
        $scope = $request->audience->requireHostScope('class');

        if ($actor->ageBand->isUnknownMinorContext()) {
            return AudienceDecision::deny('unknown_age_conservative');
        }

        if ($this->enrollments->isCurrentMember($actor->actor, $scope)) {
            return AudienceDecision::allow('current_class_member');
        }

        if ($this->parents->mayObserve($actor->actor, $scope)) {
            return AudienceDecision::allowReadOnly('parent_observer');
        }

        return AudienceDecision::deny('not_currently_admitted');
    }
}
```

Parent observer decision не используется для write command. Consent/safety checks отделены от membership.

## 27. Chatom bridge

```text
sockit-chatom/
├── src/Api/
├── src/Internal/Announcement/
├── src/Internal/CommentBackend/
├── src/Internal/Mapping/
├── src/Internal/Reconciliation/
├── src/Laravel/
├── database/migrations/
└── tests/Contract/
```

```sql
CREATE TABLE scb_announcement_mappings (
    id uuid PRIMARY KEY,
    sockit_post_id uuid NOT NULL,
    sockit_incarnation bigint NOT NULL,
    chatom_channel_id varchar(512) NOT NULL,
    chatom_message_id varchar(512),
    source_privacy_revision bigint NOT NULL,
    backend_receipt varchar(512),
    state varchar(32) NOT NULL,
    UNIQUE (sockit_post_id, sockit_incarnation, chatom_channel_id)
);
```

Workflow:

1. Consume event through bridge inbox.
2. Resolve current authorized post snapshot.
3. Resolve current channel audience.
4. Verify containment.
5. Persist send intent/stable backend key.
6. Call Chatom outside SocKit transaction.
7. On timeout call outcome lookup with same key.
8. Store mapping/receipt.
9. Process shrink/delete as redact/delete intent.
10. Reject stale event by privacy revision/tombstone.

Если Chatom не имеет idempotency/outcome lookup, bridge нельзя объявлять exactly-once-effect. Допустима честно описанная at-least-once доставка или отказ от production bridge.

## 28. HTTP adapter

```php
final class PublishPostController
{
    public function __invoke(
        PublishPostRequest $request,
        SocKit $socKit,
        HttpActorContextFactory $actors,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);

        $key = $request->header('Idempotency-Key');
        if ($key === null) {
            throw MissingIdempotencyKey::new();
        }

        $result = $socKit->posts()->publish(
            PublishPostCommand::fromHttp(
                request: $request,
                actor: $actor,
                commandKey: CommandKey::fromString($key),
            ),
        );

        return MutationResponseMapper::toJson($result);
    }
}
```

Domain handler не читает global `request()`, `auth()` или facade. Rate limit является transport concern, не domain authorization.

## 29. Problem response и codes

```json
{
  "type": "https://example.test/problems/sockit/revision-conflict",
  "title": "Revision conflict",
  "status": 409,
  "code": "sockit.revision_conflict",
  "trace_id": "...",
  "meta": {"current_revision": 8}
}
```

Stable codes:

```text
sockit.authentication_required
sockit.actor_context_invalid
sockit.authorization_denied
sockit.authorization_unknown
sockit.surface_not_found
sockit.surface_archived
sockit.surface_incarnation_mismatch
sockit.backend_binding_mismatch
sockit.audience_not_contained
sockit.audience_version_unknown
sockit.content_held
sockit.content_hidden
sockit.content_deleted
sockit.content_erased
sockit.revision_conflict
sockit.idempotency_conflict
sockit.provider_unavailable
sockit.external_outcome_unknown
sockit.event_identity_conflict
sockit.configuration_invalid
```

Exception messages не являются machine contract.

## 30. Config validation

Проверяются:

- DB connection/profile;
- prefix whitelist;
- class existence и SPI implementation;
- обязательные providers по capabilities;
- limits ranges;
- backend key uniqueness;
- consumer name uniqueness;
- config cache serialization;
- migration/schema compatibility.

Ошибка возникает при boot/diagnose, не при первой production mutation.

## 31. Security boundaries

Запрещено:

- выдавать Eloquent models публично;
- mass-assign request в model;
- проверять authorization только в controller;
- доверять `author_id`/`school_id`/`surface_id`;
- логировать body/audience/member list;
- выдавать permanent media URLs;
- позволять host писать package tables;
- импортировать `Internal` из bridge;
- трактовать cache miss как allow;
- считать search result правом;
- разрешать arbitrary extension callback менять deny на allow.

## 32. Logging и metrics

```php
$logger->info('sockit.mutation.completed', [
    'sockit.command.type' => 'publish_post',
    'sockit.command.key_hash' => $keyHasher->forLog($commandKey),
    'sockit.receipt.id' => (string) $receiptId,
    'sockit.surface.kind' => $surfaceKind->value,
    'sockit.outcome' => 'confirmed',
    'sockit.revision' => 1,
    'trace_id' => $traceContext->traceId(),
]);
```

Не логируются raw key, body, display name, recipient list и credentials.

Metrics:

```text
sockit_mutation_duration_seconds{command,outcome}
sockit_authorization_decisions_total{action,decision,reason_family}
sockit_receipt_conflicts_total{command}
sockit_outbox_oldest_age_seconds{consumer}
sockit_outbox_attempts_total{consumer,outcome}
sockit_projection_lag_seconds{projection}
sockit_feed_candidates_total{surface_kind}
sockit_feed_visible_total{surface_kind}
sockit_revision_conflicts_total{resource_type}
```

IDs не используются как metric labels.

## 33. Operations commands

```text
php artisan sockit:diagnose
php artisan sockit:outbox:run --once --batch=100
php artisan sockit:outbox:inspect --older-than=5m
php artisan sockit:outbox:retry <event-id>
php artisan sockit:reactions:rebuild --subject=post:<id>
php artisan sockit:projection:rebuild <projection>
php artisan sockit:tombstones:verify
php artisan sockit:receipts:verify
php artisan sockit:surface:inspect <surface-id>
```

Projection rebuild использует `ReplayMode::ProjectionOnly` и не рассылает historical notifications.

## 34. Backup, restore и clone

Installation имеет `source_generation_id`.

Restore in place:

1. schema check;
2. source generation check;
3. tombstone high-water reconciliation;
4. receipt/outbox/inbox consistency;
5. projection rebuild;
6. explicit read unlock.

Clone/staging получает новый generation; outbound delivery disabled по умолчанию. Иначе clone может повторить production notifications.

## 35. Erasure

```php
enum ErasureOutcome: string
{
    case Confirmed = 'confirmed';
    case PartiallyCompleted = 'partially_completed';
    case NotImplemented = 'not_implemented';
    case BlockedByRetentionHold = 'blocked_by_retention_hold';
    case FailedRetryable = 'failed_retryable';
    case FailedPermanent = 'failed_permanent';
}
```

Host orchestrator отдельно вызывает SocKit, media, search, notifications и bridges. Partial/not-implemented не агрегируется как success.

## 36. Tests

```text
tests/
├── Unit/
│   ├── Domain/PostTest.php
│   ├── Domain/CommentTest.php
│   ├── Audience/AudienceExpressionTest.php
│   └── Idempotency/CanonicalPayloadHasherTest.php
├── Integration/Postgres/
│   ├── PublishPostHandlerTest.php
│   ├── EditCommentCasTest.php
│   ├── OutboxClaimTest.php
│   └── TombstoneReplayTest.php
├── Concurrency/
│   ├── DuplicatePublishTest.php
│   ├── ConcurrentCommentEditTest.php
│   ├── ReactionVersusHideTest.php
│   └── OutboxWorkersTest.php
├── Contract/HostAdapters/
│   ├── AudienceDeciderContract.php
│   └── AuthorizationDeciderContract.php
├── Architecture/
│   ├── PublicApiBoundaryTest.php
│   ├── NoInternalImportsTest.php
│   └── NoEloquentInApiTest.php
└── Compatibility/
    ├── PublicSymbolsSnapshotTest.php
    ├── EventFixtureTest.php
    └── MigrationUpgradeTest.php
```

Concurrency test использует независимые DB connections/processes:

```php
it('returns one post for concurrent identical commands', function () {
    $barrier = ProcessBarrier::forParticipants(2);
    $command = Fixtures::publishPost(
        commandKey: 'test.concurrent.publish.0001',
    );

    $results = Concurrently::run([
        fn () => $this->newProcessClient($barrier)->publish($command),
        fn () => $this->newProcessClient($barrier)->publish($command),
    ]);

    expect(PostRecord::query()->count())->toBe(1)
        ->and($results[0]->resource)->toEqual($results[1]->resource)
        ->and(ReceiptRecord::query()->count())->toBe(1);
});
```

SQLite не доказывает PostgreSQL locking/partial indexes/concurrency.

## 37. Adapter contract suite

Каждый AudienceDecider проходит:

- unknown actor/context/version → deny/unknown;
- direct grant не даёт write;
- class A ≠ class B;
- membership removal закрывает current read;
- parent observer read не даёт comment write;
- surface minimum нельзя ослабить;
- batch/scalar эквивалентны;
- explain не раскрывает hidden body.

## 38. CI matrix

```yaml
strategy:
  matrix:
    php: ['8.4', '8.5']
    laravel: ['13.*']
    postgres: ['17', '18']
```

Stages:

1. Composer validate.
2. Lowest/highest dependencies.
3. Style.
4. PHPStan.
5. Unit/integration.
6. PostgreSQL concurrency.
7. Architecture/API checks.
8. Fresh and upgrade migrations.
9. Build package archive.
10. Install archive into clean host.
11. `config:cache`, migrate, diagnose, smoke.

## 39. Compatibility

Отдельно versioned:

- PHP application API;
- SPI;
- HTTP/wire;
- events;
- DB schema;
- config schema.

`public-api-v1.txt` содержит FQCN, constructors/methods, parameter/return types, enum cases и attributes. Required constructor parameter, method removal или type narrowing — breaking. Новая enum case может ломать exhaustive match.

SPI расширяется capability interface, а не новым method в стабильном interface:

```php
interface AudienceDeciderV1 {}
interface SupportsBatchAudienceDecisions {}
```

## 40. Documentation requirements

Каждый command документирует:

- trusted inputs;
- state owner;
- authorization timing;
- idempotency namespace/digest;
- transaction boundary;
- revision semantics;
- emitted events;
- problem codes;
- retry guidance;
- PII/logging.

Каждый SPI документирует:

- caller/frequency;
- transaction context;
- timeout/retry;
- veto authority;
- fail-open/fail-closed;
- cacheability/version;
- batch capability;
- compatibility.

## 41. ADR baseline

- ADR-0001 Embedded-first.
- ADR-0002 Native comments.
- ADR-0003 Current audience.
- ADR-0004 Command receipts.
- ADR-0005 Transactional outbox.
- ADR-0006 Surface identity.
- ADR-0007 PostgreSQL baseline.
- ADR-0008 Api/Spi/Internal.
- ADR-0009 Body ownership.
- ADR-0010 Deletion semantics.

## 42. Implementation dependency order

1. Value objects/problem taxonomy/boundary tests.
2. Typed config/install smoke.
3. Surface identity.
4. Trusted context and host contract tests.
5. Receipts/canonical digest.
6. Posts/revisions/outbox transaction.
7. Feed/current audience.
8. Comments/CAS.
9. Reactions/counters.
10. Hide/delete/erase/tombstone/restore.
11. Outbox/inbox/replay diagnostics.
12. Backup/clone/erasure.
13. HTTP adapter.
14. Chatom bridge only after seam audit.

## 43. Definition of Done v1

- Composer artifact installs in clean Laravel host.
- `config:cache`, migrations and diagnostics pass.
- Host adapters import no `Internal`.
- Publish/comment/reaction work without optional services.
- Revocation blocks stale candidate before body.
- Duplicate/lost response returns original receipt.
- Concurrent edit produces revision conflict.
- Old event after delete cannot resurrect content.
- Outbox replay cannot duplicate projection effect.
- Restore rechecks current policy.
- Logs contain no body/raw audience.
- API/SPI/event/migration compatibility is CI-enforced.
- Full scenario works: `publish → feed → comment → reaction → revoke → hide/delete → replay/rebuild`.
