<?php

declare(strict_types=1);

namespace SocKit\Groups\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class ConsentDecision
{
    public function __construct(public AuthorizationDecision $decision, public string $generation)
    {
        if ($generation === '' || strlen($generation) > 128 || preg_match('/[\x00-\x1f\x7f]/', $generation)) {
            throw new InvalidPayloadException('Invalid consent generation');
        }
    }
}
