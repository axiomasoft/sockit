<?php

declare(strict_types=1);

namespace SocKit\Configuration;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Contracts\ConfigReader;
use Packman\Config\Contracts\ConfigSection;
use SocKit\Panels\PanelProvider;
use SocKit\Surfaces\SurfaceProvider;

/**
 * Packman ConfigSection descriptor powering closed-schema JSON Schema generation and SectionCompiler validation.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
final readonly class SockitConfigSection implements ConfigSection
{
    #[Override]
    public function key(): string
    {
        return 'sockit';
    }

    #[Override]
    public function settingsClass(): string
    {
        return SockitSectionSettings::class;
    }

    #[Override]
    public function compile(ConfigReader $config): SockitSectionSettings
    {
        $ids = $config->subtree('ids', ['strategy' => 'uuid7'], 'Identifier generation strategy configuration.');
        $panels = $config->classList('panels', [], PanelProvider::class, 'Declared PanelProvider classes.');
        $surfaces = $config->classList('surfaces', [], SurfaceProvider::class, 'Declared SurfaceProvider classes.');
        $modules = $config->subtree('modules', [
            'enabled' => [],
            'settings' => [],
        ], 'Module activation and global per-module setting defaults.');
        $resolvers = $config->subtree('resolvers', [
            'tenant' => null,
            'actor' => null,
            'contour' => null,
            'audience' => null,
            'authorizer' => null,
            'groups' => null,
            'surface_policy' => null,
            'targets' => null,
        ], 'Host SPI resolver class bindings.');
        $privacy = $config->subtree('privacy', [
            'floor' => [
                'allowed_scopes' => ['direct'],
                'default_scope' => 'direct',
                'max_scope' => 'direct',
                'minor_default_scope' => 'direct',
                'minor_max_scope' => 'direct',
                'contour_levels' => [],
            ],
        ], 'Platform-wide privacy floor bounds.');
        $storage = $config->subtree('storage', [
            'connection' => null,
            'table_prefix' => 'sk_',
        ], 'Default database storage settings.');
        $operations = $config->subtree('operations', [
            'receipt_ttl_seconds' => 86400,
            'outbox_batch_size' => 100,
        ], 'Command envelope and outbox execution limits.');

        return new SockitSectionSettings(SockitOptions::fromArray([
            'ids' => $ids,
            'panels' => $panels,
            'surfaces' => $surfaces,
            'modules' => $modules,
            'resolvers' => $resolvers,
            'privacy' => $privacy,
            'storage' => $storage,
            'operations' => $operations,
        ], $config->path()));
    }
}
