<?php

declare(strict_types=1);

namespace SocKit\Groups\Data;

use SocKit\Attributes\Api;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class MembershipActivation
{
    public function __construct(public ResourceRef $group, public ActorRef $subject, public string $transition)
    {
        if ($group->kind !== ResourceKind::Group || ! in_array($transition, ['join', 'approve'], true)) {
            throw new InvalidPayloadException('Invalid membership activation');
        }
    }
}
