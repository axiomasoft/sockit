<?php

declare(strict_types=1);

namespace SocKit\Audience\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;

/**
 * Optional batch capability interface for {@see AudienceResolver}.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ResolvesAudienceInBatch
{
    /**
     * @param  list<AudienceSubject>  $subjects
     * @return array<string, AudienceVerdict>
     */
    public function decideReadMany(ActorContext $viewer, array $subjects): array;
}
