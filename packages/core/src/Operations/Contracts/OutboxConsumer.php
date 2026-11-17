<?php

declare(strict_types=1);

namespace SocKit\Operations\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Operations\Data\OutboxEventRecord;
use SocKit\Operations\Enums\ReplayMode;

/**
 * Typed event-sink slot contract for idempotent transactional outbox delivery and projection rebuilds.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface OutboxConsumer
{
    public function consumerKey(): string;

    public function supports(string $eventType): bool;

    public function consume(OutboxEventRecord $event, ReplayMode $mode): void;
}
