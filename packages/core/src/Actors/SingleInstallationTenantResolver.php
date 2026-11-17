<?php

declare(strict_types=1);

namespace SocKit\Actors;

use Override;
use Packman\Attributes\Api;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Support\Data\InstallationRef;

/**
 * Explicit opt-in TenantResolver for single-installation host applications.
 */
#[Api(since: '1.0')]
final readonly class SingleInstallationTenantResolver implements TenantResolver
{
    public function __construct(private string $installationId = 'default') {}

    #[Override]
    public function current(): InstallationRef
    {
        return new InstallationRef($this->installationId);
    }
}
