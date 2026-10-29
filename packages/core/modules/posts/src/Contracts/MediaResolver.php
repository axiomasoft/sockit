<?php

declare(strict_types=1);

namespace SocKit\Posts\Contracts;

use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Posts\Data\MediaReference;

/** Read-only association/visibility check outside tx; no remote writes, uploads or file storage.
 * The host's local authority fence must cover mutable consent/visibility facts used to publish.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface MediaResolver
{
    public function associate(OperationHandle $handle, MediaReference $media): AuthorizationDecision;
}
