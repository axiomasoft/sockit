<?php

declare(strict_types=1);

namespace Acme\Groups;

use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Support\Data\InstallationRef;

final class Tenant implements TenantResolver
{
    public string $id = 'runtime-test';

    public function current(): InstallationRef
    {
        return new InstallationRef($this->id);
    }
}
