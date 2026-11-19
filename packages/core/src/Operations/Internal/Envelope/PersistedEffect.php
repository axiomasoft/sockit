<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal\Envelope;

use Packman\Attributes\Internal;
use SocKit\Operations\Data\MutationEffect;

/** Per-call state shared by fixed persist and record stages, never a container singleton. */
#[Internal]
final class PersistedEffect
{
    public ?MutationEffect $value = null;
}
