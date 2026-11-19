<?php

declare(strict_types=1);

namespace SocKit\Posts\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Contracts\OwnerMutation;
use SocKit\Operations\Data\MutationEffect;
use SocKit\Operations\Data\MutationEvent;
use SocKit\Operations\Data\MutationRequest;
use SocKit\Operations\Data\MutationSnapshot;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Posts\Data\PostTransition;
use SocKit\Posts\Enums\PublicationState;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\Revision;
use SocKit\Support\Ids;
use SocKit\Surfaces\Enums\TargetLifecycle;

/** Lifecycle is an overlay on publication, never an implicit publication or an erasure workflow. */
final class PostLifecycleMutation implements OwnerMutation
{
    /** @var array<string, mixed>|null */
    private ?array $prepared = null;

    /** @var array<string, mixed>|null */
    private ?array $locked = null;

    public function __construct(private readonly Application $app, private readonly PostTransition $transition) {}

    public function commandType(): string
    {
        return 'posts.'.$this->transition->action;
    }

    public function payload(): array
    {
        return ['resource' => $this->transition->resource->toWire(), 'reason' => $this->transition->reason,
            'selected_audience' => $this->transition->audience?->toWire()];
    }

    public function request(OperationHandle $handle): MutationRequest
    {
        $store = new PostStore($this->app);
        $store->outside($handle);
        if (! $this->transition->surface->equals($handle->surface)) {
            throw new PostException(ProblemCode::PanelBindingMismatch);
        }
        $actor = $handle->actor->actor ?? throw new PostException(ProblemCode::AuthenticationRequired);
        $row = $store->retained($handle, $this->transition->resource);
        if (in_array($this->transition->action, ['publish', 'delete', 'audience'], true) && $store->text($row, 'author_ref') !== $actor->toWire()) {
            throw new PostException(ProblemCode::AuthorizationDenied);
        }
        $store->audience($handle, $row);
        $this->prepared = $row;
        $publication = PublicationState::from($store->text($row, 'publication_state'));
        $publishes = $this->transition->action === 'publish' || ($this->transition->action === 'restore' && $publication === PublicationState::Published);
        if ($publishes && new PostPolicies($this->app)->assess(handle: $handle, post: $store->content($handle, $this->transition->resource, $row, true)) !== PublicationState::Published) {
            throw new PostException(ProblemCode::ContentHeld);
        }
        $proposed = $this->transition->audience ?? ($publishes ? AudienceSpec::fromWire($store->object($store->text($row, 'audience'))) : null);

        return new MutationRequest(action: $this->commandType(), subject: new AudienceSubject(key: 'posts.owner', spec: AudienceSpec::direct($actor), author: null,
            surface: $handle->surface, installation: $handle->actor->installation), intent: $proposed === null ? 'write' : 'publish',
            safety: AuthorizationDecision::allow('posts.explicit_lifecycle'), generation: 'posts.lifecycle.v1', target: $store->host($handle), proposedAudience: $proposed);
    }

    public function lock(OperationHandle $handle): MutationSnapshot
    {
        $this->requireTransaction($handle);
        $store = new PostStore($this->app);
        $row = $store->retained($handle, $this->transition->resource, lock: true);
        if ($this->prepared === null || $store->integer($row, 'revision') !== $store->integer($this->prepared, 'revision')) {
            throw new PostException(ProblemCode::RevisionConflict);
        }
        $this->locked = $row;
        $visible = $store->text($row, 'visibility') === 'visible';
        $publication = PublicationState::from($store->text($row, 'publication_state'));
        $allowed = match ($this->transition->action) {
            'publish' => $visible && $publication !== PublicationState::Published,
            'hold' => $visible && $publication !== PublicationState::Held,
            'reject' => $visible && $publication !== PublicationState::Rejected,
            'hide', 'audience' => $visible,
            'restore' => ! $visible && $store->text($row, 'visibility') === 'hidden',
            'delete' => true,
            default => false,
        };
        if (! $allowed) {
            throw new PostException(ProblemCode::RevisionConflict);
        }

        // Live means the retained owner record can accept this explicit transition. It is not a read grant.
        return new MutationSnapshot(resource: $this->transition->resource, revision: new Revision($store->integer($row, 'revision')), lifecycle: TargetLifecycle::Live,
            generation: 'posts.lifecycle.v1', safety: AuthorizationDecision::allow('posts.valid_transition'));
    }

    public function persist(OperationHandle $handle, MutationSnapshot $snapshot): MutationEffect
    {
        $this->requireTransaction($handle);
        $store = new PostStore($this->app);
        $row = $this->locked ?? throw new PostException(ProblemCode::PayloadInvalid);
        $revision = ($snapshot->revision ?? throw new PostException(ProblemCode::PayloadInvalid))->next();
        $state = match ($this->transition->action) {
            'publish' => PublicationState::Published->value, 'hold' => PublicationState::Held->value, 'reject' => PublicationState::Rejected->value,
            default => $store->text($row, 'publication_state'),
        };
        $visibility = match ($this->transition->action) {
            'hide' => 'hidden', 'restore' => 'visible', 'delete' => 'deleted', default => $store->text($row, 'visibility'),
        };
        $audience = $this->transition->audience === null ? $store->text($row, 'audience') : CanonicalJson::encode($this->transition->audience->toWire());
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $values = ['publication_state' => $state, 'visibility' => $visibility, 'audience' => $audience, 'revision' => $revision->value,
            'privacy_revision' => $revision->value, 'safety_generation' => $revision->value,
            'published_at' => $row['published_at'] ?? ($state === PublicationState::Published->value ? $now : null), 'updated_at' => $now];
        $affected = $store->table($handle, 'posts')->where('id', $this->transition->resource->id)->where('revision', $snapshot->revision->value)
            ->where('content_state', 'retained')->where('visibility', $store->text($row, 'visibility'))->update($values);
        if ($affected !== 1) {
            throw new PostException(ProblemCode::RevisionConflict);
        }
        // The delete revision is a content-free terminal marker. Retained history/holds await P9 policy.
        $store->table($handle, 'post_revisions')->insert(['id' => Ids::uuid7(), 'post_id' => $this->transition->resource->id, 'revision' => $revision->value,
            'actor_ref' => $handle->actor->actor?->toWire(), 'reason' => $this->transition->reason, 'action' => $this->transition->action,
            'publication_state' => $state, 'visibility' => $visibility, 'audience' => $audience, 'privacy_revision' => $revision->value,
            'body_format' => $store->text($row, 'body_format'), 'body' => $visibility === 'deleted' ? null : $store->text($row, 'body'), 'media_refs' => $visibility === 'deleted' ? '[]' : $store->text($row, 'media_refs'), 'created_at' => $now]);

        return new MutationEffect(affectedRows: $affected, resource: $this->transition->resource, newRevision: $revision,
            events: [new MutationEvent(type: 'posts.'.match ($this->transition->action) {
                'publish' => 'published', 'hold' => 'held', 'reject' => 'rejected', 'hide' => 'hidden', 'restore' => 'restored', 'delete' => 'deleted', default => 'audience_changed',
            }, refs: ['post' => $this->transition->resource])]);
    }

    private function requireTransaction(OperationHandle $handle): void
    {
        if ($this->app->make(ConnectionResolverInterface::class)->connection($handle->panel->storage->connection)->transactionLevel() !== 1) {
            throw new PostException(ProblemCode::ConfigurationInvalid);
        }
    }
}
