<?php

declare(strict_types=1);

namespace SocKit\Support\Data;

use Packman\Attributes\Api;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class PayloadDigest
{
    public function __construct(public string $value)
    {
        if (! preg_match('~^sha256:[0-9a-f]{64}$~D', $value)) {
            throw new InvalidPayloadException('Invalid PayloadDigest');
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

    /** @param array<mixed> $payload */
    public static function of(string $commandType, array $payload): self
    {
        return new self('sha256:'.hash('sha256', $commandType."\n".CanonicalJson::encode($payload)));
    }
}
