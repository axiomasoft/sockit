<?php

declare(strict_types=1);

namespace Acme\Groups;

use Illuminate\Database\DatabaseManager;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Contracts\TargetWriteFence;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Enums\TargetLifecycle;

final readonly class Target implements TargetResolver, TargetWriteFence
{
    public function __construct(private DatabaseManager $db) {}

    public function resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution
    {
        $connection = $this->db->connection('owner_a');
        if ($connection->transactionLevel() !== 0) {
            throw new \LogicException('Target resolved under lock');
        }
        $row = $connection->table('public.p48_targets')->where('type', $target->host->type)->where('id', $target->host->value)->first();
        $ok = $row !== null && $row->incarnation === $target->incarnation->value && $row->visible === true;
        $verdict = $ok ? AudienceVerdict::allow('audience.allowed.platform') : AudienceVerdict::deny('host.unknown');

        return new TargetResolution($surface, $target, $actor->installation, $verdict, $verdict, $ok ? TargetLifecycle::Live : TargetLifecycle::Unknown, new Revision(1), $row !== null && is_string($row->generation) ? $row->generation : 'unknown');
    }

    public function recheck(OperationHandle $handle, TargetResolution $resolved): AuthorizationDecision
    {
        $connection = $this->db->connection('owner_a');
        if ($connection->transactionLevel() !== 1 || $handle->panel->storage->connection !== 'owner_a') {
            return AuthorizationDecision::unknown('host.no_fence');
        }
        $row = $connection->table('public.p48_targets')->where('type', $resolved->target->host->type)->where('id', $resolved->target->host->value)->sharedLock()->first();

        return $row !== null && $row->visible === true && $row->generation === $resolved->generation && $row->incarnation === $resolved->target->incarnation->value
            ? AuthorizationDecision::allow('host.fenced') : AuthorizationDecision::unknown('host.changed');
    }
}
