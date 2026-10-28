<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Data;

use DateTimeImmutable;
use SocKit\Attributes\Api;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Enums\SurfaceLifecycle;

/** Informational snapshot, never authorization. Source generations are positive monotonic owner sequence numbers.
 * Local policy generation advances independently. Archived is always read-only; Deleted cannot be restored.
 * Internal storage primitives are consumed by the guarded operation envelope, not public lifecycle commands.
 */
#[Api(since: '1.0')]
final readonly class SurfaceInstance
{
    public function __construct(
        public string $id,
        public InstallationRef $installation,
        public SurfaceRef $ref,
        public SurfaceLifecycle $lifecycle,
        public Revision $revision,
        public Revision $sourceGeneration,
        public Revision $privacyGeneration,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt = null,
    ) {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)
            || ($lifecycle === SurfaceLifecycle::Deleted) !== ($deletedAt !== null)
            || $createdAt > $updatedAt || ($deletedAt !== null && $deletedAt != $updatedAt)
            || $createdAt->getOffset() !== 0 || $updatedAt->getOffset() !== 0 || ($deletedAt !== null && $deletedAt->getOffset() !== 0)) {
            throw new InvalidPayloadException('Invalid surface instance');
        }
    }

    public function resource(): ResourceRef
    {
        return new ResourceRef(panel: $this->ref->panel, kind: ResourceKind::SurfaceInstance, id: $this->id);
    }
}
