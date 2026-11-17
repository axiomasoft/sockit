<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Modules\Posts\Enums\ContentState;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Immutable read DTO of an authorized post snapshot (never exposes Eloquent models).
 */
#[Api(since: '1.0')]
final readonly class PostView
{
    public function __construct(
        public string $id,
        public PanelId $panel,
        public SurfaceRef $surface,
        public ActorRef $author,
        public ContentState $state,
        public string $bodyFormat,
        public ?string $body,
        public Revision $revision,
        public Revision $privacyRevision,
        public AudienceSpec $audience,
        public ?DateTimeImmutable $publishedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
