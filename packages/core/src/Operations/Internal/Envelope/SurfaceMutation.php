<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal\Envelope;

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
use SocKit\Operations\Internal\Storage\OwnerConnection;
use SocKit\Operations\Internal\Storage\SurfaceInstances;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Enums\SurfaceLifecycle;
use SocKit\Surfaces\Enums\TargetLifecycle;

/** Kernel-only, cannot be impersonated by a module commandType. */
final readonly class SurfaceMutation implements OwnerMutation
{
    public function __construct(private OwnerConnection $owner, public string $action)
    {
        if (! in_array($action, ['create', 'archive', 'resume', 'delete'], true)) {
            throw new EnvelopeException(ProblemCode::PayloadInvalid);
        }
    }

    public function commandType(): string
    {
        return 'sockit.surfaces.'.$this->action;
    }

    public function payload(): array
    {
        return [];
    }

    public function request(OperationHandle $handle): MutationRequest
    {
        $spec = AudienceSpec::direct($handle->actor->actor ?? throw new EnvelopeException(ProblemCode::AuthenticationRequired));

        return new MutationRequest(action: $this->commandType(), subject: new AudienceSubject(key: 'sockit.surface', spec: $spec, author: null, surface: $handle->surface, installation: $handle->actor->installation), intent: 'write', safety: AuthorizationDecision::allow('kernel.lifecycle'), generation: 'kernel.lifecycle');
    }

    public function lock(OperationHandle $handle): MutationSnapshot
    {
        $current = new SurfaceInstances($this->owner)->current();
        if ($this->action === 'create') {
            if ($current !== null) {
                throw new EnvelopeException($current->lifecycle === SurfaceLifecycle::Deleted ? ProblemCode::ContentDeleted : ProblemCode::RevisionConflict);
            }

            return new MutationSnapshot(resource: null, revision: null, lifecycle: TargetLifecycle::Live, generation: 'kernel.lifecycle', safety: AuthorizationDecision::allow('kernel.lifecycle'));
        }
        $current = new SurfaceInstances($this->owner)->lock();

        return new MutationSnapshot(resource: $current->resource(), revision: $current->revision, lifecycle: $current->lifecycle === SurfaceLifecycle::Archived ? TargetLifecycle::ReadOnly : TargetLifecycle::Live,
            generation: 'kernel.lifecycle', safety: AuthorizationDecision::allow('kernel.lifecycle'));
    }

    public function persist(OperationHandle $handle, MutationSnapshot $snapshot): MutationEffect
    {
        $store = new SurfaceInstances($this->owner);
        if ($this->action === 'create') {
            $current = $store->create(sourceGeneration: new Revision(1), privacyGeneration: new Revision(1));
        } else {
            $before = $store->lock();
            $current = $store->transition(to: match ($this->action) {
                'archive' => SurfaceLifecycle::Archived, 'resume' => SurfaceLifecycle::Live, default => SurfaceLifecycle::Deleted
            },
                expectedRevision: $snapshot->revision ?? throw new EnvelopeException(ProblemCode::PayloadInvalid), sourceGeneration: $before->sourceGeneration, privacyGeneration: $before->privacyGeneration->next());
        }

        return new MutationEffect(affectedRows: 1, resource: $current->resource(), newRevision: $current->revision,
            events: [new MutationEvent(type: 'sockit.surfaces.'.$this->action, refs: ['surface' => $current->resource()])]);
    }
}
