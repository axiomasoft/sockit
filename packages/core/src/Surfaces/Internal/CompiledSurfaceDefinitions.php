<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Internal;

use Packman\Attributes\Internal;
use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Data\SurfaceDefinition;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Exceptions\UnknownSurfaceKindException;

#[Internal]
final readonly class CompiledSurfaceDefinitions implements SurfaceDefinitions
{
    /** @param array<string, SurfaceDefinition> $definitions */
    public function __construct(private array $definitions) {}

    public function get(PanelId $panel, SurfaceKindId $kind): SurfaceDefinition
    {
        return $this->definitions[$panel->value.'/'.$kind->value] ?? throw new UnknownSurfaceKindException(message: 'Surface kind is not defined in panel', detail: ['panel' => $panel->value, 'kind' => $kind->value]);
    }

    public function has(PanelId $panel, SurfaceKindId $kind): bool
    {
        return isset($this->definitions[$panel->value.'/'.$kind->value]);
    }

    /** @return array<string, SurfaceDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }
}
