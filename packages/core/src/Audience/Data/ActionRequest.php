<?php

declare(strict_types=1);

namespace SocKit\Audience\Data;

use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Exceptions\InvalidAudienceException;
use SocKit\Support\Data\ResourceRef;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Authorization request evaluated by {@see ActionAuthorizer}.
 */
#[Api(since: '1.0')]
final readonly class ActionRequest
{
    public const string ACTION_PATTERN = '/\A[a-z][a-z0-9_.-]{0,119}\z/';

    public function __construct(
        public ActorContext $actor,
        public string $action,
        public SurfaceRef $surface,
        public ?ResourceRef $resource = null,
    ) {
        if (preg_match(self::ACTION_PATTERN, $action) !== 1) {
            throw InvalidAudienceException::invalidSpec("Invalid action name [{$action}].", '/action');
        }
    }
}
