<?php

declare(strict_types=1);

namespace SocKit\Surfaces;

use SocKit\Attributes\Api;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Operations;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\SurfaceInstance;

#[Api(since: '1.0')]
final readonly class SurfaceInstances
{
    public function __construct(private Operations $operations) {}

    public function get(OperationHandle $handle): SurfaceInstance
    {
        return $this->operations->surface($handle);
    }

    /** Local-only dependency fence before host/group/content locks, on the exact owner connection.
     * Both handles and expected snapshot must have been sampled by current outside guards.
     * Holds the current live dependency row until owner commit; compares identity and all
     * generations. Blocking surface locks follow canonical identity order. If the dependency
     * sorts before the already locked owner surface, NOWAIT rejects contention instead of
     * introducing a reverse wait. Unknown/errors require the caller to abort the envelope;
     * no retry, network, ambient actor lookup or lifecycle mutation occurs here.
     */
    public function recheck(OperationHandle $owner, OperationHandle $dependency, SurfaceInstance $expected): AuthorizationDecision
    {
        return $this->operations->recheckSurface($owner, $dependency, $expected);
    }

    public function create(OperationHandle $handle, CommandEnvelope $command): OperationResult
    {
        return $this->operations->surfaceCommand(handle: $handle, action: 'create', command: $command);
    }

    public function archive(OperationHandle $handle, CommandEnvelope $command): OperationResult
    {
        return $this->operations->surfaceCommand(handle: $handle, action: 'archive', command: $command);
    }

    public function resume(OperationHandle $handle, CommandEnvelope $command): OperationResult
    {
        return $this->operations->surfaceCommand(handle: $handle, action: 'resume', command: $command);
    }

    public function delete(OperationHandle $handle, CommandEnvelope $command): OperationResult
    {
        return $this->operations->surfaceCommand(handle: $handle, action: 'delete', command: $command);
    }

    public function digest(OperationHandle $handle, string $action, ?Revision $expectedRevision = null): PayloadDigest
    {
        return $this->operations->surfaceDigest(handle: $handle, action: $action, expectedRevision: $expectedRevision);
    }
}
