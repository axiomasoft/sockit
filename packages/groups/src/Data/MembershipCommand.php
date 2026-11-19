<?php

declare(strict_types=1);

namespace SocKit\Groups\Data;

use Packman\Attributes\Api;
use SocKit\Groups\Enums\MembershipRole;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidPayloadException;

/** Recipient is intent data. Self actions always derive their subject from the trusted handle. */
#[Api(since: '1.0')]
final readonly class MembershipCommand
{
    public function __construct(public ResourceRef $group, public string $action, public ?ActorRef $subject = null, public ?MembershipRole $role = null)
    {
        if ($group->kind !== ResourceKind::Group || ! in_array($action, ['join', 'leave', 'approve', 'invite', 'add_member', 'remove_member'], true)
            || in_array($action, ['approve', 'invite', 'add_member', 'remove_member'], true) !== ($subject !== null)
            || ($role !== null && ! in_array($action, ['add_member', 'invite', 'approve'], true))) {
            throw new InvalidPayloadException('Invalid membership command');
        }
    }
}
