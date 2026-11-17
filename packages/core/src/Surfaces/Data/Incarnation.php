<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Data;

use Packman\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;

/**
 * Positive monotonic lifecycle incarnation number (>= 1) preventing resurrected host IDs from inheriting old content.
 */
#[Api(since: '1.0')]
final readonly class Incarnation
{
    public function __construct(public int $value = 1)
    {
        if ($value < 1) {
            throw InvalidPayloadException::invalid('Incarnation must be >= 1.', ['incarnation' => $value], '/incarnation');
        }
    }

    public static function initial(): self
    {
        return new self(1);
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
}
