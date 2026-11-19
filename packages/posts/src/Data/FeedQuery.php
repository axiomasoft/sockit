<?php

declare(strict_types=1);

namespace SocKit\Posts\Data;

use Packman\Attributes\Api;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Exceptions\InvalidPayloadException;

/** Fixed newest-first order by immutable first publication and ID. Only the optional author wire filter is supported. */
#[Api(since: '1.0')]
final readonly class FeedQuery
{
    /** @param array<string, string> $filters */
    public function __construct(public ?int $limit = null, public ?string $cursor = null, public array $filters = [])
    {
        if (($limit !== null && ($limit < 1 || $limit > 500)) || array_diff(array_keys($filters), ['author']) !== []) {
            throw new InvalidPayloadException('Invalid feed query');
        }
        if (isset($filters['author'])) {
            ActorRef::fromWire($filters['author']);
        }
    }
}
