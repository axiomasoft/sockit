<?php

declare(strict_types=1);

namespace SocKit\Operations\Data;

use SocKit\Attributes\Api;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Enums\TargetLifecycle;

#[Api(since: '1.0')]
final readonly class MutationSnapshot
{
    public function __construct(public ?ResourceRef $resource, public ?Revision $revision, public TargetLifecycle $lifecycle, public string $generation, public AuthorizationDecision $safety)
    {
        if (($resource === null) !== ($revision === null) || $generation === '' || strlen($generation) > 128) {
            throw new InvalidPayloadException('Invalid mutation snapshot');
        }
    }
}
