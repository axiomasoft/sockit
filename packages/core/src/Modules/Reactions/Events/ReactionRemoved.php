<?php

declare(strict_types=1);

namespace SocKit\Modules\Reactions\Events;

use Override;
use Packman\Attributes\Api;
use Packman\Events\DomainEvent;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;

#[Api(since: '1.0')]
final readonly class ReactionRemoved extends DomainEvent
{
    public function __construct(
        public PanelId $panel,
        public HostEntityRef $target,
        public ActorRef $actor,
        public string $reactionType,
    ) {
        parent::__construct(scopeId: $panel->value);
    }

    #[Override]
    public static function name(): string
    {
        return 'sockit.reactions.removed';
    }
}
