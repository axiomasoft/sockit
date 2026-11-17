<?php

declare(strict_types=1);

namespace SocKit\Testing;

use Override;
use Packman\Attributes\Api;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Support\Data\InstallationRef;

/**
 * Mutable test double for {@see TenantResolver}.
 */
#[Api(since: '1.0')]
final class FakeTenantResolver implements TenantResolver
{
    private InstallationRef $installation;

    public function __construct(?InstallationRef $installation = null)
    {
        $this->installation = $installation ?? new InstallationRef('inst-1');
    }

    public function switchTo(InstallationRef|string $installation): self
    {
        $this->installation = is_string($installation) ? new InstallationRef($installation) : $installation;

        return $this;
    }

    #[Override]
    public function current(): InstallationRef
    {
        return $this->installation;
    }
}
