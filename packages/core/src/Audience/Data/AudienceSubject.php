<?php

declare(strict_types=1);

namespace SocKit\Audience\Data;

use Packman\Attributes\Api;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;

#[Api(since: '1.0')]
final readonly class AudienceSubject
{
    public function __construct(
        public string $key,
        public AudienceSpec $spec,
        public ?ActorRef $author,
        public SurfaceRef $surface,
        public InstallationRef $installation,
        public bool $surfaceArchived = false,
        public bool $archiveReaders = false,
    ) {
        if ($key === '' || preg_match('~[\x00-\x1f\x7f]~', $key)) {
            throw new InvalidPayloadException('Invalid audience result key');
        }
    }
}
