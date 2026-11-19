<?php

declare(strict_types=1);

namespace SocKit\Posts\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;

/** Explicit owner transition; every execution also requires an expected revision and trusted actor.
 * Erasure/retention orchestration belongs to the purge owner, outside this API.
 */
#[Api(since: '1.0')]
final readonly class PostTransition
{
    public function __construct(public SurfaceRef $surface, public ResourceRef $resource, public string $action,
        public string $reason, public ?AudienceSpec $audience = null)
    {
        if ($resource->kind !== ResourceKind::Post || ! $resource->panel->equals($surface->panel)
            || ! in_array($action, ['publish', 'hold', 'reject', 'hide', 'restore', 'delete', 'audience'], true)
            || trim($reason) === '' || strlen($reason) > 4096 || ! mb_check_encoding($reason, 'UTF-8')
            || ($action === 'audience') !== ($audience !== null)) {
            throw new InvalidPayloadException('Invalid post transition');
        }
    }
}
