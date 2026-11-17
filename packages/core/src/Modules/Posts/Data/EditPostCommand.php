<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * CAS-protected post edit command requiring expected revision matching current database state.
 */
#[Api(since: '1.0')]
final readonly class EditPostCommand
{
    public function __construct(
        public CommandKey $key,
        public SurfaceRef $surface,
        public string $postId,
        public Revision $expectedRevision,
        public string $body,
        public ?string $bodyFormat = null,
        public ?AudienceSpec $audience = null,
        public string $integration = 'sockit.posts',
    ) {
        if (preg_match(ResourceRef::ID_PATTERN, $postId) !== 1) {
            throw InvalidPayloadException::invalid('Post id is malformed.', ['post_id' => $postId], '/post_id');
        }

        if (trim($body) === '') {
            throw InvalidPayloadException::invalid('Edited post body cannot be empty.', [], '/body');
        }

        if ($bodyFormat !== null && preg_match(PublishPostCommand::FORMAT_PATTERN, $bodyFormat) !== 1) {
            throw InvalidPayloadException::invalid("Invalid post body format [{$bodyFormat}].", ['body_format' => $bodyFormat], '/body_format');
        }
    }

    /** @return array<string, mixed> */
    public function toDigestPayload(): array
    {
        return [
            'surface' => $this->surface->toWire(),
            'post_id' => $this->postId,
            'expected_revision' => $this->expectedRevision->value,
            'body' => $this->body,
            'body_format' => $this->bodyFormat,
            'audience' => $this->audience?->toWire(),
        ];
    }
}
