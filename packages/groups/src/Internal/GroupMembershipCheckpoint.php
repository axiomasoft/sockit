<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use SocKit\Audience\Contracts\ReadCheckpoint;

/** One statement compares the sampled group and membership after all yielding read callbacks. */
#[Internal]
final readonly class GroupMembershipCheckpoint implements ReadCheckpoint
{
    public function __construct(private Builder $query) {}

    public function isCurrent(): bool
    {
        return $this->query->exists();
    }
}
