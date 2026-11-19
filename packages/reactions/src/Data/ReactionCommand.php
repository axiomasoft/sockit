<?php

declare(strict_types=1);

namespace SocKit\Reactions\Data;

use Packman\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;

/** Subject only. Actor and installation are always resolved from the current trusted context. */
#[Api(since: '1.0')]
final readonly class ReactionCommand
{
    public function __construct(public SurfaceRef $surface, public TargetRef $target, public string $kind = 'like')
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $kind) !== 1) {
            throw new InvalidPayloadException('Invalid reaction kind');
        }
    }
}
