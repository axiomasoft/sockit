<?php

declare(strict_types=1);

namespace SocKit\Testing;

use Override;
use Packman\Attributes\Api;
use RuntimeException;
use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Operations\Data\OutboxEventRecord;
use SocKit\Operations\Enums\ReplayMode;

/**
 * Spy {@see OutboxConsumer} recording delivered outbox events and replay modes for test assertions.
 */
#[Api(since: '1.0')]
final class SpyOutboxConsumer implements OutboxConsumer
{
    /** @var list<array{event: OutboxEventRecord, mode: ReplayMode}> */
    private array $recorded = [];

    private bool $throwOnConsume = false;

    public function __construct(private readonly string $key = 'sockit.test.spy') {}

    public function failNext(): self
    {
        $this->throwOnConsume = true;

        return $this;
    }

    #[Override]
    public function consumerKey(): string
    {
        return $this->key;
    }

    #[Override]
    public function supports(string $eventType): bool
    {
        return str_starts_with($eventType, 'sockit.');
    }

    #[Override]
    public function consume(OutboxEventRecord $event, ReplayMode $mode): void
    {
        if ($this->throwOnConsume) {
            $this->throwOnConsume = false;

            throw new RuntimeException('Simulated outbox consumer failure.');
        }

        $this->recorded[] = ['event' => $event, 'mode' => $mode];
    }

    /** @return list<array{event: OutboxEventRecord, mode: ReplayMode}> */
    public function recorded(): array
    {
        return $this->recorded;
    }

    public function clear(): void
    {
        $this->recorded = [];
    }
}
