<?php

declare(strict_types=1);

namespace SocKit\Operations\Data;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class DeliveryResult
{
    private function __construct(public string $status, public ?ProblemCode $problem, public string $reason)
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,119}$/D', $reason) !== 1) {
            throw new InvalidPayloadException('Invalid delivery reason code');
        }
    }

    public static function confirmed(): self
    {
        return new self(status: 'confirmed', problem: null, reason: 'outbox.confirmed');
    }

    public static function retry(ProblemCode $problem = ProblemCode::ProviderUnavailable, string $reason = 'outbox.retry'): self
    {
        return new self(status: 'retry', problem: $problem, reason: $reason);
    }

    public static function unknown(): self
    {
        return new self(status: 'unknown', problem: ProblemCode::ExternalOutcomeUnknown, reason: 'outbox.unknown');
    }

    public static function quarantine(ProblemCode $problem, string $reason): self
    {
        return new self(status: 'quarantine', problem: $problem, reason: $reason);
    }

    public function isSuccess(): bool
    {
        return $this->status === 'confirmed';
    }
}
