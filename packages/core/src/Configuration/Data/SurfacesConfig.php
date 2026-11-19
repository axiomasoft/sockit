<?php

declare(strict_types=1);

namespace SocKit\Configuration\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class SurfacesConfig
{
    /** @param list<class-string> $providers */
    public function __construct(public array $providers) {}
}
