<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Modules\Posts\Data\EditPostCommand;
use SocKit\Modules\Posts\Data\PostView;
use SocKit\Modules\Posts\Data\PublishPostCommand;
use SocKit\Modules\Posts\Data\TransitionPostStateCommand;
use SocKit\Operations\Data\OperationResult;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Capability contract for panel-isolated Posts write and single-post authorized read operations.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface PostService
{
    public function publish(PublishPostCommand $command): OperationResult;

    public function edit(EditPostCommand $command): OperationResult;

    public function transition(TransitionPostStateCommand $command): OperationResult;

    public function get(SurfaceRef $surface, string $postId): PostView;
}
