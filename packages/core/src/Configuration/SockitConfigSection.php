<?php

declare(strict_types=1);

namespace SocKit\Configuration;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Contracts\ConfigReader;
use Packman\Config\Contracts\ConfigSection;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Panels\PanelProvider;
use SocKit\Surfaces\Contracts\TargetResolver;
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
        $strategy = $config->oneOf('ids.strategy', 'uuid7', ['uuid7'], 'Identifier generation strategy.');
        $panels = $config->classList('panels', [], PanelProvider::class, 'Declared PanelProvider classes.');
        $surfaces = $config->classList('surfaces', [], SurfaceProvider::class, 'Declared SurfaceProvider classes.');
        $enabledModules = $config->stringList('modules.enabled', [], null, 'Globally enabled module identifiers.');
        $moduleSettings = $config->subtree('modules.settings', [], 'Global per-module setting defaults.');
        $tenant = $config->nullableClassName('resolvers.tenant', null, TenantResolver::class, 'Host TenantResolver SPI.');
        $actor = $config->nullableClassName('resolvers.actor', null, ActorContextResolver::class, 'Host ActorContextResolver SPI.');
        $contour = $config->nullableClassName('resolvers.contour', null, ContourResolver::class, 'Host ContourResolver SPI.');
        $audience = $config->nullableClassName('resolvers.audience', null, AudienceResolver::class, 'AudienceResolver SPI override.');
        $authorizer = $config->nullableClassName('resolvers.authorizer', null, ActionAuthorizer::class, 'Host ActionAuthorizer SPI.');
        $groups = $config->nullableClassName('resolvers.groups', null, GroupMembershipResolver::class, 'GroupMembershipResolver SPI.');
        $surfacePolicy = $config->nullableClassName('resolvers.surface_policy', null, SurfaceAudiencePolicy::class, 'Host SurfaceAudiencePolicy SPI.');
        $targets = $config->nullableClassName('resolvers.targets', null, TargetResolver::class, 'Default TargetResolver SPI.');
        $privacyFloor = $config->subtree('privacy.floor', [
            'allowed_scopes' => ['direct'],
            'default_scope' => 'direct',
            'max_scope' => 'direct',
            'minor_default_scope' => 'direct',
            'minor_max_scope' => 'direct',
            'contour_levels' => [],
        ], 'Platform-wide privacy floor bounds.');
        $connection = $config->nullableString('storage.connection', null, 'Default storage connection name.');
        $prefix = $config->string('storage.table_prefix', 'sk_', false, '/\A[a-z][a-z0-9]{1,6}_\z/', 'Default SQL table prefix.');
        $receiptTtl = $config->int('operations.receipt_ttl_seconds', 86400, 60, 31536000, 'Command receipt retention horizon in seconds.');
        $outboxBatch = $config->int('operations.outbox_batch_size', 100, 1, 5000, 'Maximum outbox batch size per claim.');

        return new SockitSectionSettings(SockitOptions::fromArray([
            'ids' => ['strategy' => $strategy],
            'panels' => $panels,
            'surfaces' => $surfaces,
            'modules' => [
                'enabled' => $enabledModules,
                'settings' => $moduleSettings,
            ],
            'resolvers' => [
                'tenant' => $tenant,
                'actor' => $actor,
                'contour' => $contour,
                'audience' => $audience,
                'authorizer' => $authorizer,
                'groups' => $groups,
                'surface_policy' => $surfacePolicy,
                'targets' => $targets,
            ],
            'privacy' => [
                'floor' => $privacyFloor,
            ],
            'storage' => [
                'connection' => $connection,
                'table_prefix' => $prefix,
            ],
            'operations' => [
                'receipt_ttl_seconds' => $receiptTtl,
                'outbox_batch_size' => $outboxBatch,
            ],
        ], $config->path()));
    }
}
