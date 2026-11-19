<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;

/** One group per management surface incarnation. Identity does not grant admission. */
final readonly class GroupIdentity
{
    public function __construct(private InstallationRef $installation, private SurfaceRef $surface) {}

    /** @return array<string, string|int> */
    public function columns(): array
    {
        return ['installation' => $this->installation->id, 'panel' => $this->surface->panel->value,
            'surface_kind' => $this->surface->kind->value, 'host_type' => $this->surface->host->type,
            'host_key' => $this->surface->host->value, 'surface_incarnation' => $this->surface->incarnation->value];
    }

    public function digest(): string
    {
        return hash('sha256', json_encode($this->columns(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Required after digest lookup or unique-conflict recovery, before disclosing or mutating state.
     * @param array<string, mixed> $row */
    public function verify(array $row): void
    {
        foreach ($this->columns() as $key => $value) {
            if (! array_key_exists($key, $row) || $row[$key] !== $value) {
                throw new InvalidPayloadException('Group identity digest collision or corrupt row');
            }
        }
        if (($row['surface_digest'] ?? null) !== $this->digest()) {
            throw new InvalidPayloadException('Group identity digest mismatch');
        }
    }
}
