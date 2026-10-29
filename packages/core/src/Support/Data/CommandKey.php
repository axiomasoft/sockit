<?php

declare(strict_types=1);

namespace SocKit\Support\Data;

use SocKit\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class CommandKey
{
    public function __construct(public string $value)
    {
        if (! preg_match('~^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$~D', $value)) {
            throw new InvalidPayloadException('Invalid CommandKey');
        }
    }

    public function toWire(): string
    {
        return $this->value;
    }

    public static function fromWire(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function hashForLog(): string
    {
        return substr(hash('sha256', $this->value), 0, 16);
    }
}
