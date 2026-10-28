<?php

declare(strict_types=1);

namespace SocKit\Surfaces;

use SocKit\Attributes\Api;
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
