<?php

declare(strict_types=1);

namespace Acme;

use Illuminate\Database\DatabaseManager;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Actors\Enums\AgeBand;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Actors\Exceptions\UnresolvedActorException;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\InstallationRef;

final readonly class Actor implements ActorContextResolver
{
    public function __construct(private DatabaseManager $db) {}

    public function current(): ActorContext
    {
        return $this->reResolve(new ActorRef(type: 'user', id: '7'), ContextProvenance::Direct);
    }

    public function reResolve(ActorRef $actor, ContextProvenance $provenance): ActorContext
    {
        if ($actor->type !== 'user' || $actor->id !== '7' || $this->db->connection('owner_a')->table('panel_a.envelope_authority')->value('allowed') !== true) {
            throw new UnresolvedActorException('Actor unavailable');
        }

        return new ActorContext(actor: $actor, kind: ActorKind::Person, installation: new InstallationRef('runtime-test'), ageBand: AgeBand::Adult, provenance: $provenance, generation: '1');
    }
}
