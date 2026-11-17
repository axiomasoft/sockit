<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Data;

use Packman\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Keyset-paginated read query for a surface post feed.
 */
#[Api(since: '1.0')]
final readonly class GetFeedQuery
{
    public function __construct(
        public SurfaceRef $surface,
        public int $limit = 20,
        public ?FeedCursor $cursor = null,
    ) {
        if ($limit < 1 || $limit > 500) {
            throw InvalidPayloadException::invalid('GetFeedQuery limit must be between 1 and 500.', ['limit' => $limit], '/limit');
        }
    }
}
