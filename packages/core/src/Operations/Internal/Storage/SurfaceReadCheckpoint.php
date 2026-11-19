<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal\Storage;

use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use SocKit\Audience\Contracts\ReadCheckpoint;

/** The query is bound to the verified owner and generations while outside guards are running. */
#[Internal]
final readonly class SurfaceReadCheckpoint implements ReadCheckpoint
{
    public function __construct(private Builder $query) {}

    public function isCurrent(): bool
    {
        return $this->query->exists();
    }
}
