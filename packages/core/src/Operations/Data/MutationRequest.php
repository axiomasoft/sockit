<?php

declare(strict_types=1);

namespace SocKit\Operations\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\TargetRef;

#[Api(since: '1.0')]
final readonly class MutationRequest
{
    public function __construct(
        public string $action,
        public AudienceSubject $subject,
        public string $intent,
        public AuthorizationDecision $safety,
        public string $generation,
        public ?TargetRef $target = null,
        public ?AudienceSpec $proposedAudience = null,
    ) {
        if (! preg_match('/^[a-z][a-z0-9_.-]{0,119}$/D', $action) || ! in_array($intent, ['write', 'publish', 'read'], true)
            || $generation === '' || strlen($generation) > 128 || preg_match('/[\x00-\x1f\x7f]/', $generation)
            || ($intent === 'publish') !== ($proposedAudience !== null)) {
            throw new InvalidPayloadException('Invalid mutation request');
        }
    }
}
