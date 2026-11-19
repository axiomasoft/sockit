<?php

declare(strict_types=1);

namespace SocKit\Posts\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Posts\Enums\PublicationState;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;

/** An immutable view, authorized against current post state even when viewing an old revision. */
#[Api(since: '1.0')]
final readonly class PostView
{
    /** @param list<MediaReference> $media */
    public function __construct(public ResourceRef $resource, public Revision $revision, public ActorRef $actor, public string $action,
        public ?string $reason, public string $body, public AudienceSpec $audience, public array $media,
        public PublicationState $publication, public ?\DateTimeImmutable $publishedAt) {}
}
