<?php

declare(strict_types=1);

namespace SocKit\Operations\Data;

use Packman\Attributes\Api;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\Revision;

/**
 * Immutable operation envelope pairing idempotency key, panel-qualified namespace, canonical payload digest, and optional CAS revision.
 */
#[Api(since: '1.0')]
final readonly class CommandEnvelope
{
    public function __construct(
        public CommandKey $key,
        public CommandNamespace $namespace,
        public PayloadDigest $digest,
        public ?Revision $expectedRevision = null,
    ) {}
}
