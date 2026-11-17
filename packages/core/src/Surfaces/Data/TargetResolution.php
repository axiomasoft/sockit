<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\TargetResolver;

/**
 * Result of resolving a target entity through {@see TargetResolver}.
 */
#[Api(since: '1.0')]
final readonly class TargetResolution
{
    public function __construct(
        public HostEntityRef $target,
        public bool $exists,
        public bool $visible,
        public ?ActorRef $owner = null,
        public ?AudienceSpec $audience = null,
        public ?Revision $privacyRevision = null,
        public ?string $reason = null,
    ) {}

    public static function available(
        HostEntityRef $target,
        ?ActorRef $owner = null,
        ?AudienceSpec $audience = null,
        ?Revision $privacyRevision = null,
    ): self {
        return new self(
            target: $target,
            exists: true,
            visible: true,
            owner: $owner,
            audience: $audience,
            privacyRevision: $privacyRevision,
        );
    }

    public static function missing(HostEntityRef $target, string $reason = 'target.not_found'): self
    {
        return new self(
            target: $target,
            exists: false,
            visible: false,
            reason: $reason,
        );
    }

    public static function hidden(HostEntityRef $target, string $reason = 'target.hidden'): self
    {
        return new self(
            target: $target,
            exists: true,
            visible: false,
            reason: $reason,
        );
    }

    public function isAccessible(): bool
    {
        return $this->exists && $this->visible;
    }
}
