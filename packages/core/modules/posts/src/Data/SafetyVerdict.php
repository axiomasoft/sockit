<?php

declare(strict_types=1);

namespace SocKit\Posts\Data;

use SocKit\Attributes\Api;
use SocKit\Posts\Enums\SafetyDecision;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class SafetyVerdict
{
    public function __construct(public SafetyDecision $decision, public string $reason)
    {
        if ($reason === '' || strlen($reason) > 512) {
            throw new InvalidPayloadException('Safety reason required');
        }
    }
}
