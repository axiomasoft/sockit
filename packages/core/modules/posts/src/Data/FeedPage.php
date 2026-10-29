<?php

declare(strict_types=1);

namespace SocKit\Posts\Data;

use SocKit\Attributes\Api;

/** No candidate totals or reaction counters. A non-null cursor means continue, including an empty budget-limited page. */
#[Api(since: '1.0')]
final readonly class FeedPage
{
    /** @param list<PostView> $items */
    public function __construct(public array $items, public ?string $nextCursor) {}
}
