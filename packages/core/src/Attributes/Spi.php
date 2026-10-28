<?php

declare(strict_types=1);

namespace SocKit\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
#[Api]
final readonly class Spi
{
    public function __construct(public SpiKind $kind, public string $since = '1.0') {}
}
