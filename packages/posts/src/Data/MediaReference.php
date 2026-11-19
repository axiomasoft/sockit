<?php

declare(strict_types=1);

namespace SocKit\Posts\Data;

use Packman\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;

/** Opaque association reference only. Bytes and files remain with the media owner. */
#[Api(since: '1.0')]
final readonly class MediaReference
{
    public function __construct(public string $owner, public string $id)
    {
        if (! preg_match('/^[a-z][a-z0-9_.-]{0,95}$/D', $owner) || $id === '' || strlen($id) > 512 || preg_match('/[\x00-\x1f\x7f]/', $id)) {
            throw new InvalidPayloadException('Invalid media reference');
        }
    }

    /** @return array{owner: string, id: string} */
    public function toWire(): array
    {
        return ['owner' => $this->owner, 'id' => $this->id];
    }
}
