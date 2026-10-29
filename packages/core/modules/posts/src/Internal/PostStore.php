<?php

declare(strict_types=1);

namespace SocKit\Posts\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\Operations;
use SocKit\Posts\Contracts\MediaResolver;
use SocKit\Posts\Data\MediaReference;
use SocKit\Posts\Data\PostCommand;
use SocKit\Posts\Data\PostView;
use SocKit\Posts\Enums\PublicationState;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Enums\SurfaceLifecycle;

final readonly class PostStore
{
    public function __construct(private Application $app) {}

    public function assertHandle(OperationHandle $handle): void
    {
        $this->app->make(OperationHandles::class)->assertCurrent($handle);
        if (! in_array('sockit.posts', $handle->panel->modules, true) || ! in_array('posts', $handle->definition->features, true)) {
            throw new PostException(ProblemCode::ModuleInvalid);
        }
    }

    public function table(OperationHandle $handle, string $entity): Builder
    {
        $storage = $handle->panel->storage;
        $table = ($storage->schema === null ? '' : $storage->schema.'.').($storage->tables[$entity] ?? throw new PostException(ProblemCode::ConfigurationInvalid));

        return $this->app->make(ConnectionResolverInterface::class)->connection($storage->connection)->table($table);
    }

    public function host(OperationHandle $handle): TargetRef
    {
        return new TargetRef(host: $handle->surface->host, incarnation: $handle->surface->incarnation);
    }

    public function outside(OperationHandle $handle): void
    {
        $this->assertHandle($handle);
        if ($this->app->make(ConnectionResolverInterface::class)->connection($handle->panel->storage->connection)->transactionLevel() !== 0) {
            throw new PostException(ProblemCode::ConfigurationInvalid);
        }
    }

    /** @return array<string, mixed> */
    public function row(OperationHandle $handle, ResourceRef $resource, bool $lock = false): array
    {
        $row = $this->retained($handle, $resource, $lock);
        if (($row['visibility'] ?? null) !== 'visible') {
            throw new PostException(($row['visibility'] ?? null) === 'deleted' ? ProblemCode::ContentDeleted : ProblemCode::ContentHidden);
        }

        return $row;
    }

    /** Owner lifecycle access preserves hidden publication state, but never admits a terminal record.
     * @return array<string, mixed> */
    public function retained(OperationHandle $handle, ResourceRef $resource, bool $lock = false): array
    {
        if ($resource->kind !== ResourceKind::Post || ! $resource->panel->equals($handle->surface->panel)) {
            throw new PostException(ProblemCode::PanelBindingMismatch);
        }
        $query = $this->table($handle, 'posts')->where('id', $resource->id);
        $raw = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($raw === null) {
            throw new PostException(ProblemCode::ContentHidden);
        }
        $row = $this->columns($raw);
        foreach ($this->identity($handle) as $key => $value) {
            if (($row[$key] ?? null) !== $value) {
                throw new PostException(ProblemCode::PanelBindingMismatch);
            }
        }
        if (($row['content_state'] ?? null) !== 'retained') {
            throw new PostException(ProblemCode::ContentErased);
        }
        if (($row['visibility'] ?? null) === 'deleted') {
            throw new PostException(ProblemCode::ContentDeleted);
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    public function content(OperationHandle $handle, ResourceRef $resource, array $row, bool $publish): PostCommand
    {
        $raw = json_decode($this->text($row, 'media_refs'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new PostException(ProblemCode::PayloadInvalid);
        }
        $media = [];
        foreach ($raw as $ref) {
            if (! is_array($ref) || ! is_string($ref['owner'] ?? null) || ! is_string($ref['id'] ?? null)) {
                throw new PostException(ProblemCode::PayloadInvalid);
            }
            $media[] = new MediaReference($ref['owner'], $ref['id']);
        }

        return new PostCommand(surface: $handle->surface, resource: $resource, body: $this->text($row, 'body'),
            audience: AudienceSpec::fromWire($this->object($this->text($row, 'audience'))), publish: $publish, media: $media);
    }

    /** @return array<string, string|int> */
    public function identity(OperationHandle $handle): array
    {
        return ['installation' => $handle->actor->installation->id, 'panel' => $handle->surface->panel->value, 'surface_kind' => $handle->surface->kind->value,
            'host_type' => $handle->surface->host->type, 'host_key' => $handle->surface->host->value, 'surface_incarnation' => $handle->surface->incarnation->value];
    }

    /** @return array<string, mixed> */
    public function readable(OperationHandle $handle, ResourceRef $resource, ?TargetResolution $host = null): array
    {
        $this->outside($handle);
        $this->app->make(Operations::class)->surface($handle);
        $host ??= $this->app->make(OperationHandles::class)->resolveTarget(handle: $handle, target: $this->host($handle));
        if (! $host->read->canRead()) {
            throw new PostException(ProblemCode::ContentHidden);
        }
        $this->app->make(ActionAuthorizer::class)->decide(new ActionRequest(actor: $handle->actor, action: 'posts.read', surface: $handle->surface, resource: $resource))->requireAllowed();
        $row = $this->row($handle, $resource);
        if ($this->text($row, 'publication_state') !== PublicationState::Published->value) {
            throw new PostException(ProblemCode::ContentHeld);
        }
        $this->audience($handle, $row);

        return $row;
    }

    /** @param array<string, mixed> $row */
    public function audience(OperationHandle $handle, array $row): AudienceVerdict
    {
        $surface = $this->app->make(Operations::class)->surface($handle);
        $subject = new AudienceSubject(key: 'posts.current', spec: AudienceSpec::fromWire($this->object($this->text($row, 'audience'))), author: null,
            surface: $handle->surface, installation: $handle->actor->installation,
            surfaceArchived: $surface->lifecycle === SurfaceLifecycle::Archived, archiveReaders: $handle->definition->archiveReaders);
        $verdict = $this->app->make(AudienceGate::class)->read(viewer: $handle->actor, subject: $subject);
        if (! $verdict->canRead() || ($verdict->expiresAt !== null && $verdict->expiresAt <= new \DateTimeImmutable('now'))) {
            throw new PostException(ProblemCode::AudienceDenied);
        }

        return $verdict;
    }

    /** @param array<string, mixed> $row */
    public function view(OperationHandle $handle, ResourceRef $resource, array $row, ?Revision $revision = null): PostView
    {
        $published = $row['published_at'] ?? null;
        if ($revision !== null) {
            $raw = $this->table($handle, 'post_revisions')->where('post_id', $resource->id)->where('revision', $revision->value)->first();
            if ($raw === null) {
                throw new PostException(ProblemCode::ContentHidden);
            }
            $row = $this->columns($raw);
            if (in_array($this->text($row, 'publication_state'), [PublicationState::Held->value, PublicationState::Rejected->value], true)) {
                throw new PostException(ProblemCode::ContentHeld);
            }
        }
        $media = json_decode($this->text($row, 'media_refs'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($media) || ! array_is_list($media)) {
            throw new PostException(ProblemCode::PayloadInvalid);
        }
        $refs = [];
        foreach ($media as $ref) {
            if (! is_array($ref) || ! isset($ref['owner'], $ref['id']) || ! is_string($ref['owner']) || ! is_string($ref['id'])) {
                throw new PostException(ProblemCode::PayloadInvalid);
            }
            $reference = new MediaReference(owner: $ref['owner'], id: $ref['id']);
            if (! $this->app->bound(MediaResolver::class)) {
                throw new PostException(ProblemCode::ProviderUnavailable);
            }
            $this->app->make(MediaResolver::class)->associate(handle: $handle, media: $reference)->requireAllowed();
            $refs[] = $reference;
        }

        return new PostView(resource: $resource, revision: new Revision($this->integer($row, 'revision')),
            actor: ActorRef::fromWire($this->text($row, $revision === null ? 'author_ref' : 'actor_ref')), action: $revision === null ? 'read' : $this->text($row, 'action'),
            reason: isset($row['reason']) ? $this->text($row, 'reason') : null, body: $this->text($row, 'body'), audience: AudienceSpec::fromWire($this->object($this->text($row, 'audience'))),
            media: $refs, publication: PublicationState::from($this->text($row, 'publication_state')), publishedAt: is_string($published) ? new \DateTimeImmutable($published, new \DateTimeZone('UTC')) : null);
    }

    /** @return array<string, mixed> */
    public function columns(object $row): array
    {
        $result = [];
        foreach (get_object_vars($row) as $key => $value) {
            if (! is_string($key)) {
                throw new PostException(ProblemCode::PayloadInvalid);
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function object(string $json): array
    {
        $raw = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($raw)) {
            throw new PostException(ProblemCode::PayloadInvalid);
        }
        $result = [];
        foreach ($raw as $key => $value) {
            if (! is_string($key)) {
                throw new PostException(ProblemCode::PayloadInvalid);
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    public function text(array $row, string $key): string
    {
        return is_string($row[$key] ?? null) ? $row[$key] : throw new PostException(ProblemCode::PayloadInvalid);
    }

    /** @param array<string, mixed> $row */
    public function integer(array $row, string $key): int
    {
        return is_int($row[$key] ?? null) && $row[$key] > 0 ? $row[$key] : throw new PostException(ProblemCode::PayloadInvalid);
    }
}
