<?php

declare(strict_types=1);

namespace SocKit\Configuration;

use Packman\Attributes\Api;

/**
 * Compiled section DTO returned by {@see SockitConfigSection::compile()}.
 */
#[Api(since: '1.0')]
final readonly class SockitSectionSettings
{
    public function __construct(public SockitOptions $options) {}
}
