<?php

declare(strict_types=1);

namespace SocKit\Posts\Data;

use SocKit\Attributes\Api;
use SocKit\Operations\Data\OperationResult;
use SocKit\Posts\Enums\PublicationState;

/** Confirmed operation acknowledges storage. Only isPublished confirms publication at that revision. */
#[Api(since: '1.0')]
final readonly class PostResult
{
    public function __construct(public OperationResult $operation, public ?PublicationState $publication) {}

    public function isPublished(): bool
    {
        return $this->operation->isSuccess() && $this->publication === PublicationState::Published;
    }
}
