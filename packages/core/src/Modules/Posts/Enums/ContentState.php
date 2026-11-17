<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Enums;

use Packman\Attributes\Api;

/**
 * Monotonic post lifecycle state supporting draft promotion, safety holds, moderation hiding, tombstoned deletion, and erasure.
 */
#[Api(since: '1.0')]
enum ContentState: string
{
    case Draft = 'draft';
    case Held = 'held';
    case Published = 'published';
    case Hidden = 'hidden';
    case Deleted = 'deleted';
    case Erased = 'erased';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Draft, self::Held, self::Published, self::Deleted, self::Erased], true),
            self::Held => in_array($target, [self::Held, self::Published, self::Hidden, self::Deleted, self::Erased], true),
            self::Published => in_array($target, [self::Published, self::Hidden, self::Deleted, self::Erased], true),
            self::Hidden => in_array($target, [self::Published, self::Hidden, self::Deleted, self::Erased], true),
            self::Deleted => in_array($target, [self::Deleted, self::Erased], true),
            self::Erased => false,
        };
    }

    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Held, self::Published, self::Hidden], true);
    }
}
