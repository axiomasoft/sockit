<?php

declare(strict_types=1);

namespace SocKit\Audience\Contracts;

use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AuthorizationDecision;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ActionAuthorizer
{
    public function decide(ActionRequest $request): AuthorizationDecision;
}
