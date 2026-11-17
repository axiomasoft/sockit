<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Data;

use Packman\Attributes\Api;
use SocKit\Modules\Groups\Enums\GroupJoinPolicy;
use SocKit\Modules\Groups\Enums\GroupVisibility;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Command creating a panel-scoped group without implicit creator membership enrollment.
 */
#[Api(since: '1.0')]
final readonly class CreateGroupCommand
{
    public const string SLUG_PATTERN = '/\A[a-z0-9][a-z0-9_-]{1,63}\z/';

    public function __construct(
        public CommandKey $key,
        public SurfaceRef $surface,
        public string $slug,
        public string $title,
        public GroupVisibility $visibility = GroupVisibility::Private,
        public string $integration = 'sockit.groups',
        public GroupJoinPolicy $joinPolicy = GroupJoinPolicy::Approval,
    ) {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw InvalidPayloadException::invalid('Group slug must match ^[a-z0-9][a-z0-9_-]{1,63}$.', ['slug' => $slug], '/slug');
        }

        if (trim($title) === '' || strlen($title) > 160) {
            throw InvalidPayloadException::invalid('Group title must be 1..160 bytes.', [], '/title');
        }
    }

    /** @return array<string, mixed> */
    public function toDigestPayload(): array
    {
        return [
            'surface' => $this->surface->toWire(),
            'slug' => $this->slug,
            'title' => $this->title,
            'visibility' => $this->visibility->value,
            'join_policy' => $this->joinPolicy->value,
        ];
    }
}
