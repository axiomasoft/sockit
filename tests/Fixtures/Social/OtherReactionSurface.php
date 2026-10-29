<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

final class OtherReactionSurface extends ReactionSurface
{
    public function __construct()
    {
        parent::__construct('b');
    }
}
