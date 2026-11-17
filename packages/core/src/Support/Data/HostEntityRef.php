<?php

declare(strict_types=1);

namespace SocKit\Support\Data;

use Packman\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;
use Stringable;

/**
 * Lossless opaque reference to a host domain entity (`type:value`).
 */
#[Api(since: '1.0')]
final readonly class HostEntityRef implements Stringable
{
    public const string TYPE_PATTERN = '/\A[a-z][a-z0-9_.-]{0,95}\z/';

    public const int MAX_VALUE_BYTES = 512;

    public function __construct(
        public string $type,
        public string $value,
    ) {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw InvalidPayloadException::invalid(
                'HostEntityRef type must match ^[a-z][a-z0-9_.-]{0,95}$.',
                ['type' => $type],
                '/type',
            );
        }

        if ($value === '' || strlen($value) > self::MAX_VALUE_BYTES || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw InvalidPayloadException::invalid(
                'HostEntityRef value must be a non-empty string up to 512 bytes without control characters.',
                [],
                '/value',
            );
        }
    }

    public static function fromWire(string $wire): self
    {
        $pos = strpos($wire, ':');

        if ($pos === false) {
            throw InvalidPayloadException::invalid('HostEntityRef wire value must contain ":" separator.');
        }

        return new self(substr($wire, 0, $pos), substr($wire, $pos + 1));
    }

    public function toWire(): string
    {
        return $this->type.':'.$this->value;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->toWire();
    }
}
