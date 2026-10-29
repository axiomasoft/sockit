<?php

declare(strict_types=1);

namespace SocKit\Support\Data;

use SocKit\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class Revision
{
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw new InvalidPayloadException('Invalid Revision');
        }
    }

    public function next(): self
    {
        if ($this->value === PHP_INT_MAX) {
            throw new InvalidPayloadException('Value overflow');
        }

        return new self($this->value + 1);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isAfter(self $other): bool
    {
        return $this->value > $other->value;
    }

    public function toWire(): int
    {
        return $this->value;
    }

    public static function fromWire(int $value): self
    {
        return new self($value);
    }
}
