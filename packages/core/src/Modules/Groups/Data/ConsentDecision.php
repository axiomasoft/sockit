<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class ConsentDecision
{
    public function __construct(
        public AuthorizationDecision $decision,
        public string $generation,
    ) {
        if (trim($generation) === '' || strlen($generation) > 64) {
            throw InvalidPayloadException::invalid('ConsentDecision generation must be 1..64 bytes.', [], '/generation');
        }
    }
}
