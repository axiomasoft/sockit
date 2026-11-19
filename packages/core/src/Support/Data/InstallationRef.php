<?php

declare(strict_types=1);

namespace SocKit\Support\Data;

use Packman\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class InstallationRef
{
    public function __construct(public string $id)
    {
        if (! preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$~D', $id)) {
            throw new InvalidPayloadException('Invalid InstallationRef');
        }
    }

    public function toWire(): string
    {
        return $this->id;
    }

    public static function fromWire(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id;
    }
}
