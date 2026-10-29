<?php

declare(strict_types=1);

namespace SocKit\Reactions\Internal;

use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;

/** Persistence identity only, never authorization. Every digest lookup must verify the full row. */
final readonly class ReactionIdentity
{
    public function __construct(private InstallationRef $installation, private SurfaceRef $surface, private TargetRef $target, private string $kind, private ?ActorRef $actor = null)
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $kind) !== 1) {
            throw new InvalidPayloadException('Invalid reaction kind');
        }
    }

    /** Count identity omits actor; membership identity requires it.
     * @return array<string, string|int> */
    public function columns(): array
    {
        $columns = ['installation' => $this->installation->id, 'panel' => $this->surface->panel->value,
            'surface_kind' => $this->surface->kind->value, 'host_type' => $this->surface->host->type, 'host_key' => $this->surface->host->value,
            'surface_incarnation' => $this->surface->incarnation->value, 'target_type' => $this->target->host->type,
            'target_key' => $this->target->host->value, 'target_incarnation' => $this->target->incarnation->value, 'reaction_kind' => $this->kind];
        if ($this->actor !== null) {
            $columns['actor_ref'] = $this->actor->toWire();
        }

        return $columns;
    }

    public function digest(): string
    {
        return hash('sha256', json_encode($this->columns(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Use after SELECT (including unique-conflict recovery), before reading/updating an existing row.
     * @param array<string, mixed> $row */
    public function verify(array $row): void
    {
        foreach ($this->columns() as $key => $value) {
            if (! array_key_exists($key, $row) || $row[$key] !== $value) {
                throw new InvalidPayloadException('Reaction identity digest collision or corrupt row');
            }
        }
        if (($row['identity_digest'] ?? null) !== $this->digest() || array_key_exists('actor_ref', $row) !== ($this->actor !== null)) {
            throw new InvalidPayloadException('Reaction identity digest mismatch');
        }
    }
}
