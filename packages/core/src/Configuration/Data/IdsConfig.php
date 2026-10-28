<?php

declare(strict_types=1);

namespace SocKit\Configuration\Data;

use SocKit\Attributes\Api;

#[Api(since: '1.0')]
final readonly class IdsConfig
{
    public function __construct(public string $strategy) {}
}
