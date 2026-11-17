<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Events;

use Override;
use Packman\Attributes\Api;
use Packman\Events\DomainEvent;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\Revision;

#[Api(since: '1.0')]
final readonly class PostPublished extends DomainEvent
{
    public function __construct(
        public PanelId $panel,
        public string $postId,
        public ActorRef $author,
        public Revision $revision,
        public Revision $privacyRevision,
    ) {
        parent::__construct(scopeId: $panel->value);
    }

    #[Override]
    public static function name(): string
    {
        return 'sockit.posts.published';
    }
}
