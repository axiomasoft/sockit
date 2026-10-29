<?php

declare(strict_types=1);

namespace Acme\Social;

use Illuminate\Database\Connection;
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

/** Consumer owns these host rows; social modules only receive public resolutions/fences. */
final readonly class Target implements TargetResolver, TargetWriteFence
{
    public function __construct(private DatabaseManager $db) {}

    private function connection(SurfaceRef $surface): Connection
    {
        return $this->db->connection($surface->panel->value === 'acme.product' ? 'owner_b' : 'owner_a');
    }

    public function resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution
    {
        $connection = $this->connection($surface);
        if ($connection->transactionLevel() !== 0) {
            throw new \LogicException('Host resolution under owner lock');
        }
        $row = $connection->table('public.p48_targets')->where('type', $target->host->type)->where('id', $target->host->value)->first();
        $allowed = $row !== null && $row->incarnation === $target->incarnation->value && $row->visible === true;
        $verdict = $allowed ? AudienceVerdict::allow('audience.allowed.platform') : AudienceVerdict::deny('host.unknown');

        return new TargetResolution(surface: $surface, target: $target, installation: $actor->installation, read: $verdict, write: $verdict,
            lifecycle: $allowed ? TargetLifecycle::Live : TargetLifecycle::Unknown, privacyRevision: new Revision(1), generation: $row !== null && is_string($row->generation) ? $row->generation : 'unknown');
    }

    public function recheck(OperationHandle $handle, TargetResolution $resolved): AuthorizationDecision
    {
        $connection = $this->connection($handle->surface);
        if ($connection->transactionLevel() !== 1 || $connection->getName() !== $handle->panel->storage->connection) {
            return AuthorizationDecision::unknown('host.fence_unproven');
        }
        $row = $connection->table('public.p48_targets')->where('type', $resolved->target->host->type)->where('id', $resolved->target->host->value)->lockForUpdate()->first();
        if ($row === null || $row->incarnation !== $resolved->target->incarnation->value || $row->visible !== true || $row->generation !== $resolved->generation) {
            return AuthorizationDecision::unknown('host.fence_changed');
        }

        return AuthorizationDecision::allow('host.fenced');
    }
}
