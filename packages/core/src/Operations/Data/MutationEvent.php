<?php

declare(strict_types=1);

namespace SocKit\Operations\Data;

use Packman\Attributes\Api;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Exceptions\InvalidPayloadException;

/** Proposal only. The kernel supplies event/source identity, audience and revisions. */
#[Api(since: '1.0')]
final readonly class MutationEvent
{
    /** @param array<string, ResourceRef> $refs */
    public function __construct(public string $type, public array $refs)
    {
        if (! preg_match('/^[a-z][a-z0-9_.-]{0,119}$/D', $type)) {
            throw new InvalidPayloadException('Invalid mutation event type');
        }
    }
}
