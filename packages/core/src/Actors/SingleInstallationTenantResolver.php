<?php

declare(strict_types=1);

namespace SocKit\Actors;

use Packman\Attributes\Api;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Support\Data\InstallationRef;

/** Explicit opt-in for single-installation hosts; never an implicit resolver binding. */
#[Api(since: '1.0')]
final readonly class SingleInstallationTenantResolver implements TenantResolver
{
    public function current(): InstallationRef
    {
        return new InstallationRef('default');
    }
}
