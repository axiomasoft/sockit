<?php

declare(strict_types=1);

namespace SocKit\Posts\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\OperationHandles;
use SocKit\Posts\Enums\PublicationState;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Contracts\TargetWriteFence;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Enums\TargetLifecycle;

/** Posts owns both capability aliases. `post` protects existing resource receipt reads;
 * `sockit.post` is the canonical social target. IDs are never reused: incarnation 1 is permanent.
 * Scoped preparation is replaced on EVERY resolve and consumed by the local fence, never an access cache.
 */
final class PostTarget implements TargetResolver, TargetWriteFence
{
    private ?OperationHandle $prepared = null;

    private ?TargetResolution $host = null;

    private ?TargetResolution $sample = null;

    public function __construct(private readonly Application $app) {}

    public function resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution
    {
        $this->prepared = $this->host = $this->sample = null;
        $handles = $this->app->make(OperationHandles::class);
        $handle = $handles->forSurface($surface);
        if ($actor != $handle->actor || $target->incarnation->value !== 1
            || ! in_array($target->host->type, ['post', 'sockit.post'], true)
            || in_array($surface->host->type, ['post', 'sockit.post'], true)) {
            throw new PostException(ProblemCode::PanelBindingMismatch);
        }
        $store = new PostStore($this->app);
        $store->outside($handle);
        $host = $handles->resolveTarget(handle: $handle, target: $store->host($handle));
        $row = $store->readable(handle: $handle, resource: $this->resource($handle, $target), host: $host);
        $read = $store->audience($handle, $row);
        $write = $target->host->type === 'sockit.post' && $read->canWrite() && $host->write->canWrite()
            ? $read : AudienceVerdict::deny('audience.unknown');
        $resolution = new TargetResolution(surface: $surface, target: $target, installation: $actor->installation,
            read: $read, write: $write, lifecycle: $write->canWrite() ? TargetLifecycle::Live : TargetLifecycle::ReadOnly,
            privacyRevision: new Revision($store->integer($row, 'privacy_revision')), generation: $this->generation($store, $row, $host));
        $this->prepared = $handle;
        $this->host = $host;
        $this->sample = $resolution;

        return $resolution;
    }

    public function recheck(OperationHandle $handle, TargetResolution $resolved): AuthorizationDecision
    {
        $prepared = $this->prepared;
        $host = $this->host;
        $sample = $this->sample;
        $this->prepared = $this->host = $this->sample = null;
        $connection = $this->app->make(ConnectionResolverInterface::class)->connection($handle->panel->storage->connection);
        if ($prepared != $handle || $host === null || $sample === null || $connection->transactionLevel() !== 1
            || ! $sample->surface->equals($resolved->surface) || ! $sample->target->equals($resolved->target)
            || ! $sample->installation->equals($resolved->installation) || $sample->generation !== $resolved->generation
            || $sample->privacyRevision != $resolved->privacyRevision || ! $sample->write->canWrite()) {
            return AuthorizationDecision::unknown('posts.target_preparation_unproven');
        }
        // Host -> owned post -> reaction, matching post lifecycle's host fence then owner row.
        // Only the public local fence runs here; host/audience/action resolution stays outside tx.
        $this->app->make(OperationHandles::class)->recheckTarget(handle: $handle, resolved: $host)->requireAllowed();
        $store = new PostStore($this->app);
        $row = $store->row(handle: $handle, resource: $this->resource($handle, $resolved->target), lock: true);
        if ($store->text($row, 'publication_state') !== PublicationState::Published->value
            || $store->integer($row, 'privacy_revision') !== $resolved->privacyRevision->value
            || $this->generation($store, $row, $host) !== $resolved->generation) {
            return AuthorizationDecision::unknown('posts.target_changed');
        }

        return AuthorizationDecision::allow('posts.target_fenced');
    }

    private function resource(OperationHandle $handle, TargetRef $target): ResourceRef
    {
        return new ResourceRef(panel: $handle->surface->panel, kind: ResourceKind::Post, id: $target->host->value);
    }

    /** @param array<string, mixed> $row */
    private function generation(PostStore $store, array $row, TargetResolution $host): string
    {
        // Sampled verdicts/deadlines authorize this entry; only stable owner facts version the target.
        return hash('sha256', CanonicalJson::encode(['owner' => ['installation' => $store->text($row, 'installation'),
            'surface' => $host->surface->toWire(), 'post' => $store->text($row, 'id'), 'incarnation' => 1],
            'revision' => $store->integer($row, 'revision'),
            'privacy' => $store->integer($row, 'privacy_revision'), 'safety' => $store->integer($row, 'safety_generation'),
            'consent' => $store->integer($row, 'consent_generation'),
            'host' => ['installation' => $host->installation->toWire(), 'target' => $host->target->toWire(),
                'privacy' => $host->privacyRevision->value, 'generation' => $host->generation]]));
    }
}
