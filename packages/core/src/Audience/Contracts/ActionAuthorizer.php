<?php

declare(strict_types=1);

namespace SocKit\Audience\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AuthorizationDecision;

/**
 * Host SPI deciding whether an actor may perform a named action in the given surface/resource context.
 *
 * Called once per operation outside SQL transactions. Unknown/exceptions fail closed.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ActionAuthorizer
{
    public function decide(ActionRequest $request): AuthorizationDecision;
}
