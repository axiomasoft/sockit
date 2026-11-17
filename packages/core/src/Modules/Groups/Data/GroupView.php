<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;
use SocKit\Modules\Groups\Enums\GroupVisibility;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\Revision;

#[Api(since: '1.0')]
final readonly class GroupView
{
    public function __construct(
        public string $id,
        public PanelId $panel,
        public string $slug,
        public string $title,
        public GroupVisibility $visibility,
        public string $state,
        public Revision $revision,
        public ActorRef $createdBy,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
