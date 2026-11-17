<?php

declare(strict_types=1);

namespace SocKit\Support\Data;

use Packman\Attributes\Api;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Exceptions\InvalidPayloadException;
use Stringable;

/**
 * SHA-256 digest of `commandType + "\n" + CanonicalJson::encode($payload)`.
 */
#[Api(since: '1.0')]
final readonly class PayloadDigest implements Stringable
{
    public const string PATTERN = '/\Asha256:[0-9a-f]{64}\z/';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidPayloadException::invalid('PayloadDigest must match ^sha256:[0-9a-f]{64}$.');
        }
    }

    /** @param array<array-key, mixed> $payload */
    public static function of(string $commandType, array $payload): self
    {
        if (preg_match(CommandNamespace::COMMAND_TYPE_PATTERN, $commandType) !== 1) {
            throw InvalidPayloadException::invalid('Invalid commandType for PayloadDigest.', ['command_type' => $commandType]);
        }

        $canonical = CanonicalJson::encode($payload);

        return new self('sha256:'.hash('sha256', $commandType."\n".$canonical));
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
        return hash_equals($this->value, $other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
