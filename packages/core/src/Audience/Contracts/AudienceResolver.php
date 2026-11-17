<?php

declare(strict_types=1);

namespace SocKit\Audience\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\PrivacyFloor;

/**
 * SPI evaluating content read and publication audience predicates.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface AudienceResolver
{
    public function decideRead(ActorContext $viewer, AudienceSubject $subject): AudienceVerdict;

    public function decidePublish(ActorContext $author, AudienceSubject $subject, PrivacyFloor $floor): AudienceVerdict;
}
