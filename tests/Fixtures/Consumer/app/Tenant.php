<?php

declare(strict_types=1);

namespace Acme;

use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Support\Data\InstallationRef;

final class Tenant implements TenantResolver
{
    public function current(): InstallationRef
    {
        return new InstallationRef('runtime-test');
    }
}
