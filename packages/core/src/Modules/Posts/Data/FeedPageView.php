<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Data;

use Packman\Attributes\Api;
use SocKit\Audience\AudienceGate;

/**
 * Immutable filtered keyset feed page containing only {@see AudienceGate}-approved items.
 */
#[Api(since: '1.0')]
final readonly class FeedPageView
{
    /** @param list<PostView> $items */
    public function __construct(
        public array $items,
        public ?FeedCursor $nextCursor,
        public bool $hasMore,
    ) {}
}
