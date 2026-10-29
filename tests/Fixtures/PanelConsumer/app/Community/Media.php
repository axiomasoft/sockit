<?php

declare(strict_types=1);

namespace Acme\Community;

use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Posts\Contracts\MediaResolver;
use SocKit\Posts\Data\MediaReference;

final class Media implements MediaResolver
{
    public function associate(OperationHandle $handle, MediaReference $media): AuthorizationDecision
    {
        return AuthorizationDecision::allow('host.media_visible');
    }
}
