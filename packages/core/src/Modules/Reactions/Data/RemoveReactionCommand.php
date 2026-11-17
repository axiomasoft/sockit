<?php

declare(strict_types=1);

namespace SocKit\Modules\Reactions\Data;

use Packman\Attributes\Api;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Command removing an actor's reaction from a panel surface target (actor derived server-side).
 */
#[Api(since: '1.0')]
final readonly class RemoveReactionCommand
{
    public function __construct(
        public CommandKey $key,
        public SurfaceRef $surface,
        public HostEntityRef $target,
        public string $reactionType,
        public string $integration = 'sockit.reactions',
    ) {
        if (preg_match(AddReactionCommand::TYPE_PATTERN, $reactionType) !== 1) {
            throw InvalidPayloadException::invalid(
                "Reaction type [{$reactionType}] must match ^[a-z][a-z0-9_-]{0,63}$.",
                ['reaction_type' => $reactionType],
                '/reaction_type',
            );
        }
    }

    /** @return array{surface: array{panel: string, kind: string, host: string, incarnation: int}, target: string, reaction_type: string} */
    public function toDigestPayload(): array
    {
        return [
            'surface' => $this->surface->toWire(),
            'target' => $this->target->toWire(),
            'reaction_type' => $this->reactionType,
        ];
    }
}
