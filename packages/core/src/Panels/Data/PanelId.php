<?php

declare(strict_types=1);

namespace SocKit\Panels\Data;

use Packman\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;
use Stringable;

/**
 * Stable dotted identifier for a SocKit storage-boundary panel (`edufy.community`).
 */
#[Api(since: '1.0')]
final readonly class PanelId implements Stringable
{
    public const string PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:-[a-z0-9]+)*)+\z/';

    public const int MAX_BYTES = 96;

    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAX_BYTES || preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidPayloadException::invalid(
                'PanelId must be a dotted lowercase identifier up to 96 bytes.',
                ['panel_id' => $value],
                '/panel',
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
