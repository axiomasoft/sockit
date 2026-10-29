<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

final class OtherPostSurface extends PostSurface
{
    public function __construct()
    {
        parent::__construct('b');
    }
}
