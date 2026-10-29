<?php

declare(strict_types=1);

namespace SocKit\Support\Data;

use SocKit\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Support\Exceptions\UnsupportedWireVersionException;

#[Api(since: '1.0')]
final readonly class WireVersion
{
    public function __construct(public int $major, public int $minor)
    {
        if ($major < 1 || $minor < 0) {
            throw new InvalidPayloadException('Invalid wire version');
        }
    }

    public function supports(self $other): bool
    {
        return $this->major === $other->major && $other->minor <= $this->minor;
    }

    public function requireSupported(self $other): void
    {
        if (! $this->supports($other)) {
            throw new UnsupportedWireVersionException('Unsupported wire version');
        }
    }

    public function toWire(): string
    {
        return $this->major.'.'.$this->minor;
    }

    public static function fromWire(string $wire): self
    {
        if (! preg_match('~^([1-9][0-9]*)\.(0|[1-9][0-9]*)$~D', $wire, $m) || (string) (int) $m[1] !== $m[1] || (string) (int) $m[2] !== $m[2]) {
            throw new InvalidPayloadException('Invalid wire version');
        }

        return new self(major: (int) $m[1], minor: (int) $m[2]);
    }
}
