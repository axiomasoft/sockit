<?php

declare(strict_types=1);

namespace SocKit\Operations\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Operations\Data\DeliveryResult;
use SocKit\Operations\Data\EventEnvelope;
use SocKit\Operations\Enums\ReplayMode;

/** Called outside owner transactions after current host/access/lifecycle checks, once per lease attempt.
 * At-least-once: a crash after consume and before ACK repeats the complete identity and digest.
 * Own inbox UNIQUE(consumer,installation,panel,source_generation,event_id), digest and local effect
 * must commit together on the consumer connection. Conflicting digest must quarantine, never succeed.
 * ProjectionOnly permits idempotent local projections only: no delivery/network/notification effects,
 * and must not suppress a later Delivery via its delivery inbox. Unknown/errors never confirm delivery.
 * Bounded IO/timeouts are the host's responsibility; the relay is the sole retry owner. No actor-bearing
 * singleton or cached authorization. No batch capability. This implement SPI evolves additively by capability.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface OutboxConsumer
{
    public function consume(EventEnvelope $event, ReplayMode $mode): DeliveryResult;
}
