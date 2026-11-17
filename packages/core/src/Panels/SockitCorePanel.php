<?php

declare(strict_types=1);

namespace SocKit\Panels;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Builder\PanelBuilder;
use Packman\Panels\Builder\PanelProvider as PackmanBuilderPanelProvider;
use Packman\Panels\Data\Panel\PanelSlot;
use SocKit\Diagnostics\SockitDoctorCheck;

/**
 * Registers SocKit's immutable Packman PanelBuilder declaration so `PanelRegistry::freeze()` compiles it at boot.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class SockitCorePanel extends PackmanBuilderPanelProvider
{
    #[Override]
    public static function getId(): string
    {
        return 'sockit';
    }

    #[Override]
    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->id('sockit')
            ->label('SocKit Social Engine')
            ->description('Headless storage-boundary social engine panel.')
            ->slots([
                new PanelSlot(
                    id: 'sockit.privacy',
                    settings: ['default_scope', 'max_scope', 'minor_max_scope'],
                    defaults: ['default_scope' => 'direct', 'max_scope' => 'direct', 'minor_max_scope' => 'direct'],
                    inheritDefaults: true,
                ),
            ])
            ->doctorChecks([SockitDoctorCheck::class]);
    }
}
