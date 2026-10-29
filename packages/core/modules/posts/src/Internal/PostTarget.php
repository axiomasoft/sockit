<?php

declare(strict_types=1);

namespace SocKit\Posts\Internal;

use Illuminate\Contracts\Foundation\Application;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\OperationHandles;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Enums\TargetLifecycle;

/** Current read capability for receipt metadata; no body and no implicit permission to write a post target.
 * Post IDs are never reused; incarnation 1 is permanent. Lifecycle write fencing belongs to its owner.
 */
final readonly class PostTarget implements TargetResolver
{
    public function __construct(private Application $app) {}

    public function resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution
    {
        $handle = $this->app->make(OperationHandles::class)->forSurface($surface);
        if ($actor != $handle->actor || $target->incarnation->value !== 1 || $surface->host->type === 'post') {
            throw new PostException(ProblemCode::PanelBindingMismatch);
        }
        $store = new PostStore($this->app);
        $row = $store->readable($handle, new ResourceRef(panel: $surface->panel, kind: ResourceKind::Post, id: $target->host->value));

        return new TargetResolution(surface: $surface, target: $target, installation: $actor->installation,
            read: $store->audience($handle, $row), write: AudienceVerdict::deny('audience.unknown'), lifecycle: TargetLifecycle::ReadOnly,
            privacyRevision: new Revision($store->integer($row, 'privacy_revision')),
            generation: implode(':', [$store->integer($row, 'revision'), $store->integer($row, 'safety_generation'), $store->integer($row, 'consent_generation')]));
    }
}
