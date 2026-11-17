<?php

declare(strict_types=1);

namespace SocKit\Support\Data;

use Packman\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;

/**
 * Positive monotonic optimistic-concurrency revision counter (>= 1).
 */
#[Api(since: '1.0')]
final readonly class Revision
{
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw InvalidPayloadException::invalid('Revision must be >= 1.', ['revision' => $value], '/revision');
        }
    }

    public static function fromWire(int $wire): self
    {
        return new self($wire);
    }

    public function toWire(): int
    {
        return $this->value;
    }

    public function next(): self
    {
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
}
