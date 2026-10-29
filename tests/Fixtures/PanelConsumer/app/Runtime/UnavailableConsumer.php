<?php

declare(strict_types=1);

namespace Acme\Runtime;

use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Operations\Data\DeliveryResult;
use SocKit\Operations\Data\EventEnvelope;
use SocKit\Operations\Enums\ReplayMode;

final class UnavailableConsumer implements OutboxConsumer
{
    public function consume(EventEnvelope $event, ReplayMode $mode): DeliveryResult
    {
        throw new \RuntimeException('Consumer must not be resolved after disable');
    }
}
