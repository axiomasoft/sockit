<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use Illuminate\Contracts\Foundation\Application;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Groups\Exceptions\GroupException;
use SocKit\Operations\OperationHandles;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Enums\TargetLifecycle;

/** Read-only receipt capability; content targeting belongs to a separate capability. */
final readonly class GroupTarget implements TargetResolver
{
    public function __construct(private Application $app) {}

    public function resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution
    {
        $handles = $this->app->make(OperationHandles::class);
        $handle = $handles->forSurface($surface);
        if ($actor != $handle->actor || $target->host->type !== 'group' || $target->incarnation->value !== 1 || $surface->host->type === 'group') {
            throw new GroupException(ProblemCode::PanelBindingMismatch);
        }
        $store = new GroupStore($this->app);
        $store->outside($handle);
        $host = $handles->resolveTarget($handle, $store->host($handle));
        $resource = new ResourceRef($surface->panel, ResourceKind::Group, $target->host->value);
        $row = $store->readable($handle, $resource, $host);
        $revision = new Revision($store->integer($row, 'revision'));

        return new TargetResolution(surface: $surface, target: $target, installation: $actor->installation, read: $host->read,
            write: AudienceVerdict::deny('audience.unknown'), lifecycle: TargetLifecycle::ReadOnly, privacyRevision: $revision,
            generation: hash('sha256', CanonicalJson::encode(['group' => $resource->toWire(), 'revision' => $revision->value, 'host' => $host->generation])));
    }
}
