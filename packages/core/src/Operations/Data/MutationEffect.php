<?php

declare(strict_types=1);

namespace SocKit\Operations\Data;

use Packman\Attributes\Api;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;

#[Api(since: '1.0')]
final readonly class MutationEffect
{
    /** @param list<MutationEvent> $events */
    public function __construct(public int $affectedRows, public ResourceRef $resource, public Revision $newRevision, public array $events) {}
}
