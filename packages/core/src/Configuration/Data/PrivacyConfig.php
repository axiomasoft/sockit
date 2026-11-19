<?php

declare(strict_types=1);

namespace SocKit\Configuration\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Data\PrivacyFloor;

#[Api(since: '1.0')]
final readonly class PrivacyConfig
{
    public function __construct(public PrivacyFloor $floor) {}
}
