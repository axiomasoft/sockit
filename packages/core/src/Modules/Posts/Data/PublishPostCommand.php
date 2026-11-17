<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Command publishing or creating a draft post on a panel surface (author derived server-side).
 */
#[Api(since: '1.0')]
final readonly class PublishPostCommand
{
    public const string FORMAT_PATTERN = '/\A[a-z][a-z0-9_-]{0,47}\z/';

    public function __construct(
        public CommandKey $key,
        public SurfaceRef $surface,
        public string $body,
        public string $bodyFormat = 'plain',
        public ?AudienceSpec $audience = null,
        public bool $asDraft = false,
        public string $integration = 'sockit.posts',
    ) {
        if (trim($body) === '') {
            throw InvalidPayloadException::invalid('Post body cannot be empty.', [], '/body');
        }

        if (preg_match(self::FORMAT_PATTERN, $bodyFormat) !== 1) {
            throw InvalidPayloadException::invalid(
                "Invalid post body format [{$bodyFormat}].",
                ['body_format' => $bodyFormat],
                '/body_format',
            );
        }
    }

    /** @return array<string, mixed> */
    public function toDigestPayload(): array
    {
        return [
            'surface' => $this->surface->toWire(),
            'body' => $this->body,
            'body_format' => $this->bodyFormat,
            'audience' => $this->audience?->toWire(),
            'as_draft' => $this->asDraft,
        ];
    }
}
