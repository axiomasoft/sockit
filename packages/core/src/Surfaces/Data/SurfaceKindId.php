<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Data;

use Packman\Attributes\Api;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Exceptions\InvalidPayloadException;
use Stringable;

/**
 * Stable dotted identifier for a surface kind (`edufy.class-feed`).
 */
#[Api(since: '1.0')]
final readonly class SurfaceKindId implements Stringable
{
    public const int MAX_BYTES = 96;

    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAX_BYTES || preg_match(PanelId::PATTERN, $value) !== 1) {
            throw InvalidPayloadException::invalid(
                'SurfaceKindId must be a dotted lowercase identifier up to 96 bytes.',
                ['kind' => $value],
                '/kind',
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

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
