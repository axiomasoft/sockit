<?php

declare(strict_types=1);

namespace SocKit\Audience\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;
use SocKit\Audience\Enums\VerdictDecision;

/**
 * Audience evaluation verdict with stable machine reason code and policy metadata.
 */
#[Api(since: '1.0')]
final readonly class AudienceVerdict
{
    public function __construct(
        public VerdictDecision $decision,
        public string $reason,
        public string $policyVersion = '1',
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    public static function allow(string $reason, string $policyVersion = '1', ?DateTimeImmutable $expiresAt = null): self
    {
        return new self(VerdictDecision::Allow, $reason, $policyVersion, $expiresAt);
    }

    public static function allowReadOnly(string $reason, string $policyVersion = '1', ?DateTimeImmutable $expiresAt = null): self
    {
        return new self(VerdictDecision::AllowReadOnly, $reason, $policyVersion, $expiresAt);
    }

    public static function deny(string $reason, string $policyVersion = '1'): self
    {
        return new self(VerdictDecision::Deny, $reason, $policyVersion);
    }

    public function canRead(): bool
    {
        return $this->decision === VerdictDecision::Allow || $this->decision === VerdictDecision::AllowReadOnly;
    }

    public function canWrite(): bool
    {
        return $this->decision === VerdictDecision::Allow;
    }

    public function intersect(self $other): self
    {
        if ($this->decision === VerdictDecision::Deny) {
            return $this;
        }

        if ($other->decision === VerdictDecision::Deny) {
            return $other;
        }

        if ($this->decision === VerdictDecision::AllowReadOnly) {
            return $this;
        }

        return $other;
    }

    public function downgradeToReadOnly(): self
    {
        if ($this->decision === VerdictDecision::Allow) {
            return new self(VerdictDecision::AllowReadOnly, $this->reason, $this->policyVersion, $this->expiresAt);
        }

        return $this;
    }
}
