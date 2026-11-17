<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Data;

use Packman\Attributes\Api;
use SocKit\Modules\Posts\Enums\ContentState;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * CAS-protected lifecycle state transition command (promote draft, hide, restore, delete, erase).
 */
#[Api(since: '1.0')]
final readonly class TransitionPostStateCommand
{
    public const string REASON_PATTERN = '/\A[a-z][a-z0-9_.-]{0,95}\z/';

    public function __construct(
        public CommandKey $key,
        public SurfaceRef $surface,
        public string $postId,
        public Revision $expectedRevision,
        public ContentState $targetState,
        public ?string $reasonCode = null,
        public string $integration = 'sockit.posts',
    ) {
        if (preg_match(ResourceRef::ID_PATTERN, $postId) !== 1) {
            throw InvalidPayloadException::invalid('Post id is malformed.', ['post_id' => $postId], '/post_id');
        }

        if ($reasonCode !== null && preg_match(self::REASON_PATTERN, $reasonCode) !== 1) {
            throw InvalidPayloadException::invalid("Invalid reason code [{$reasonCode}].", ['reason_code' => $reasonCode], '/reason_code');
        }
    }

    /** @return array<string, mixed> */
    public function toDigestPayload(): array
    {
        return [
            'surface' => $this->surface->toWire(),
            'post_id' => $this->postId,
            'expected_revision' => $this->expectedRevision->value,
            'target_state' => $this->targetState->value,
            'reason_code' => $this->reasonCode,
        ];
    }
}
