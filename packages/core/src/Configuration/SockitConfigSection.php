<?php

declare(strict_types=1);

namespace SocKit\Configuration;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Contracts\ConfigReader;
use Packman\Config\Contracts\ConfigSection;
use SocKit\Panels\PanelProvider;
use SocKit\Surfaces\SurfaceProvider;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
final class SockitConfigSection implements ConfigSection
{
    public function key(): string
    {
        return 'sockit';
    }

    public function settingsClass(): string
    {
        return SocKitConfig::class;
    }

    public function compile(ConfigReader $config): SocKitConfig
    {
        return SocKitConfig::fromArray([
            'ids' => $config->subtree('ids'),
            'panels' => $config->classList('panels', [], PanelProvider::class),
            'surfaces' => $config->classList('surfaces', [], SurfaceProvider::class),
            'modules' => $config->subtree('modules'),
            'resolvers' => $config->subtree('resolvers'),
            'privacy' => $config->subtree('privacy'),
            'events' => $config->subtree('events'),
            'storage' => $config->subtree('storage'),
        ], $config->path());
    }
}
