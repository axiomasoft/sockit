<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Events;

use Override;
use Packman\Attributes\Api;
use Packman\Events\DomainEvent;
use SocKit\Modules\Groups\Enums\GroupMemberRole;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;

#[Api(since: '1.0')]
final readonly class GroupMemberAdded extends DomainEvent
{
    public function __construct(
        public PanelId $panel,
        public string $groupId,
        public ActorRef $member,
        public GroupMemberRole $role,
    ) {
        parent::__construct(scopeId: $panel->value);
    }

    #[Override]
    public static function name(): string
    {
        return 'sockit.groups.member_added';
    }
}
