<?php

declare(strict_types=1);

namespace SocKit\Operations;

use Illuminate\Contracts\Foundation\Application;
use SocKit\Attributes\Api;
use SocKit\Operations\Contracts\OwnerMutation;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Internal\Envelope\Envelope;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\SurfaceInstance;

/** Guarded runtime entry. Confirmed is returned only after our own owner commit. */
#[Api(since: '1.0')]
final readonly class Operations
{
    public function __construct(private Application $app) {}

    public function execute(OperationHandle $handle, CommandEnvelope $command, OwnerMutation $mutation): OperationResult
    {
        return new Envelope($this->app)->execute(handle: $handle, command: $command, mutation: $mutation);
    }

    public function lookup(OperationHandle $handle, CommandNamespace $namespace, CommandKey $key): ?OperationResult
    {
        return new Envelope($this->app)->lookup(handle: $handle, namespace: $namespace, key: $key);
    }

    /** Canonical subject-bound digest; payload never chooses actor, installation or panel. */
    public function digest(OperationHandle $handle, OwnerMutation $mutation, ?Revision $expectedRevision = null): PayloadDigest
    {
        return new Envelope($this->app)->digest(handle: $handle, mutation: $mutation, expectedRevision: $expectedRevision);
    }

    /** Used by the public lifecycle facade; the same guarded envelope owns every write. */
    public function surfaceCommand(OperationHandle $handle, string $action, CommandEnvelope $command): OperationResult
    {
        return new Envelope($this->app)->lifecycle(handle: $handle, action: $action, command: $command);
    }

    public function surfaceDigest(OperationHandle $handle, string $action, ?Revision $expectedRevision = null): PayloadDigest
    {
        return new Envelope($this->app)->lifecycleDigest(handle: $handle, action: $action, expectedRevision: $expectedRevision);
    }

    public function surface(OperationHandle $handle): SurfaceInstance
    {
        return new Envelope($this->app)->surface($handle);
    }
}
