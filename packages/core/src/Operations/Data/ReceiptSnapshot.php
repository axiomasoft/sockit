<?php

declare(strict_types=1);

namespace SocKit\Operations\Data;

use SocKit\Attributes\Api;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Enums\OperationOutcome;
use SocKit\Operations\Exceptions\InvalidOperationResultException;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;

/** Non-content ledger metadata. This is never authorization to disclose an outcome to a caller. */
#[Api(since: '1.0')]
final readonly class ReceiptSnapshot
{
    public function __construct(
        public string $id,
        public CommandNamespace $namespace,
        public string $keyHash,
        public PayloadDigest $digest,
        public ActorRef $initiatingActor,
        public OperationOutcome $outcome,
        public ?ResourceRef $resource,
        public ?Revision $revision,
        public ?ProblemCode $problem,
    ) {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)
            || ! preg_match('/^[0-9a-f]{16}$/D', $keyHash)
            || ($resource !== null && ! $resource->panel->equals($namespace->panel))
            || ! in_array($outcome, [OperationOutcome::Confirmed, OperationOutcome::Rejected], true)
            || ($outcome === OperationOutcome::Confirmed && ($resource === null || $revision === null || $problem !== null))
            || ($outcome === OperationOutcome::Rejected && ($problem === null || ! $problem->spec()->receiptRecorded || $resource !== null || $revision !== null))) {
            throw new InvalidOperationResultException('Invalid stable receipt snapshot');
        }
    }
}
