<?php

declare(strict_types=1);

namespace SocKit\Audience\Contracts;

use SocKit\Actors\Data\ActorContext;
use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ResolvesAudienceInBatch
{
    /**
     * @param  list<AudienceSubject>  $subjects
     * @return array<string, AudienceVerdict> Missing keys fail closed.
     */
    public function decideReadMany(ActorContext $viewer, array $subjects): array;
}
