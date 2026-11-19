<?php

declare(strict_types=1);

namespace SocKit\Audience\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum Scope: string
{
    case Direct = 'direct';
    case Group = 'group';
    case Contour = 'contour';
    case Platform = 'platform';
    case Public = 'public';

    /** Coarse publication ceiling only. Never proves membership or containment. */
    public function breadth(): int
    {
        return match ($this) {
            self::Direct => 0, self::Group => 1, self::Contour => 2,
            self::Platform => 3, self::Public => 4,
        };
    }

    public function isWiderThan(self $other): bool
    {
        return $this->breadth() > $other->breadth();
    }
}
