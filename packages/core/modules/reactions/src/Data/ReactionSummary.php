<?php

declare(strict_types=1);

namespace SocKit\Reactions\Data;

use SocKit\Attributes\Api;
use SocKit\Errors\Enums\ProblemCode;

/** No roster or reusable authorization. A denied view contains neither totals nor own membership. */
#[Api(since: '1.0')]
final readonly class ReactionSummary
{
    /** @param array<string, int>|null $counts
     * @param list<string>|null $ownKinds */
    public function __construct(public ?array $counts, public ?array $ownKinds, public ?ProblemCode $problem = null) {}
}
