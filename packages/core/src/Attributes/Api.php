<?php

declare(strict_types=1);

namespace SocKit\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
#[Api]
final readonly class Api
{
    public function __construct(public string $since = '1.0') {}
}
