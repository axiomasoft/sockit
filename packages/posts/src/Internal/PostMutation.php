<?php

declare(strict_types=1);

namespace SocKit\Posts\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;
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
use SocKit\Posts\Data\PostCommand;
use SocKit\Posts\Enums\PublicationState;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Ids;
use SocKit\Surfaces\Enums\TargetLifecycle;

/** One operation-local preparation. Safety/media assess outside tx; only owner rows change under locks. */
#[Internal]
final class PostMutation implements OwnerMutation
{
    private ?PublicationState $publication = null;

    private ?AudienceSpec $audience = null;

    /** @var array<string, mixed>|null */
    private ?array $locked = null;

    private ?Revision $preparedRevision = null;

    public function __construct(private readonly Application $app, private readonly PostCommand $post) {}

    public function commandType(): string
    {
        return $this->post->resource === null ? 'posts.create' : 'posts.edit';
    }

    public function payload(): array
    {
        return [...($this->post->format === 'plain' ? [] : ['format' => $this->post->format]), 'selected_audience' => $this->post->audience?->toWire(), 'body' => $this->post->body, 'media' => array_map(fn ($ref): array => $ref->toWire(), $this->post->media),
            'resource' => $this->post->resource?->toWire(), 'publish' => $this->post->publish, 'reason' => $this->post->reason];
    }

    public function request(OperationHandle $handle): MutationRequest
    {
        $store = new PostStore($this->app);
        $store->outside($handle);
        if (! $this->post->surface->equals($handle->surface)) {
            throw new PostException(ProblemCode::PanelBindingMismatch);
        }
        $actor = $handle->actor->actor ?? throw new PostException(ProblemCode::AuthenticationRequired);
        if ($this->post->resource !== null) {
            $row = $store->row($handle, $this->post->resource);
            $this->preparedRevision = new Revision($store->integer($row, 'revision'));
            if ($store->text($row, 'author_ref') !== $actor->toWire()) {
                throw new PostException(ProblemCode::AuthorizationDenied);
            }
            // Current stored audience still constrains the author, including during re-publication.
            $store->audience($handle, $row);
        }
        $this->audience = $this->post->audience ?? AudienceSpec::direct($actor);
        $this->publication = new PostPolicies($this->app)->assess(handle: $handle, post: $this->post);

        // Safety here authorizes recording a known moderation outcome, never publishing held/rejected content.
        return new MutationRequest(action: $this->commandType(), subject: new AudienceSubject(key: 'posts.owner', spec: AudienceSpec::direct($actor), author: null,
            surface: $handle->surface, installation: $handle->actor->installation), intent: $this->post->publish ? 'publish' : 'write',
            safety: AuthorizationDecision::allow('posts.record_known_safety_outcome'), generation: 'posts.v1', target: $store->host($handle),
            proposedAudience: $this->post->publish ? $this->audience : null);
    }

    public function lock(OperationHandle $handle): MutationSnapshot
    {
        $this->requireTransaction($handle);
        $store = new PostStore($this->app);
        $this->locked = $this->post->resource === null ? null : $store->row($handle, $this->post->resource, lock: true);
        if ($this->locked !== null && $store->text($this->locked, 'author_ref') !== $handle->actor->actor?->toWire()) {
            throw new PostException(ProblemCode::AuthorizationDenied);
        }
        if ($this->locked !== null && $this->preparedRevision?->value !== $store->integer($this->locked, 'revision')) {
            throw new PostException(ProblemCode::RevisionConflict);
        }

        return new MutationSnapshot(resource: $this->post->resource, revision: $this->locked === null ? null : new Revision($store->integer($this->locked, 'revision')),
            lifecycle: TargetLifecycle::Live, generation: 'posts.v1', safety: AuthorizationDecision::allow('posts.retained_owner_storage'));
    }

    private function requireTransaction(OperationHandle $handle): void
    {
        if ($this->app->make(ConnectionResolverInterface::class)->connection($handle->panel->storage->connection)->transactionLevel() !== 1) {
            throw new PostException(ProblemCode::ConfigurationInvalid);
        }
    }

    public function persist(OperationHandle $handle, MutationSnapshot $snapshot): MutationEffect
    {
        $this->requireTransaction($handle);
        $store = new PostStore($this->app);
        $settings = $handle->panel->moduleSettings['sockit.posts'] ?? [];
        $limit = $settings['max_body_bytes'] ?? 1048576;
        $formats = $settings['allowed_formats'] ?? ['plain', 'markdown'];
        if (! is_int($limit) || strlen($this->post->body) > $limit || ! is_array($formats) || ! in_array($this->post->format, $formats, true)) {
            throw new PostException(ProblemCode::PayloadInvalid);
        }
        $state = $this->publication ?? throw new PostException(ProblemCode::PayloadInvalid);
        $audience = $this->audience ?? throw new PostException(ProblemCode::PayloadInvalid);
        $resource = $snapshot->resource ?? new ResourceRef(panel: $handle->surface->panel, kind: ResourceKind::Post, id: Ids::uuid7());
        $revision = $snapshot->revision?->next() ?? new Revision(1);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        // Conservative invalidation: every immutable content/safety revision is a privacy fence.
        $privacy = $revision->value;
        $media = json_encode(array_map(fn ($ref): array => $ref->toWire(), $this->post->media), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $values = ['body' => $this->post->body, 'body_format' => $this->post->format, 'media_refs' => $media, 'audience' => CanonicalJson::encode($audience->toWire()),
            'revision' => $revision->value, 'privacy_revision' => $privacy, 'publication_state' => $state->value,
            'safety_generation' => $revision->value, 'published_at' => $this->locked['published_at'] ?? ($state === PublicationState::Published ? $now : null), 'updated_at' => $now];
        if ($snapshot->revision === null) {
            $affected = $store->table($handle, 'posts')->insertOrIgnore([...$store->identity($handle), ...$values, 'id' => $resource->id,
                'surface_digest' => hash('sha256', CanonicalJson::encode($handle->surface->toWire())), 'author_ref' => $handle->actor->actor?->toWire(),
                'visibility' => 'visible', 'content_state' => 'retained', 'consent_generation' => 1, 'created_at' => $now]);
        } else {
            $affected = $store->table($handle, 'posts')->where('id', $resource->id)->where('revision', $snapshot->revision->value)->where('visibility', 'visible')->where('content_state', 'retained')->update($values);
        }
        if ($affected !== 1) {
            throw new PostException(ProblemCode::RevisionConflict);
        }
        $store->table($handle, 'post_revisions')->insert(['id' => Ids::uuid7(), 'post_id' => $resource->id, 'revision' => $revision->value,
            'actor_ref' => $handle->actor->actor?->toWire(), 'reason' => $this->post->reason, 'action' => $snapshot->resource === null ? 'create' : 'edit',
            'publication_state' => $state->value, 'visibility' => 'visible', 'audience' => $values['audience'], 'privacy_revision' => $privacy,
            'body' => $this->post->body, 'body_format' => $this->post->format, 'media_refs' => $media, 'created_at' => $now]);

        return new MutationEffect(affectedRows: $affected, resource: $resource, newRevision: $revision,
            events: [new MutationEvent(type: $state === PublicationState::Published && ($this->locked['publication_state'] ?? null) === PublicationState::Published->value ? 'posts.edited' : 'posts.'.$state->value, refs: ['post' => $resource])]);
    }
}
