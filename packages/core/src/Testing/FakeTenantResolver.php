<?php

declare(strict_types=1);

namespace SocKit\Testing;

use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Attributes\Api;
use SocKit\Support\Data\InstallationRef;

#[Api(since: '1.0')]
final readonly class FakeTenantResolver implements TenantResolver
{
    public function __construct(private InstallationRef $installation) {}

    public function current(): InstallationRef
    {
        return $this->installation;
    }
}
