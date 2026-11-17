<?php

declare(strict_types=1);

namespace SocKit\Actors\Data;

use Packman\Attributes\Api;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Actors\Enums\AgeBand;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Actors\Exceptions\ActorContextMismatchException;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\InstallationRef;

/**
 * Server-derived trusted actor context. Never accepts client-supplied actor claims from command payloads.
 */
#[Api(since: '1.0')]
final readonly class ActorContext
{
    public const int MAX_GENERATION_BYTES = 64;

    public function __construct(
        public ?ActorRef $actor,
        public ActorKind $kind,
        public InstallationRef $installation,
        public AgeBand $ageBand,
        public ContextProvenance $provenance,
        public ?ActorRef $onBehalfOf = null,
        public string $generation = '0',
    ) {
        if ($kind === ActorKind::Anonymous && $actor instanceof ActorRef) {
            throw ActorContextMismatchException::invalidInvariant('Anonymous ActorContext must have null actor.', '/actor');
        }

        if ($kind !== ActorKind::Anonymous && ! $actor instanceof ActorRef) {
            throw ActorContextMismatchException::invalidInvariant('Non-anonymous ActorContext requires an ActorRef.', '/actor');
        }

        if ($kind === ActorKind::Anonymous && $ageBand !== AgeBand::Unknown) {
            throw ActorContextMismatchException::invalidInvariant('Anonymous ActorContext must have AgeBand::Unknown.', '/age_band');
        }

        if ($onBehalfOf instanceof ActorRef && $kind !== ActorKind::Service) {
            throw ActorContextMismatchException::invalidInvariant('onBehalfOf is permitted only for ActorKind::Service.', '/on_behalf_of');
        }

        if ($generation === '' || strlen($generation) > self::MAX_GENERATION_BYTES || preg_match('/[\x00-\x1F\x7F]/', $generation) === 1) {
            throw ActorContextMismatchException::invalidInvariant('ActorContext generation must be a non-empty string up to 64 bytes.', '/generation');
        }
    }

    public static function anonymous(
        InstallationRef $installation,
        ContextProvenance $provenance = ContextProvenance::Http,
        string $generation = '0',
    ): self {
        return new self(
            actor: null,
            kind: ActorKind::Anonymous,
            installation: $installation,
            ageBand: AgeBand::Unknown,
            provenance: $provenance,
            generation: $generation,
        );
    }

    public static function person(
        ActorRef $actor,
        InstallationRef $installation,
        AgeBand $ageBand = AgeBand::Adult,
        ContextProvenance $provenance = ContextProvenance::Http,
        string $generation = '1',
    ): self {
        return new self(
            actor: $actor,
            kind: ActorKind::Person,
            installation: $installation,
            ageBand: $ageBand,
            provenance: $provenance,
            generation: $generation,
        );
    }

    public function isMinorForPolicy(): bool
    {
        return $this->ageBand !== AgeBand::Adult;
    }

    public function sameActor(ActorRef $ref): bool
    {
        return $this->actor instanceof ActorRef && $this->actor->equals($ref);
    }
}
