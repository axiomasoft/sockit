<?php

declare(strict_types=1);

namespace SocKit\Operations\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;
use SocKit\Panels\Data\PanelId;

/**
 * Immutable minimal transactional outbox event envelope (never carries bodies or recipient lists).
 */
#[Api(since: '1.0')]
final readonly class OutboxEventRecord
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public PanelId $panel,
        public string $aggregateType,
        public string $aggregateId,
        public int $aggregateRevision,
        public string $eventType,
        public int $eventSchemaVersion,
        public string $payloadDigest,
        public array $payload,
        public DateTimeImmutable $occurredAt,
        public int $attempts = 0,
        public ?DateTimeImmutable $deliveredAt = null,
    ) {}
}
