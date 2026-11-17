<?php

declare(strict_types=1);

namespace SocKit\Plugins;

use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Composition\ProvidesPanels;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithPanels;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Enums\Contributions\SlotKind;
use Packman\Plugins\Laravel\PluginRegistrar;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Configuration\SockitConfigSection;
use SocKit\Configuration\SockitOptions;
use SocKit\Database\SockitSchemaSetProvider;
use SocKit\Diagnostics\SockitDoctorCheck;
use SocKit\Errors\SockitProblemDefinitions;
use SocKit\Operations\Contracts\ContentSafetyChecker;
use SocKit\Operations\Contracts\OperationEnvelopeRunner;
use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Operations\Contracts\OutboxRelay;
use SocKit\Operations\Internal\DatabaseOperationEnvelope;
use SocKit\Operations\Internal\DatabaseOutboxService;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Internal\SockitPanelRegistry;
use SocKit\Panels\SockitCorePanel;
use SocKit\Panels\SockitPanelProvider;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Contracts\SurfaceService;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Internal\DatabaseSurfaceService;
use SocKit\Surfaces\Internal\SurfaceRegistry;

/**
 * Core Packman extension plugin composing SocKit's kernel configuration, schema,
 * capabilities, extension slots, panels, and diagnostics.
 */
#[Api(since: '1.0')]
final class SockitCorePlugin implements ExtensionPlugin, WithCapabilities, WithConfiguration, WithDiagnostics, WithPanels, WithSchema, WithSlots
{
    use Configurable;
    use ContributesToSlots;
    use OwnsSchema;
    use ProvidesCapabilities;
    use ProvidesDiagnostics;
    use ProvidesPanels;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.sockit.core',
            version: '1.0.0',
            resourceRoot: dirname(__DIR__, 2),
            databases: ['pgsql', 'sqlite'],
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}

    #[Override]
    public function configRoot(): string
    {
        return 'sockit';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/sockit.php';
    }

    /** @return class-string<SockitOptions> */
    #[Override]
    public function optionsClass(): string
    {
        return SockitOptions::class;
    }

    /** @return class-string<SockitConfigSection> */
    #[Override]
    public function configSectionClass(): string
    {
        return SockitConfigSection::class;
    }

    #[Override]
    public function tablePrefix(): string
    {
        return 'sk_';
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return [
            'surface_instances',
            'command_receipts',
            'outbox_events',
            'resource_tombstones',
        ];
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations/core';
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [SockitSchemaSetProvider::class];
    }

    /** @return array<class-string, string> */
    #[Override]
    public function definedCapabilities(): array
    {
        return [
            TenantResolver::class => Cardinality::RequiredSingle->value,
            ActorContextResolver::class => Cardinality::OptionalSingle->value,
            ActionAuthorizer::class => Cardinality::RequiredSingle->value,
            AudienceResolver::class => Cardinality::RequiredSingle->value,
            ContourResolver::class => Cardinality::OptionalSingle->value,
            GroupMembershipResolver::class => Cardinality::OptionalSingle->value,
            SurfaceAudiencePolicy::class => Cardinality::OptionalSingle->value,
            PanelDefinitions::class => Cardinality::RequiredSingle->value,
            SurfaceDefinitions::class => Cardinality::RequiredSingle->value,
            SurfaceService::class => Cardinality::RequiredSingle->value,
            OperationEnvelopeRunner::class => Cardinality::RequiredSingle->value,
            OutboxRelay::class => Cardinality::RequiredSingle->value,
        ];
    }

    /** @return array<class-string, class-string|array{config: string, default?: class-string|null}> */
    #[Override]
    public function providedCapabilities(): array
    {
        return [
            TenantResolver::class => [
                'config' => 'sockit.resolvers.tenant',
                'default' => null,
            ],
            ActorContextResolver::class => [
                'config' => 'sockit.resolvers.actor',
                'default' => null,
            ],
            ActionAuthorizer::class => [
                'config' => 'sockit.resolvers.authorizer',
                'default' => null,
            ],
            AudienceResolver::class => [
                'config' => 'sockit.resolvers.audience',
                'default' => AudienceEvaluator::class,
            ],
            ContourResolver::class => [
                'config' => 'sockit.resolvers.contour',
                'default' => null,
            ],
            SurfaceAudiencePolicy::class => [
                'config' => 'sockit.resolvers.surface_policy',
                'default' => null,
            ],
            PanelDefinitions::class => SockitPanelRegistry::class,
            SurfaceDefinitions::class => SurfaceRegistry::class,
            SurfaceService::class => DatabaseSurfaceService::class,
            OperationEnvelopeRunner::class => DatabaseOperationEnvelope::class,
            OutboxRelay::class => DatabaseOutboxService::class,
        ];
    }

    /** @return array<class-string, array{kind: string, cardinality: string}> */
    #[Override]
    public function definedSlots(): array
    {
        return [
            TargetResolver::class => [
                'kind' => SlotKind::Handler->value,
                'cardinality' => Cardinality::Many->value,
            ],
            OutboxConsumer::class => [
                'kind' => SlotKind::EventSink->value,
                'cardinality' => Cardinality::Many->value,
            ],
            ContentSafetyChecker::class => [
                'kind' => SlotKind::Guard->value,
                'cardinality' => Cardinality::Many->value,
            ],
        ];
    }

    /** @return list<class-string> */
    #[Override]
    public function panelProviders(): array
    {
        return [SockitPanelProvider::class, SockitCorePanel::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function healthChecks(): array
    {
        return [SockitDoctorCheck::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [SockitProblemDefinitions::class];
    }
}
