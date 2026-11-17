<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Events;

use Override;
use Packman\Attributes\Api;
use Packman\Events\DomainEvent;
use SocKit\Modules\Posts\Enums\ContentState;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\Revision;

#[Api(since: '1.0')]
final readonly class PostStateChanged extends DomainEvent
{
    public function __construct(
        public PanelId $panel,
        public string $postId,
        public ContentState $previousState,
        public ContentState $newState,
        public Revision $revision,
        public ?string $reasonCode = null,
    ) {
        parent::__construct(scopeId: $panel->value);
    }

    #[Override]
    public static function name(): string
    {
        return 'sockit.posts.state_changed';
    }
}
