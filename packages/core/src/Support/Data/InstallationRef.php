<?php

declare(strict_types=1);

namespace SocKit\Support\Data;

use Packman\Attributes\Api;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Support\Exceptions\InvalidPayloadException;
use Stringable;

/**
 * Opaque installation/tenant boundary identifier supplied by the host {@see TenantResolver}.
 */
#[Api(since: '1.0')]
final readonly class InstallationRef implements Stringable
{
    public const string PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/';

    public function __construct(public string $id)
    {
        if (preg_match(self::PATTERN, $id) !== 1) {
            throw InvalidPayloadException::invalid(
                'InstallationRef id must match ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$.',
                ['id' => $id],
                '/installation',
            );
        }
    }

    public static function fromWire(string $wire): self
    {
        return new self($wire);
    }

    public function toWire(): string
    {
        return $this->id;
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id;
    }

    public function __toString(): string
    {
        return $this->id;
    }
}
