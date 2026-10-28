<?php

declare(strict_types=1);

namespace Acme;

use Illuminate\Database\DatabaseManager;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Contracts\TargetWriteFence;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;

final readonly class TargetRouter implements TargetResolver, TargetWriteFence
{
    public function __construct(private DatabaseManager $db) {}

    private function adapter(string $panel): EnvelopeTarget
    {
        $b = $panel === 'acme.b';

        return new EnvelopeTarget(connection: $this->db->connection($b ? 'owner_b' : 'owner_a'), schema: $b ? 'panel_b' : 'panel_a');
    }

    public function resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution
    {
        return $this->adapter($surface->panel->value)->resolve(actor: $actor, surface: $surface, target: $target);
    }

    public function recheck(OperationHandle $handle, TargetResolution $resolved): AuthorizationDecision
    {
        return $this->adapter($handle->surface->panel->value)->recheck(handle: $handle, resolved: $resolved);
    }
}
