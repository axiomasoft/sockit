<?php

declare(strict_types=1);

namespace SocKit\Support\Data;

use SocKit\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class ActorRef
{
    public function __construct(public string $type, public string $id)
    {
        if (! preg_match('~^[a-z][a-z0-9_.-]{0,95}$~D', $type) || $id === '' || strlen($id) > 512 || preg_match('~[\x00-\x1f\x7f]~', $id)) {
            throw new InvalidPayloadException('Invalid reference');
        }
    }

    public function toWire(): string
    {
        return $this->type.':'.$this->id;
    }

    public static function fromWire(string $wire): self
    {
        $parts = explode(':', $wire, 2);
        if (count($parts) !== 2) {
            throw new InvalidPayloadException('Invalid reference wire');
        }

        return new self(type: $parts[0], id: $parts[1]);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
