<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Panels\PanelDefinitions;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\TargetWriteFence;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;

/** A test host owns its table/SQL. This fixture is neither a production adapter nor a social module. */
final class LocalTargetAdapter extends TargetAdapter implements TargetWriteFence
{
    public int $fences = 0;

    public bool $fenceFailure = false;

    public function __construct(private readonly ConnectionResolverInterface $connections, private readonly PanelDefinitions $panels) {}

    public function resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution
    {
        $connection = $this->connection($surface);
        if ($connection->transactionLevel() !== 0) {
            throw new \LogicException('Remote stage called under lock');
        }
        $sample = parent::resolve(actor: $actor, surface: $surface, target: $target);
        $generation = $connection->table('panel_a.host_targets')->where('id', $target->host->value)->value('generation');
        if (! is_string($generation)) {
            throw new \RuntimeException('Host target unavailable');
        }

        return new TargetResolution(surface: $surface, target: $target, installation: $actor->installation, read: $sample->read, write: $sample->write,
            lifecycle: $sample->lifecycle, privacyRevision: new Revision(1), generation: $generation);
    }

    public function recheck(OperationHandle $handle, TargetResolution $resolved): AuthorizationDecision
    {
        $this->fences++;
        if ($this->fenceFailure) {
            throw new \RuntimeException('Local adapter failure');
        }
        $connection = $this->connection($handle->surface);
        if ($connection->transactionLevel() !== 1) {
            return AuthorizationDecision::unknown('fixture.owner_transaction_required');
        }
        $row = $connection->table('panel_a.host_targets')->where('id', $resolved->target->host->value)->lockForUpdate()->first();
        if ($row === null || ! isset($row->generation, $row->incarnation, $row->visible, $row->privacy_revision) || $row->generation !== $resolved->generation || $row->incarnation !== $resolved->target->incarnation->value || $row->visible !== true || $row->privacy_revision !== $resolved->privacyRevision->value) {
            return AuthorizationDecision::unknown('fixture.generation_changed');
        }

        return AuthorizationDecision::allow('fixture.local_fence_held');
    }

    private function connection(SurfaceRef $surface): Connection
    {
        $connection = $this->connections->connection($this->panels->get($surface->panel)->storage->connection);
        if (! $connection instanceof Connection) {
            throw new \LogicException('Expected fixture connection');
        }

        return $connection;
    }
}
