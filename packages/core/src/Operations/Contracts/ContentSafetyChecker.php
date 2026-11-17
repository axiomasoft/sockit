<?php

declare(strict_types=1);

namespace SocKit\Operations\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Actors\Data\ActorContext;
use SocKit\Operations\Data\SafetyVerdict;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Optional guard slot SPI for prescreening content before publication.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ContentSafetyChecker
{
    /** @param array<string, scalar|null> $metadata */
    public function assess(ActorContext $actor, SurfaceRef $surface, string $contentType, string $body, array $metadata = []): SafetyVerdict;
}
