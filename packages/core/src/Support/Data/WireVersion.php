<?php

declare(strict_types=1);

namespace SocKit\Support\Data;

use Packman\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;
use Stringable;

/**
 * Semantic wire-contract version (`major.minor`).
 */
#[Api(since: '1.0')]
final readonly class WireVersion implements Stringable
{
    public function __construct(
        public int $major,
        public int $minor = 0,
    ) {
        if ($major < 1 || $minor < 0) {
            throw InvalidPayloadException::invalid('WireVersion requires major >= 1 and minor >= 0.');
        }
    }

    public static function v1(): self
    {
        return new self(1, 0);
    }

    public static function fromWire(string $wire): self
    {
        if (preg_match('/\A([1-9]\d*)\.(0|[1-9]\d*)\z/', $wire, $matches) !== 1) {
            throw InvalidPayloadException::unsupportedWireVersion($wire);
        }

        return new self((int) $matches[1], (int) $matches[2]);
    }

    public function supports(self $other): bool
    {
        return $this->major === $other->major && $other->minor <= $this->minor;
    }

    public function toWire(): string
    {
        return $this->major.'.'.$this->minor;
    }

    public function __toString(): string
    {
        return $this->toWire();
    }
}
