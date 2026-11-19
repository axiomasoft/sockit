<?php

declare(strict_types=1);

namespace SocKit\Groups\Data;

use Packman\Attributes\Api;
use SocKit\Groups\Enums\GroupVisibility;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class GroupCommand
{
    public function __construct(public JoinPolicy $joinPolicy, public ?string $name = null, public ?ResourceRef $resource = null, public ?GroupVisibility $visibility = null)
    {
        if (($resource === null && ($name === null || $name === '' || strlen($name) > 512 || str_contains($name, "\0")))
            || ($resource !== null && ($name !== null || $resource->kind !== ResourceKind::Group))) {
            throw new InvalidPayloadException('Invalid group command');
        }
    }
}
