<?php

declare(strict_types=1);

namespace SocKit\Audience\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Enums\AuthorizationStatus;
use SocKit\Audience\Exceptions\AuthorizationDeniedException;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class AuthorizationDecision
{
    private function __construct(public AuthorizationStatus $status, public string $reason)
    {
        if ($reason === '') {
            throw new InvalidPayloadException('Authorization reason is required');
        }
    }

    public static function allow(string $reason): self
    {
        return new self(status: AuthorizationStatus::Allow, reason: $reason);
    }

    public static function deny(string $reason): self
    {
        return new self(status: AuthorizationStatus::Deny, reason: $reason);
    }

    public static function unknown(string $reason): self
    {
        return new self(status: AuthorizationStatus::Unknown, reason: $reason);
    }

    public function isAllowed(): bool
    {
        return $this->status === AuthorizationStatus::Allow;
    }

    public function requireAllowed(): void
    {
        if (! $this->isAllowed()) {
            throw new AuthorizationDeniedException(status: $this->status, reason: $this->reason);
        }
    }
}
