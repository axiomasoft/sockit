<?php

declare(strict_types=1);

namespace SocKit\Audience\Data;

use Packman\Attributes\Api;
use SocKit\Support\CanonicalJson;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * External contour and management-surface admissions pre-sampled outside SQL transactions
 * so transactional group locking can re-verify local state without calling external SPIs under locks.
 */
#[Api(since: '1.0')]
final readonly class TransactionAudienceSnapshot
{
    /**
     * @param  array<string, AudienceVerdict>  $contours
     * @param  array<string, AudienceVerdict>  $surfaceAdmissions
     */
    public function __construct(
        public array $contours = [],
        public array $surfaceAdmissions = [],
    ) {}

    public static function surfaceKey(SurfaceRef $surface): string
    {
        return CanonicalJson::encode($surface->toWire());
    }
}
