<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal\Outbox;

use Packman\Attributes\Internal;

#[Internal]
final readonly class OutboxLease
{
    public function __construct(public string $rowId, public string $eventId, public string $generation, public string $consumerId, public int $attempt, public string $token, public string $encoded, public string $digest) {}
}
