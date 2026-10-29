<?php

declare(strict_types=1);

namespace SocKit\Audience\Data;

use SocKit\Actors\Data\ActorContext;
use SocKit\Attributes\Api;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;

#[Api(since: '1.0')]
final readonly class ActionRequest
{
    public function __construct(public ActorContext $actor, public string $action, public SurfaceRef $surface, public ?ResourceRef $resource = null)
    {
        if (! preg_match('~^[a-z][a-z0-9_.-]{0,119}$~D', $action) || ($resource !== null && ! $resource->panel->equals($surface->panel))) {
            throw new InvalidPayloadException('Invalid action or resource panel mismatch');
        }
    }
}
