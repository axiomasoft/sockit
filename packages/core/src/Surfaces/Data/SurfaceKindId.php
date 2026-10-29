<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Data;

use SocKit\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class SurfaceKindId
{
    public function __construct(public string $value)
    {
        if (! preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:-[a-z0-9]+)*)+$~D', $value) || strlen($value) > 96) {
            throw new InvalidPayloadException('Invalid SurfaceKindId');
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
}
