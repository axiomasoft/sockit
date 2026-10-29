<?php

declare(strict_types=1);

namespace SocKit\Actors\Data;

use SocKit\Actors\Enums\ActorKind;
use SocKit\Actors\Enums\AgeBand;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Actors\Exceptions\InvalidActorContextException;
use SocKit\Attributes\Api;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\InstallationRef;

#[Api(since: '1.0')]
final readonly class ActorContext
{
    public function __construct(
        public ?ActorRef $actor,
        public ActorKind $kind,
        public InstallationRef $installation,
        public AgeBand $ageBand,
        public ContextProvenance $provenance,
        public ?ActorRef $onBehalfOf = null,
        public string $generation = '0',
    ) {
        if (($kind === ActorKind::Anonymous) !== ($actor === null)) {
            throw new InvalidActorContextException('Only anonymous contexts must have no actor');
        }

        if ($kind === ActorKind::Anonymous && $ageBand !== AgeBand::Unknown) {
            throw new InvalidActorContextException('Anonymous contexts must have unknown age');
        }

        if ($onBehalfOf !== null && $kind !== ActorKind::Service) {
            throw new InvalidActorContextException('Only service actors may act on behalf of another actor');
        }

        if ($generation === '' || strlen($generation) > 64) {
            throw new InvalidActorContextException('Actor generation must contain between 1 and 64 bytes');
        }
    }

    public static function anonymous(InstallationRef $installation, ContextProvenance $provenance): self
    {
        return new self(actor: null, kind: ActorKind::Anonymous, installation: $installation, ageBand: AgeBand::Unknown, provenance: $provenance);
    }

    public function isMinorForPolicy(): bool
    {
        return $this->ageBand !== AgeBand::Adult;
    }

    public function sameActor(ActorRef $ref): bool
    {
        return $this->actor?->equals($ref) ?? false;
    }
}
