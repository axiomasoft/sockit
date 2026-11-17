<?php

declare(strict_types=1);

namespace SocKit\Support\Data;

use Packman\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;
use Stringable;

/**
 * Lossless opaque reference to an authenticated host actor (`type:id`).
 */
#[Api(since: '1.0')]
final readonly class ActorRef implements Stringable
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if (preg_match(HostEntityRef::TYPE_PATTERN, $type) !== 1) {
            throw InvalidPayloadException::invalid(
                'ActorRef type must match ^[a-z][a-z0-9_.-]{0,95}$.',
                ['type' => $type],
                '/type',
            );
        }

        if ($id === '' || strlen($id) > HostEntityRef::MAX_VALUE_BYTES || preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
            throw InvalidPayloadException::invalid(
                'ActorRef id must be a non-empty string up to 512 bytes without control characters.',
                [],
                '/id',
            );
        }
    }

    public static function fromWire(string $wire): self
    {
        $pos = strpos($wire, ':');

        if ($pos === false) {
            throw InvalidPayloadException::invalid('ActorRef wire value must contain ":" separator.');
        }

        return new self(substr($wire, 0, $pos), substr($wire, $pos + 1));
    }

    public function toWire(): string
    {
        return $this->type.':'.$this->id;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    public function __toString(): string
    {
        return $this->toWire();
    }
}
