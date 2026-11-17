<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\SurfaceInstance;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Runtime service managing panel-bound surface instances and CAS lifecycle incarnations.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface SurfaceService
{
    public function ensureInstance(
        CommandKey $commandKey,
        PanelId $panel,
        SurfaceKindId $kind,
        HostEntityRef $host,
        string $integration = 'sockit.core',
    ): SurfaceInstance;

    public function create(
        CommandKey $commandKey,
        SurfaceRef $ref,
        string $integration = 'sockit.core',
    ): SurfaceInstance;

    public function get(SurfaceRef $ref): SurfaceInstance;

    public function findActive(PanelId $panel, SurfaceKindId $kind, HostEntityRef $host): ?SurfaceInstance;

    public function archive(
        CommandKey $commandKey,
        SurfaceRef $ref,
        Revision $expectedRevision,
        string $integration = 'sockit.core',
    ): SurfaceInstance;

    public function resume(
        CommandKey $commandKey,
        SurfaceRef $ref,
        Revision $expectedRevision,
        string $integration = 'sockit.core',
    ): SurfaceInstance;

    public function delete(
        CommandKey $commandKey,
        SurfaceRef $ref,
        Revision $expectedRevision,
        string $integration = 'sockit.core',
    ): SurfaceInstance;

    public function startNewIncarnation(
        CommandKey $commandKey,
        PanelId $panel,
        SurfaceKindId $kind,
        HostEntityRef $host,
        string $integration = 'sockit.core',
    ): SurfaceInstance;
}
