<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use Illuminate\Database\Connection;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Actors\Enums\AgeBand;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Actors\Exceptions\UnresolvedActorException;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\InstallationRef;

final class WorkerActorResolver implements ActorContextResolver
{
    public int $resolutions = 0;

    public function __construct(private readonly Connection $connection, private readonly string $schema) {}

    public function current(): ActorContext
    {
        return RuntimeScenario::actor('caller');
    }

    public function reResolve(ActorRef $actor, ContextProvenance $provenance): ActorContext
    {
        $this->resolutions++;
        if ($this->connection->transactionLevel() !== 0) {
            throw new \LogicException('Actor resolution under lock');
        }
        $row = $this->connection->table($this->schema.'.worker_actors')->where('id', $actor->id)->first();
        if ($actor->type !== 'user' || $row === null || ! is_string($row->generation) || ! is_string($row->age)) {
            throw new UnresolvedActorException('Current worker actor missing');
        }

        return new ActorContext(actor: $actor, kind: ActorKind::Person, installation: new InstallationRef('runtime-test'), ageBand: AgeBand::from($row->age), provenance: $provenance, generation: $row->generation);
    }
}
