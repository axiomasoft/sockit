<?php

declare(strict_types=1);

namespace SocKit\Reactions\Data;

use SocKit\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\TargetRef;

#[Api(since: '1.0')]
final readonly class ReactionQuery
{
    public function __construct(public string $key, public TargetRef $target)
    {
        if ($key === '' || strlen($key) > 128 || preg_match('/[\x00-\x1f\x7f]/', $key)) {
            throw new InvalidPayloadException('Invalid reaction batch key');
        }
    }
}
