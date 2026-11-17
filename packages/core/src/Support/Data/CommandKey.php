<?php

declare(strict_types=1);

namespace SocKit\Support\Data;

use Packman\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;
use Stringable;

/**
 * Client/host idempotency key (`16..128` bytes, never trimmed, hashed when logged).
 */
#[Api(since: '1.0')]
final readonly class CommandKey implements Stringable
{
    public const string PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{15,127}\z/';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidPayloadException::invalid(
                'CommandKey must match ^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$ without whitespace.',
                [],
                '/command_key',
            );
        }
    }

    public static function fromWire(string $wire): self
    {
        return new self($wire);
    }

    public function toWire(): string
    {
        return $this->value;
    }

    public function hashForLog(): string
    {
        return substr(hash('sha256', $this->value), 0, 16);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
