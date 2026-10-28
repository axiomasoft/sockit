<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Operations\Data\DeliveryResult;
use SocKit\Operations\Data\EventEnvelope;
use SocKit\Operations\Enums\ReplayMode;

final class SwapInboxConsumer implements OutboxConsumer
{
    public int $calls = 0;

    public function __construct(private readonly SqlInboxConsumer $delegate) {}

    public function consume(EventEnvelope $event, ReplayMode $mode): DeliveryResult
    {
        $this->calls++;

        return $this->delegate->consume(event: $event, mode: $mode);
    }
}
