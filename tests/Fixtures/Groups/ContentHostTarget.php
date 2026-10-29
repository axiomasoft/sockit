<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Groups;

use Illuminate\Database\Connection;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Contracts\TargetWriteFence;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Tests\Fixtures\Runtime\EnvelopeTarget;

/** Host-owned same-PG target: shared reader fence lets membership contention reach the group row.
 * Host revocation UPDATE conflicts with this shared lock until the owning operation commits.
 */
final readonly class ContentHostTarget implements TargetResolver, TargetWriteFence
{
    public function __construct(private Connection $connection, private EnvelopeTarget $host) {}

    public function resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution
    {
        return $this->host->resolve($actor, $surface, $target);
    }

    public function recheck(OperationHandle $handle, TargetResolution $resolved): AuthorizationDecision
    {
        if ($this->host->unproven || $this->connection->transactionLevel() !== 1) {
            return AuthorizationDecision::unknown('fixture.unproven_host');
        }
        $row = $this->connection->table('panel_a.envelope_targets')->where('id', $resolved->target->host->value)->sharedLock()->first();
        if ($row === null || $row->generation !== $resolved->generation || $row->privacy !== $resolved->privacyRevision->value
            || $row->incarnation !== $resolved->target->incarnation->value || $row->visible !== true || $row->held !== false || $row->lifecycle !== 'live') {
            return AuthorizationDecision::unknown('fixture.host_changed');
        }

        return AuthorizationDecision::allow('fixture.shared_host_fence');
    }
}
