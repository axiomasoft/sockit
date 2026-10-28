<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Modules;

use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Support\Data\InstallationRef;

final class StatefulTenantResolver implements TenantResolver
{
    public string $installation = 'one';

    public function current(): InstallationRef
    {
        return new InstallationRef($this->installation);
    }
}
