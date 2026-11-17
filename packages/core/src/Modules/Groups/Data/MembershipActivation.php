<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Data;

use Packman\Attributes\Api;
use SocKit\Modules\Groups\Enums\GroupMemberRole;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;

#[Api(since: '1.0')]
final readonly class MembershipActivation
{
    public function __construct(
        public PanelId $panel,
        public SurfaceRef $surface,
        public string $groupId,
        public ActorRef $subject,
        public GroupMemberRole $role,
        public string $transition,
    ) {
        if (preg_match(ResourceRef::ID_PATTERN, $groupId) !== 1) {
            throw InvalidPayloadException::invalid('Group id is malformed.', ['group_id' => $groupId], '/group_id');
        }

        if (trim($transition) === '') {
            throw InvalidPayloadException::invalid('Membership transition must not be empty.', [], '/transition');
        }
    }
}
