<?php

declare(strict_types=1);

namespace SocKit\Posts\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;

/** No payload author/installation. publish=false stores a pending revision, never a publication.
 * Editing replaces body, audience and association refs; resource + expected revision identify intent.
 */
#[Api(since: '1.0')]
final readonly class PostCommand
{
    /** @var list<MediaReference> */
    public array $media;

    /** @param array<array-key, mixed> $media */
    public function __construct(public SurfaceRef $surface, public string $body, public ?AudienceSpec $audience = null, public bool $publish = false,
        array $media = [], public ?ResourceRef $resource = null, public ?string $reason = null)
    {
        if (strlen($body) > 1048576 || ! mb_check_encoding($body, 'UTF-8') || (trim($body) === '' && $media === []) || ! array_is_list($media) || count($media) > 32
            || ($resource !== null && ($resource->kind !== ResourceKind::Post || ! $resource->panel->equals($surface->panel)))
            || ($reason !== null && (trim($reason) === '' || strlen($reason) > 4096))) {
            throw new InvalidPayloadException('Invalid post command');
        }
        $seen = [];
        $validated = [];
        foreach ($media as $ref) {
            if (! $ref instanceof MediaReference || isset($seen[$ref->owner.':'.$ref->id])) {
                throw new InvalidPayloadException('Invalid or duplicate media reference');
            }
            $seen[$ref->owner.':'.$ref->id] = true;
            $validated[] = $ref;
        }
        $this->media = $validated;
    }
}
