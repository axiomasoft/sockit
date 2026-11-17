<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Override;
use Packman\Attributes\Internal;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\GroupRef;
use SocKit\Audience\Enums\Scope;
use SocKit\Database\Internal\SockitRows;
use SocKit\Database\Internal\SockitTables;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Modules\Posts\Configuration\PostsOptions;
use SocKit\Modules\Posts\Contracts\PostService;
use SocKit\Modules\Posts\Data\EditPostCommand;
use SocKit\Modules\Posts\Data\PostView;
use SocKit\Modules\Posts\Data\PublishPostCommand;
use SocKit\Modules\Posts\Data\TransitionPostStateCommand;
use SocKit\Modules\Posts\Enums\ContentState;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\PanelException;
use SocKit\Registrars\Internal\SockitRuntime;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Support\Ids;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Data\SurfaceDefinition;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Enums\SurfaceLifecycleState;

/**
 * Panel-isolated Posts write and single-post read service enforcing ActionAuthorizer,
 * SurfaceAdmission, AudienceGate, minor-aware privacy defaults, CAS, safety hold outbox
 * invalidation events, and tombstoned erasure.
 */
#[Internal]
final readonly class DatabasePostService implements PostService
{
    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
        private SurfaceDefinitions $surfaces,
        private SockitRuntime $runtime,
    ) {}

    #[Override]
    public function publish(PublishPostCommand $command): OperationResult
    {
        [$options, $surfaceDef] = $this->assertPostsEnabled($command->surface);

        if (strlen($command->body) > $options->maxBodyBytes) {
            throw InvalidPayloadException::invalid(
                "Post body exceeds max_body_bytes [{$options->maxBodyBytes}].",
                ['max_body_bytes' => $options->maxBodyBytes],
                '/body',
            );
        }

        if (! $options->allowsFormat($command->bodyFormat)) {
            throw InvalidPayloadException::invalid(
                "Body format [{$command->bodyFormat}] is not enabled on panel [{$command->surface->panel->value}].",
                ['body_format' => $command->bodyFormat],
                '/body_format',
            );
        }

        $actor = $this->runtime->actors()->current();
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'posts.publish');
        $digest = PayloadDigest::of('posts.publish', $command->toDigestPayload());

        $envelope = new CommandEnvelope(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
        );

        $state = new class($command->asDraft ? ContentState::Draft : ContentState::Published)
        {
            public ?AudienceSpec $audience = null;

            public function __construct(
                public ContentState $state,
                public ?string $reason = null,
            ) {}
        };

        return $this->runtime->envelope()->run(
            envelope: $envelope,
            surface: $command->surface,
            action: 'sockit.posts.publish',
            preTransactionChecks: function (ActorContext $verifiedActor) use ($command, $surfaceDef, $state): void {
                $this->runtime->authorizer()->decide(new ActionRequest(
                    actor: $verifiedActor,
                    action: 'sockit.posts.publish',
                    surface: $command->surface,
                ))->requireAllowed();

                $state->audience = $command->audience ?? $this->defaultAudience($verifiedActor, $command->surface, $surfaceDef);
                $this->assertAudienceAllowedToPublish($verifiedActor, $command->surface, $state->audience, $surfaceDef);

                [$state->state, $state->reason] = $this->runSafetyChecks(
                    actor: $verifiedActor,
                    surface: $command->surface,
                    body: $command->body,
                    bodyFormat: $command->bodyFormat,
                    defaultState: $command->asDraft ? ContentState::Draft : ContentState::Published,
                );
            },
            mutation: function (ActorContext $verifiedActor, Connection $connection) use ($command, $namespace, $state): array {
                $authorRef = $verifiedActor->actor ?? throw OperationException::audienceDenied('actor.anonymous');
                $surfaceRow = $this->findSurfaceRow($connection, $command->surface);
                $audience = $state->audience ?? throw OperationException::audienceDenied('audience.unknown');
                $now = $this->nowIso();
                $postId = Ids::uuid7();
                $encodedAudience = json_encode($audience->toWire(), JSON_THROW_ON_ERROR);
                $publishedAt = $state->state === ContentState::Published ? $now : null;

                $connection->table($this->tables->table('posts', $command->surface->panel))->insert([
                    'id' => $postId,
                    'panel_id' => $command->surface->panel->value,
                    'surface_instance_id' => SockitRows::str($surfaceRow, 'id'),
                    'surface_incarnation' => $command->surface->incarnation->value,
                    'author_type' => $authorRef->type,
                    'author_id' => $authorRef->id,
                    'state' => $state->state->value,
                    'body_format' => $command->bodyFormat,
                    'current_body' => $command->body,
                    'revision' => 1,
                    'privacy_revision' => 1,
                    'audience_wire' => $encodedAudience,
                    'published_at' => $publishedAt,
                    'hidden_at' => null,
                    'deleted_at' => null,
                    'erased_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $connection->table($this->tables->table('post_revisions', $command->surface->panel))->insert([
                    'post_id' => $postId,
                    'revision' => 1,
                    'state' => $state->state->value,
                    'body_format' => $command->bodyFormat,
                    'body' => $command->body,
                    'audience_wire' => $encodedAudience,
                    'actor_type' => $authorRef->type,
                    'actor_id' => $authorRef->id,
                    'reason_code' => $state->reason,
                    'recorded_at' => $now,
                ]);

                $receiptId = $this->findReceiptId($connection, $command->surface->panel, $namespace->toWire(), $command->key->value);

                if ($state->state === ContentState::Held) {
                    return [
                        'result' => OperationResult::rejected(
                            SockitErrorCode::ContentHeld,
                            ['post_id' => $postId, 'reason' => $state->reason ?? 'safety.hold'],
                            $receiptId,
                        ),
                        'event' => null,
                    ];
                }

                $resource = new ResourceRef($command->surface->panel, ResourceKind::Post, $postId);

                return [
                    'result' => OperationResult::confirmed($receiptId, $resource, new Revision(1)),
                    'event' => $state->state === ContentState::Published ? [
                        'aggregate_type' => 'post',
                        'aggregate_id' => $postId,
                        'aggregate_revision' => 1,
                        'event_type' => 'sockit.posts.published',
                        'payload' => [
                            'panel' => $command->surface->panel->value,
                            'post_id' => $postId,
                            'revision' => 1,
                            'privacy_revision' => 1,
                        ],
                    ] : null,
                ];
            },
            replayChecks: function (ActorContext $verifiedActor, Connection $connection, OperationResult $replay) use ($command): void {
                if (! $replay->resource instanceof ResourceRef) {
                    return;
                }

                $postsTable = $this->tables->table('posts', $command->surface->panel);
                $row = $this->lockPostRow($connection, $postsTable, $command->surface->panel->value, $replay->resource->id);
                $currentState = ContentState::from(SockitRows::str($row, 'state'));

                if ($currentState === ContentState::Erased) {
                    throw OperationException::contentErased($replay->resource->id);
                }

                if ($currentState === ContentState::Deleted) {
                    throw OperationException::contentDeleted($replay->resource->id);
                }

                if (! $command->asDraft && $currentState === ContentState::Held) {
                    throw OperationException::contentHeld('content.held');
                }

                if (! $command->asDraft && $currentState === ContentState::Hidden) {
                    throw OperationException::contentHidden('content.hidden');
                }
            },
        );
    }

    #[Override]
    public function edit(EditPostCommand $command): OperationResult
    {
        [$options, $surfaceDef] = $this->assertPostsEnabled($command->surface);

        if (strlen($command->body) > $options->maxBodyBytes) {
            throw InvalidPayloadException::invalid(
                "Post body exceeds max_body_bytes [{$options->maxBodyBytes}].",
                ['max_body_bytes' => $options->maxBodyBytes],
                '/body',
            );
        }

        if ($command->bodyFormat !== null && ! $options->allowsFormat($command->bodyFormat)) {
            throw InvalidPayloadException::invalid(
                "Body format [{$command->bodyFormat}] is not enabled on panel [{$command->surface->panel->value}].",
                ['body_format' => $command->bodyFormat],
                '/body_format',
            );
        }

        $actor = $this->runtime->actors()->current();
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'posts.edit');
        $digest = PayloadDigest::of('posts.edit', $command->toDigestPayload());

        $envelope = new CommandEnvelope(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
            expectedRevision: $command->expectedRevision,
        );

        $precheck = new class
        {
            public ?ContentState $state = null;

            public ?string $reason = null;

            public string $effectiveBodyFormat = 'plain';

            public string $effectiveAudienceCanonical = '';
        };

        return $this->runtime->envelope()->run(
            envelope: $envelope,
            surface: $command->surface,
            action: 'sockit.posts.edit',
            preTransactionChecks: function (ActorContext $verifiedActor) use ($command, $surfaceDef, $precheck): void {
                $this->runtime->authorizer()->decide(new ActionRequest(
                    actor: $verifiedActor,
                    action: 'sockit.posts.edit',
                    surface: $command->surface,
                    resource: new ResourceRef($command->surface->panel, ResourceKind::Post, $command->postId),
                ))->requireAllowed();

                $conn = $this->tables->connection($command->surface->panel);
                $existing = $conn->table($this->tables->table('posts', $command->surface->panel))
                    ->where('panel_id', $command->surface->panel->value)
                    ->where('id', $command->postId)
                    ->first();

                if (! is_object($existing)) {
                    throw OperationException::contentHidden('post.not_found');
                }

                $existingState = ContentState::from(SockitRows::str($existing, 'state'));
                if ($existingState === ContentState::Deleted) {
                    throw OperationException::contentDeleted($command->postId);
                }
                if ($existingState === ContentState::Erased) {
                    throw OperationException::contentErased($command->postId);
                }

                $existingAudienceWire = SockitRows::jsonArray($existing, 'audience_wire');
                $effectiveAudience = $command->audience instanceof AudienceSpec
                    ? $command->audience
                    : AudienceSpec::fromWire($existingAudienceWire);
                $precheck->effectiveAudienceCanonical = CanonicalJson::encode($effectiveAudience->toWire());

                // Always enforce current surface admission, containment, privacy floor, and audience on edit.
                $this->assertAudienceAllowedToPublish($verifiedActor, $command->surface, $effectiveAudience, $surfaceDef);

                $precheck->effectiveBodyFormat = $command->bodyFormat ?? SockitRows::str($existing, 'body_format');

                [$checkedState, $checkedReason] = $this->runSafetyChecks(
                    actor: $verifiedActor,
                    surface: $command->surface,
                    body: $command->body,
                    bodyFormat: $precheck->effectiveBodyFormat,
                    defaultState: ContentState::Published,
                );
                if ($checkedState === ContentState::Held) {
                    $precheck->state = ContentState::Held;
                    $precheck->reason = $checkedReason;
                }
            },
            mutation: function (ActorContext $verifiedActor, Connection $connection) use ($command, $namespace, $precheck): array {
                $actorRef = $verifiedActor->actor ?? throw OperationException::audienceDenied('actor.anonymous');
                $postsTable = $this->tables->table('posts', $command->surface->panel);
                $surfaceRow = $this->findSurfaceRow($connection, $command->surface);

                $row = $this->lockPostRow($connection, $postsTable, $command->surface->panel->value, $command->postId);

                if (SockitRows::str($row, 'surface_instance_id') !== SockitRows::str($surfaceRow, 'id')) {
                    throw PanelException::bindingMismatch($command->surface->panel->value, "Post [{$command->postId}] does not belong to target surface.");
                }

                $currentState = ContentState::from(SockitRows::str($row, 'state'));
                if ($currentState === ContentState::Deleted) {
                    throw OperationException::contentDeleted($command->postId);
                }
                if ($currentState === ContentState::Erased) {
                    throw OperationException::contentErased($command->postId);
                }

                $actualRevision = SockitRows::int($row, 'revision');
                if ($actualRevision !== $command->expectedRevision->value) {
                    throw OperationException::revisionConflict($command->expectedRevision->value, $actualRevision);
                }

                $nextRevision = $actualRevision + 1;
                $currentAudienceWire = SockitRows::jsonArray($row, 'audience_wire');
                $audienceWire = $command->audience instanceof AudienceSpec ? $command->audience->toWire() : $currentAudienceWire;
                $bodyFormat = $command->bodyFormat ?? SockitRows::str($row, 'body_format');

                if (
                    (! $command->audience instanceof AudienceSpec && CanonicalJson::encode($currentAudienceWire) !== $precheck->effectiveAudienceCanonical)
                    || ($command->bodyFormat === null && $bodyFormat !== $precheck->effectiveBodyFormat)
                ) {
                    throw OperationException::revisionConflict($command->expectedRevision->value, $actualRevision);
                }

                $audienceChanged = $command->audience instanceof AudienceSpec
                    && CanonicalJson::encode($audienceWire) !== CanonicalJson::encode($currentAudienceWire);
                $nextPrivacyRevision = SockitRows::int($row, 'privacy_revision', 1) + ($audienceChanged ? 1 : 0);
                $newState = $precheck->state ?? $currentState;
                $encodedAudience = json_encode($audienceWire, JSON_THROW_ON_ERROR);
                $now = $this->nowIso();

                $affected = $connection->table($postsTable)
                    ->where('id', $command->postId)
                    ->where('panel_id', $command->surface->panel->value)
                    ->where('revision', $command->expectedRevision->value)
                    ->update([
                        'state' => $newState->value,
                        'body_format' => $bodyFormat,
                        'current_body' => $command->body,
                        'revision' => $nextRevision,
                        'privacy_revision' => $nextPrivacyRevision,
                        'audience_wire' => $encodedAudience,
                        'updated_at' => $now,
                    ]);

                if ($affected === 0) {
                    throw OperationException::revisionConflict($command->expectedRevision->value, $actualRevision);
                }

                $connection->table($this->tables->table('post_revisions', $command->surface->panel))->insert([
                    'post_id' => $command->postId,
                    'revision' => $nextRevision,
                    'state' => $newState->value,
                    'body_format' => $bodyFormat,
                    'body' => $command->body,
                    'audience_wire' => $encodedAudience,
                    'actor_type' => $actorRef->type,
                    'actor_id' => $actorRef->id,
                    'reason_code' => $precheck->reason,
                    'recorded_at' => $now,
                ]);

                $receiptId = $this->findReceiptId($connection, $command->surface->panel, $namespace->toWire(), $command->key->value);

                if ($newState === ContentState::Held) {
                    $event = $currentState === ContentState::Published ? [
                        'aggregate_type' => 'post',
                        'aggregate_id' => $command->postId,
                        'aggregate_revision' => $nextRevision,
                        'event_type' => 'sockit.posts.state_changed',
                        'payload' => [
                            'panel' => $command->surface->panel->value,
                            'post_id' => $command->postId,
                            'previous_state' => $currentState->value,
                            'new_state' => ContentState::Held->value,
                            'revision' => $nextRevision,
                            'reason_code' => $precheck->reason ?? 'safety.hold',
                        ],
                    ] : null;

                    return [
                        'result' => OperationResult::rejected(
                            SockitErrorCode::ContentHeld,
                            ['post_id' => $command->postId, 'reason' => $precheck->reason ?? 'safety.hold'],
                            $receiptId,
                        ),
                        'event' => $event,
                    ];
                }

                return [
                    'result' => OperationResult::confirmed(
                        $receiptId,
                        new ResourceRef($command->surface->panel, ResourceKind::Post, $command->postId),
                        new Revision($nextRevision),
                    ),
                    'event' => [
                        'aggregate_type' => 'post',
                        'aggregate_id' => $command->postId,
                        'aggregate_revision' => $nextRevision,
                        'event_type' => 'sockit.posts.edited',
                        'payload' => [
                            'panel' => $command->surface->panel->value,
                            'post_id' => $command->postId,
                            'revision' => $nextRevision,
                            'privacy_revision' => $nextPrivacyRevision,
                        ],
                    ],
                ];
            },
            replayChecks: function (ActorContext $verifiedActor, Connection $connection, OperationResult $replay) use ($command): void {
                $postsTable = $this->tables->table('posts', $command->surface->panel);
                $surfaceRow = $this->findSurfaceRow($connection, $command->surface);
                $row = $this->lockPostRow($connection, $postsTable, $command->surface->panel->value, $command->postId);

                if (SockitRows::str($row, 'surface_instance_id') !== SockitRows::str($surfaceRow, 'id')) {
                    throw PanelException::bindingMismatch($command->surface->panel->value, "Post [{$command->postId}] does not belong to target surface.");
                }

                $currentState = ContentState::from(SockitRows::str($row, 'state'));
                if ($currentState === ContentState::Erased) {
                    throw OperationException::contentErased($command->postId);
                }
                if ($currentState === ContentState::Deleted) {
                    throw OperationException::contentDeleted($command->postId);
                }
                if ($replay->isSuccess() && $currentState === ContentState::Held) {
                    throw OperationException::contentHeld('content.held');
                }
                if ($replay->isSuccess() && $currentState === ContentState::Hidden) {
                    throw OperationException::contentHidden('content.hidden');
                }
            },
        );
    }

    #[Override]
    public function transition(TransitionPostStateCommand $command): OperationResult
    {
        [, $surfaceDef] = $this->assertPostsEnabled($command->surface);

        $actor = $this->runtime->actors()->current();
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'posts.transition');
        $digest = PayloadDigest::of('posts.transition', $command->toDigestPayload());

        $envelope = new CommandEnvelope(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
            expectedRevision: $command->expectedRevision,
        );

        $action = match ($command->targetState) {
            ContentState::Published => 'sockit.posts.publish',
            ContentState::Hidden => 'sockit.posts.hide',
            ContentState::Deleted => 'sockit.posts.delete',
            ContentState::Erased => 'sockit.posts.erase',
            default => 'sockit.posts.edit',
        };

        $safety = new class
        {
            public ?ContentState $override = null;

            public ?string $reason = null;
        };

        return $this->runtime->envelope()->run(
            envelope: $envelope,
            surface: $command->surface,
            action: $action,
            preTransactionChecks: function (ActorContext $verifiedActor) use ($command, $action, $surfaceDef, $safety): void {
                $this->runtime->authorizer()->decide(new ActionRequest(
                    actor: $verifiedActor,
                    action: $action,
                    surface: $command->surface,
                    resource: new ResourceRef($command->surface->panel, ResourceKind::Post, $command->postId),
                ))->requireAllowed();

                $admission = $this->runtime->audienceGate()->admitForWrite($verifiedActor, $command->surface);
                if (! $admission->canWrite()) {
                    throw OperationException::audienceDenied($admission->reason);
                }

                if ($command->targetState === ContentState::Published) {
                    $existing = $this->tables->connection($command->surface->panel)
                        ->table($this->tables->table('posts', $command->surface->panel))
                        ->where('panel_id', $command->surface->panel->value)
                        ->where('id', $command->postId)
                        ->first();

                    if (is_object($existing)) {
                        $this->assertAudienceAllowedToPublish(
                            $verifiedActor,
                            $command->surface,
                            AudienceSpec::fromWire(SockitRows::jsonArray($existing, 'audience_wire')),
                            $surfaceDef,
                        );

                        $existingBody = SockitRows::optStr($existing, 'current_body');
                        if ($existingBody !== null) {
                            [$checkedState, $checkedReason] = $this->runSafetyChecks(
                                actor: $verifiedActor,
                                surface: $command->surface,
                                body: $existingBody,
                                bodyFormat: SockitRows::str($existing, 'body_format', 'plain'),
                                defaultState: ContentState::Published,
                            );
                            if ($checkedState === ContentState::Held) {
                                $safety->override = ContentState::Held;
                                $safety->reason = $checkedReason;
                            }
                        }
                    }
                }
            },
            mutation: function (ActorContext $verifiedActor, Connection $connection) use ($command, $namespace, $safety): array {
                $actorRef = $verifiedActor->actor ?? throw OperationException::audienceDenied('actor.anonymous');
                $postsTable = $this->tables->table('posts', $command->surface->panel);
                $surfaceRow = $this->findSurfaceRow($connection, $command->surface);

                $row = $this->lockPostRow($connection, $postsTable, $command->surface->panel->value, $command->postId);

                if (SockitRows::str($row, 'surface_instance_id') !== SockitRows::str($surfaceRow, 'id')) {
                    throw PanelException::bindingMismatch($command->surface->panel->value, "Post [{$command->postId}] does not belong to target surface.");
                }

                $currentState = ContentState::from(SockitRows::str($row, 'state'));
                if ($currentState === ContentState::Erased) {
                    throw OperationException::contentErased($command->postId);
                }

                if ($currentState === ContentState::Deleted && ! in_array($command->targetState, [ContentState::Deleted, ContentState::Erased], true)) {
                    throw OperationException::contentDeleted($command->postId);
                }

                if (! $currentState->canTransitionTo($command->targetState)) {
                    throw InvalidPayloadException::invalid(
                        "Cannot transition post from [{$currentState->value}] to [{$command->targetState->value}].",
                        ['from' => $currentState->value, 'to' => $command->targetState->value],
                    );
                }

                $actualRevision = SockitRows::int($row, 'revision');
                if ($actualRevision !== $command->expectedRevision->value) {
                    throw OperationException::revisionConflict($command->expectedRevision->value, $actualRevision);
                }

                $nextRevision = $actualRevision + 1;
                $now = $this->nowIso();
                $effectiveTarget = $safety->override ?? $command->targetState;

                $update = [
                    'state' => $effectiveTarget->value,
                    'revision' => $nextRevision,
                    'updated_at' => $now,
                ];

                if ($effectiveTarget === ContentState::Published) {
                    $update['published_at'] = SockitRows::optStr($row, 'published_at') ?? $now;
                    $update['hidden_at'] = null;
                } elseif ($effectiveTarget === ContentState::Hidden) {
                    $update['hidden_at'] = $now;
                } elseif ($effectiveTarget === ContentState::Deleted) {
                    $update['deleted_at'] = $now;
                } elseif ($effectiveTarget === ContentState::Erased) {
                    $update['current_body'] = null;
                    $update['erased_at'] = $now;
                }

                $affected = $connection->table($postsTable)
                    ->where('id', $command->postId)
                    ->where('panel_id', $command->surface->panel->value)
                    ->where('revision', $command->expectedRevision->value)
                    ->update($update);

                if ($affected === 0) {
                    throw OperationException::revisionConflict($command->expectedRevision->value, $actualRevision);
                }

                $revisionsTable = $this->tables->table('post_revisions', $command->surface->panel);
                $revisionBody = $effectiveTarget === ContentState::Erased ? null : SockitRows::optStr($row, 'current_body');

                if ($effectiveTarget === ContentState::Erased) {
                    $connection->table($revisionsTable)
                        ->where('post_id', $command->postId)
                        ->update(['body' => null]);
                }

                $connection->table($revisionsTable)->insert([
                    'post_id' => $command->postId,
                    'revision' => $nextRevision,
                    'state' => $effectiveTarget->value,
                    'body_format' => SockitRows::str($row, 'body_format'),
                    'body' => $revisionBody,
                    'audience_wire' => SockitRows::str($row, 'audience_wire'),
                    'actor_type' => $actorRef->type,
                    'actor_id' => $actorRef->id,
                    'reason_code' => $safety->reason ?? $command->reasonCode,
                    'recorded_at' => $now,
                ]);

                if (in_array($effectiveTarget, [ContentState::Deleted, ContentState::Erased], true)) {
                    $this->upsertTombstone(
                        connection: $connection,
                        panelId: $command->surface->panel->value,
                        postId: $command->postId,
                        revision: $nextRevision,
                        incarnation: SockitRows::int($row, 'surface_incarnation', 1),
                        deletedAt: SockitRows::optStr($row, 'deleted_at') ?? $now,
                        erasedAt: $effectiveTarget === ContentState::Erased ? $now : null,
                    );
                }

                $receiptId = $this->findReceiptId($connection, $command->surface->panel, $namespace->toWire(), $command->key->value);

                if ($effectiveTarget === ContentState::Held) {
                    $heldEvent = $currentState === ContentState::Published ? [
                        'aggregate_type' => 'post',
                        'aggregate_id' => $command->postId,
                        'aggregate_revision' => $nextRevision,
                        'event_type' => 'sockit.posts.state_changed',
                        'payload' => [
                            'panel' => $command->surface->panel->value,
                            'post_id' => $command->postId,
                            'previous_state' => $currentState->value,
                            'new_state' => ContentState::Held->value,
                            'revision' => $nextRevision,
                            'reason_code' => $safety->reason ?? 'safety.hold',
                        ],
                    ] : null;

                    return [
                        'result' => OperationResult::rejected(
                            SockitErrorCode::ContentHeld,
                            ['post_id' => $command->postId, 'reason' => $safety->reason ?? 'safety.hold'],
                            $receiptId,
                        ),
                        'event' => $heldEvent,
                    ];
                }

                return [
                    'result' => OperationResult::confirmed(
                        $receiptId,
                        new ResourceRef($command->surface->panel, ResourceKind::Post, $command->postId),
                        new Revision($nextRevision),
                    ),
                    'event' => [
                        'aggregate_type' => 'post',
                        'aggregate_id' => $command->postId,
                        'aggregate_revision' => $nextRevision,
                        'event_type' => 'sockit.posts.state_changed',
                        'payload' => [
                            'panel' => $command->surface->panel->value,
                            'post_id' => $command->postId,
                            'previous_state' => $currentState->value,
                            'new_state' => $effectiveTarget->value,
                            'revision' => $nextRevision,
                            'reason_code' => $command->reasonCode,
                        ],
                    ],
                ];
            },
            replayChecks: function (ActorContext $verifiedActor, Connection $connection, OperationResult $replay) use ($command): void {
                $postsTable = $this->tables->table('posts', $command->surface->panel);
                $surfaceRow = $this->findSurfaceRow($connection, $command->surface);
                $row = $this->lockPostRow($connection, $postsTable, $command->surface->panel->value, $command->postId);

                if (SockitRows::str($row, 'surface_instance_id') !== SockitRows::str($surfaceRow, 'id')) {
                    throw PanelException::bindingMismatch($command->surface->panel->value, "Post [{$command->postId}] does not belong to target surface.");
                }

                $currentState = ContentState::from(SockitRows::str($row, 'state'));

                if ($currentState === ContentState::Erased && $command->targetState !== ContentState::Erased) {
                    throw OperationException::contentErased($command->postId);
                }

                if ($currentState === ContentState::Deleted && ! in_array($command->targetState, [ContentState::Deleted, ContentState::Erased], true)) {
                    throw OperationException::contentDeleted($command->postId);
                }

                if ($command->targetState === ContentState::Published && $replay->isSuccess() && $currentState !== ContentState::Published) {
                    if ($currentState === ContentState::Held) {
                        throw OperationException::contentHeld('content.held');
                    }

                    throw OperationException::contentHidden('content.'.$currentState->value);
                }
            },
        );
    }

    #[Override]
    public function get(SurfaceRef $surface, string $postId): PostView
    {
        [, $surfaceDef] = $this->assertPostsEnabled($surface);
        $actor = $this->runtime->actors()->current();

        $connection = $this->tables->connection($surface->panel);
        $row = $connection->table($this->tables->table('posts', $surface->panel))
            ->where('panel_id', $surface->panel->value)
            ->where('id', $postId)
            ->first();

        if (! is_object($row)) {
            throw OperationException::contentHidden('post.not_found');
        }

        $surfaceRow = $connection->table($this->tables->table('surface_instances', $surface->panel))
            ->where('panel_id', $surface->panel->value)
            ->where('kind', $surface->kind->value)
            ->where('host_entity_type', $surface->host->type)
            ->where('host_entity_value', $surface->host->value)
            ->where('incarnation', $surface->incarnation->value)
            ->first();

        if (! is_object($surfaceRow) || SockitRows::str($surfaceRow, 'id') !== SockitRows::str($row, 'surface_instance_id')) {
            throw OperationException::contentHidden('post.wrong_surface');
        }

        $state = ContentState::from(SockitRows::str($row, 'state'));

        if ($state === ContentState::Erased) {
            throw OperationException::contentErased($postId);
        }

        if ($state === ContentState::Deleted) {
            throw OperationException::contentDeleted($postId);
        }

        $author = new ActorRef(SockitRows::str($row, 'author_type'), SockitRows::str($row, 'author_id'));

        if (! $state->isPubliclyVisible()) {
            $isAuthor = $actor->actor instanceof ActorRef && $actor->actor->equals($author);

            if ($state === ContentState::Draft) {
                if (! $isAuthor) {
                    throw OperationException::contentHidden('content.draft');
                }
            } else {
                $modDecision = $this->runtime->authorizer()->decide(new ActionRequest(
                    actor: $actor,
                    action: 'sockit.posts.view_hidden',
                    surface: $surface,
                    resource: new ResourceRef($surface->panel, ResourceKind::Post, $postId),
                ));

                if (! $isAuthor && ! $modDecision->isAllowed()) {
                    throw OperationException::contentHidden('content.'.$state->value);
                }
            }
        }

        $audience = AudienceSpec::fromWire(SockitRows::jsonArray($row, 'audience_wire'));
        $archived = SockitRows::str($surfaceRow, 'lifecycle_state') === SurfaceLifecycleState::Archived->value;

        $verdict = $this->runtime->audienceGate()->read($actor, new AudienceSubject(
            key: $postId,
            spec: $audience,
            author: $author,
            surface: $surface,
            installation: $actor->installation,
            surfaceArchived: $archived,
            archiveReaders: $surfaceDef->archiveReaders,
        ));

        if (! $verdict->canRead()) {
            throw OperationException::audienceDenied($verdict->reason);
        }

        return new PostView(
            id: SockitRows::str($row, 'id'),
            panel: $surface->panel,
            surface: $surface,
            author: $author,
            state: $state,
            bodyFormat: SockitRows::str($row, 'body_format'),
            body: SockitRows::optStr($row, 'current_body'),
            revision: new Revision(SockitRows::int($row, 'revision', 1)),
            privacyRevision: new Revision(SockitRows::int($row, 'privacy_revision', 1)),
            audience: $audience,
            publishedAt: SockitRows::optDate($row, 'published_at'),
            createdAt: SockitRows::date($row, 'created_at'),
            updatedAt: SockitRows::date($row, 'updated_at'),
        );
    }

    /** @return array{0: PostsOptions, 1: SurfaceDefinition} */
    private function assertPostsEnabled(SurfaceRef $surface): array
    {
        $panelDef = $this->panels->get($surface->panel);
        $surfaceDef = $this->surfaces->get($surface->panel, $surface->kind);

        if (! $panelDef->hasModule('posts')) {
            throw PanelException::bindingMismatch($surface->panel->value, "Panel [{$surface->panel->value}] does not enable module [posts].");
        }

        if ($surfaceDef->features !== [] && ! $surfaceDef->supportsFeature('posts')) {
            throw PanelException::bindingMismatch($surface->panel->value, "Surface [{$surfaceDef->key()}] does not enable feature [posts].");
        }

        return [PostsOptions::fromSettings($panelDef->settingsFor('posts')), $surfaceDef];
    }

    private function defaultAudience(ActorContext $actor, SurfaceRef $surface, SurfaceDefinition $surfaceDef): AudienceSpec
    {
        return match ($surfaceDef->effectiveFloor->defaultFor($actor)) {
            Scope::Platform => AudienceSpec::platform(),
            Scope::Public => AudienceSpec::public(),
            Scope::Contour => $surfaceDef->contourLevel !== null
                ? AudienceSpec::contour($surfaceDef->contourLevel, $surface->host)
                : ($actor->actor instanceof ActorRef ? AudienceSpec::direct($actor->actor) : AudienceSpec::platform()),
            Scope::Group => $surfaceDef->hostType === 'group'
                ? AudienceSpec::group(new GroupRef($surface->host->value))
                : ($actor->actor instanceof ActorRef ? AudienceSpec::direct($actor->actor) : AudienceSpec::platform()),
            Scope::Direct => $actor->actor instanceof ActorRef
                ? AudienceSpec::direct($actor->actor)
                : throw OperationException::audienceDenied('actor.anonymous'),
        };
    }

    private function assertAudienceAllowedToPublish(
        ActorContext $actor,
        SurfaceRef $surface,
        AudienceSpec $audience,
        SurfaceDefinition $surfaceDef,
    ): void {
        $verdict = $this->runtime->audienceGate()->publish(
            author: $actor,
            subject: new AudienceSubject(
                key: 'publish:'.$surface->panel->value,
                spec: $audience,
                author: $actor->actor,
                surface: $surface,
                installation: $actor->installation,
                surfaceArchived: false,
                archiveReaders: $surfaceDef->archiveReaders,
            ),
            floor: $surfaceDef->effectiveFloor,
        );

        if ($verdict->canWrite()) {
            return;
        }

        if (in_array($verdict->reason, [
            'audience.denied.scope_not_allowed',
            'audience.denied.scope_too_wide',
            'audience.denied.contour_level_unknown',
            'audience.denied.exceeds_floor',
        ], true)) {
            throw OperationException::audienceNotContained($verdict->reason);
        }

        throw OperationException::audienceDenied($verdict->reason);
    }

    /** @return array{0: ContentState, 1: ?string} */
    private function runSafetyChecks(
        ActorContext $actor,
        SurfaceRef $surface,
        string $body,
        string $bodyFormat,
        ContentState $defaultState,
    ): array {
        $state = $defaultState;
        $reason = null;

        foreach ($this->runtime->safetyCheckers() as $checker) {
            $verdict = $checker->assess($actor, $surface, 'post', $body, ['format' => $bodyFormat]);

            if ($verdict->decision === 'reject') {
                throw OperationException::contentHeld($verdict->reason);
            }

            if ($verdict->decision === 'hold') {
                $state = ContentState::Held;
                $reason = $verdict->reason;
            }
        }

        return [$state, $reason];
    }

    private function lockPostRow(Connection $connection, string $postsTable, string $panelId, string $postId): object
    {
        $query = $connection->table($postsTable)
            ->where('panel_id', $panelId)
            ->where('id', $postId);

        if ($connection->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if (! is_object($row)) {
            throw OperationException::contentHidden('post.not_found');
        }

        return $row;
    }

    private function findSurfaceRow(Connection $connection, SurfaceRef $surface): object
    {
        $row = $connection->table($this->tables->table('surface_instances', $surface->panel))
            ->where('panel_id', $surface->panel->value)
            ->where('kind', $surface->kind->value)
            ->where('host_entity_type', $surface->host->type)
            ->where('host_entity_value', $surface->host->value)
            ->where('incarnation', $surface->incarnation->value)
            ->first();

        if (! is_object($row)) {
            throw OperationException::contentHidden('surface.missing');
        }

        return $row;
    }

    private function upsertTombstone(
        Connection $connection,
        string $panelId,
        string $postId,
        int $revision,
        int $incarnation,
        string $deletedAt,
        ?string $erasedAt,
    ): void {
        $table = $this->tables->table('resource_tombstones', new PanelId($panelId));
        $existing = $connection->table($table)
            ->where('panel_id', $panelId)
            ->where('resource_kind', ResourceKind::Post->value)
            ->where('resource_id', $postId)
            ->first();

        if (is_object($existing)) {
            $connection->table($table)
                ->where('id', SockitRows::str($existing, 'id'))
                ->update([
                    'last_revision' => $revision,
                    'erased_at' => $erasedAt ?? SockitRows::optStr($existing, 'erased_at'),
                ]);

            return;
        }

        $connection->table($table)->insert([
            'id' => Ids::uuid7(),
            'panel_id' => $panelId,
            'resource_kind' => ResourceKind::Post->value,
            'resource_id' => $postId,
            'last_revision' => $revision,
            'incarnation' => $incarnation,
            'deleted_at' => $deletedAt,
            'erased_at' => $erasedAt,
        ]);
    }

    private function findReceiptId(Connection $connection, PanelId $panel, string $namespace, string $commandKey): string
    {
        $row = $connection->table($this->tables->table('command_receipts', $panel))
            ->where('panel_id', $panel->value)
            ->where('command_namespace', $namespace)
            ->where('command_key', $commandKey)
            ->first();

        return is_object($row) ? SockitRows::str($row, 'id') : Ids::uuid7();
    }

    private function nowIso(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
