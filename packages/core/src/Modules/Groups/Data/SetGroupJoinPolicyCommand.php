<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Data;

use Packman\Attributes\Api;
use SocKit\Modules\Groups\Enums\GroupJoinPolicy;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;

#[Api(since: '1.0')]
final readonly class SetGroupJoinPolicyCommand
{
    public function __construct(
        public CommandKey $key,
        public SurfaceRef $surface,
        public string $groupId,
        public Revision $expectedRevision,
        public GroupJoinPolicy $joinPolicy,
        public string $integration = 'sockit.groups',
    ) {
        if (preg_match(ResourceRef::ID_PATTERN, $groupId) !== 1) {
            throw InvalidPayloadException::invalid('Group id is malformed.', ['group_id' => $groupId], '/group_id');
        }
    }

    /** @return array<string, mixed> */
    public function toDigestPayload(): array
    {
        return [
            'surface' => $this->surface->toWire(),
            'group_id' => $this->groupId,
            'expected_revision' => $this->expectedRevision->value,
            'join_policy' => $this->joinPolicy->value,
        ];
    }
}
