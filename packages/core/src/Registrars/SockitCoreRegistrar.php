<?php

declare(strict_types=1);

namespace SocKit\Registrars;

use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Override;
use Packman\Attributes\Internal;
use Packman\Errors\Laravel\ProblemDefinitions;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use SocKit\Actors\ActorContexts;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Configuration\SockitOptions;
use SocKit\Console\Diagnostics\DiagnoseCommand;
use SocKit\Console\Operations\OutboxRunCommand;
use SocKit\Console\Reactions\RebuildReactionsCommand;
use SocKit\Database\Internal\SockitTables;
use SocKit\Database\StorageCollisionChecker;
use SocKit\Diagnostics\SockitDoctorCheck;
use SocKit\Errors\SockitProblemDefinitions;
use SocKit\Modules\Groups\Contracts\GroupService;
use SocKit\Modules\Groups\Internal\DatabaseGroupMembershipResolver;
use SocKit\Modules\Groups\Internal\DatabaseGroupService;
use SocKit\Modules\Posts\Contracts\FeedService;
use SocKit\Modules\Posts\Contracts\PostService;
use SocKit\Modules\Posts\Internal\DatabaseFeedService;
use SocKit\Modules\Posts\Internal\DatabasePostService;
use SocKit\Modules\Reactions\Contracts\ReactionService;
use SocKit\Modules\Reactions\Internal\DatabaseReactionService;
use SocKit\Operations\Contracts\OperationEnvelopeRunner;
use SocKit\Operations\Contracts\OutboxRelay;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Operations\Internal\DatabaseOperationEnvelope;
use SocKit\Operations\Internal\DatabaseOutboxService;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Internal\SockitPanelRegistry;
use SocKit\Panels\SockitPanelProvider;
use SocKit\Registrars\Internal\SockitRuntime;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Contracts\SurfaceService;
use SocKit\Surfaces\Internal\DatabaseSurfaceService;
use SocKit\Surfaces\Internal\SurfaceRegistry;

/**
 * Registers SocKit's immutable definition registries, fail-closed security SPIs, runtime services, and Artisan commands.
 */
#[Internal]
final class SockitCoreRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->singletonIf(SockitOptions::class, static function (Application $app): SockitOptions {
            $raw = $app->bound(Repository::class) ? $app->make(Repository::class)->get('sockit', []) : [];

            return SockitOptions::fromArray(is_array($raw) ? $raw : [], 'sockit');
        });

        $app->singleton(StorageCollisionChecker::class);
        $app->singleton(SockitPanelRegistry::class);
        $app->alias(SockitPanelRegistry::class, PanelDefinitions::class);

        $app->singleton(SurfaceRegistry::class);
        $app->alias(SurfaceRegistry::class, SurfaceDefinitions::class);

        $app->singleton(SockitTables::class, static fn (Application $app): SockitTables => new SockitTables(
            db: $app->make(DatabaseManager::class),
            options: $app->make(SockitOptions::class),
            panels: $app->make(PanelDefinitions::class),
        ));

        $app->singleton(SockitRuntime::class, static fn (Application $app): SockitRuntime => new SockitRuntime($app));

        $this->registerSpiCapabilities($app);
        $this->registerKernelAndModuleServices($app);

        $app->singleton(SockitDoctorCheck::class, static fn (Application $app): SockitDoctorCheck => new SockitDoctorCheck(
            config: $app->make(Repository::class),
            db: $app->make(DatabaseManager::class),
            panels: $app->make(PanelDefinitions::class),
            surfaces: $app->make(SurfaceDefinitions::class),
            collisions: $app->make(StorageCollisionChecker::class),
        ));

        $app->singleton(SockitPanelProvider::class, static fn (Application $app): SockitPanelProvider => new SockitPanelProvider(
            container: $app,
        ));

        ProblemDefinitions::register($app, dirname(__DIR__, 2).'/composer.json', SockitProblemDefinitions::class);
    }

    #[Override]
    public function boot(Application $app): void
    {
        if (! $app->bound(GroupMembershipResolver::class)) {
            $configuredGroupResolver = $app->make(SockitOptions::class)->groupResolver;

            if ($configuredGroupResolver !== null || $this->isPluginActive($app, 'axiomasoft.sockit.groups')) {
                $app->bind(GroupMembershipResolver::class, static function (Application $app): GroupMembershipResolver {
                    $configured = $app->make(SockitOptions::class)->groupResolver;

                    if ($configured !== null) {
                        /** @var GroupMembershipResolver */
                        return $app->make($configured);
                    }

                    return new DatabaseGroupMembershipResolver(
                        tables: $app->make(SockitTables::class),
                        panels: $app->make(PanelDefinitions::class),
                    );
                });
            }
        }

        if ($app->runningInConsole()) {
            Artisan::starting(static function (Artisan $artisan): void {
                $artisan->resolveCommands([
                    DiagnoseCommand::class,
                    OutboxRunCommand::class,
                    RebuildReactionsCommand::class,
                ]);
            });
        }
    }

    private function isPluginActive(Application $app, string $pluginId): bool
    {
        if (! $app->bound(PluginInspector::class)) {
            return false;
        }

        foreach ($app->make(PluginInspector::class)->describe() as $plugin) {
            if ($plugin->id === $pluginId) {
                return true;
            }
        }

        return false;
    }

    private function registerSpiCapabilities(Application $app): void
    {
        $app->bindIf(TenantResolver::class, static function (Application $app): TenantResolver {
            $configured = $app->make(SockitOptions::class)->tenantResolver;
            if ($configured === null) {
                throw OperationException::providerUnavailable(TenantResolver::class);
            }

            /** @var TenantResolver */
            return $app->make($configured);
        });

        $app->bindIf(ActorContextResolver::class, static function (Application $app): ActorContextResolver {
            $configured = $app->make(SockitOptions::class)->actorResolver;
            if ($configured === null) {
                throw OperationException::providerUnavailable(ActorContextResolver::class);
            }

            /** @var ActorContextResolver */
            return $app->make($configured);
        });

        $app->bindIf(ActionAuthorizer::class, static function (Application $app): ActionAuthorizer {
            $configured = $app->make(SockitOptions::class)->authorizerResolver;
            if ($configured === null) {
                throw OperationException::providerUnavailable(ActionAuthorizer::class);
            }

            /** @var ActionAuthorizer */
            return $app->make($configured);
        });

        $app->bindIf(AudienceResolver::class, static function (Application $app): AudienceResolver {
            $options = $app->make(SockitOptions::class);
            if ($options->audienceResolver !== null) {
                /** @var AudienceResolver */
                return $app->make($options->audienceResolver);
            }

            $contour = self::resolveOptionalSpi($app, ContourResolver::class, $options->contourResolver);
            $groups = self::resolveOptionalGroupResolver($app, $options->groupResolver);

            return new AudienceEvaluator($contour, $groups);
        });

        $app->bindIf(ActorContexts::class, static fn (Application $app): ActorContexts => new ActorContexts(
            resolver: $app->make(ActorContextResolver::class),
            tenants: $app->make(TenantResolver::class),
        ));

        $app->bindIf(AudienceGate::class, static function (Application $app): AudienceGate {
            $options = $app->make(SockitOptions::class);
            $policy = self::resolveOptionalSpi($app, SurfaceAudiencePolicy::class, $options->surfacePolicyResolver);

            return new AudienceGate(
                resolver: $app->make(AudienceResolver::class),
                surfacePolicy: $policy,
            );
        });
    }

    private function registerKernelAndModuleServices(Application $app): void
    {
        $app->bind(DatabaseSurfaceService::class, static fn (Application $app): DatabaseSurfaceService => new DatabaseSurfaceService(
            tables: $app->make(SockitTables::class),
            panels: $app->make(PanelDefinitions::class),
            surfaces: $app->make(SurfaceDefinitions::class),
            runtime: $app->make(SockitRuntime::class),
        ));
        $app->alias(DatabaseSurfaceService::class, SurfaceService::class);

        $app->bind(DatabaseOperationEnvelope::class, static fn (Application $app): DatabaseOperationEnvelope => new DatabaseOperationEnvelope(
            tables: $app->make(SockitTables::class),
            panels: $app->make(PanelDefinitions::class),
            surfaces: $app->make(SurfaceDefinitions::class),
            runtime: $app->make(SockitRuntime::class),
        ));
        $app->alias(DatabaseOperationEnvelope::class, OperationEnvelopeRunner::class);

        $app->bind(DatabaseOutboxService::class, static fn (Application $app): DatabaseOutboxService => new DatabaseOutboxService(
            tables: $app->make(SockitTables::class),
            panels: $app->make(PanelDefinitions::class),
            runtime: $app->make(SockitRuntime::class),
        ));
        $app->alias(DatabaseOutboxService::class, OutboxRelay::class);

        $app->bind(DatabaseReactionService::class, static fn (Application $app): DatabaseReactionService => new DatabaseReactionService(
            tables: $app->make(SockitTables::class),
            panels: $app->make(PanelDefinitions::class),
            surfaces: $app->make(SurfaceDefinitions::class),
            runtime: $app->make(SockitRuntime::class),
        ));
        $app->alias(DatabaseReactionService::class, ReactionService::class);

        $app->bind(DatabasePostService::class, static fn (Application $app): DatabasePostService => new DatabasePostService(
            tables: $app->make(SockitTables::class),
            panels: $app->make(PanelDefinitions::class),
            surfaces: $app->make(SurfaceDefinitions::class),
            runtime: $app->make(SockitRuntime::class),
        ));
        $app->alias(DatabasePostService::class, PostService::class);

        $app->bind(DatabaseFeedService::class, static fn (Application $app): DatabaseFeedService => new DatabaseFeedService(
            tables: $app->make(SockitTables::class),
            panels: $app->make(PanelDefinitions::class),
            surfaces: $app->make(SurfaceDefinitions::class),
            runtime: $app->make(SockitRuntime::class),
        ));
        $app->alias(DatabaseFeedService::class, FeedService::class);

        $app->bind(DatabaseGroupService::class, static fn (Application $app): DatabaseGroupService => new DatabaseGroupService(
            tables: $app->make(SockitTables::class),
            panels: $app->make(PanelDefinitions::class),
            surfaces: $app->make(SurfaceDefinitions::class),
            runtime: $app->make(SockitRuntime::class),
        ));
        $app->alias(DatabaseGroupService::class, GroupService::class);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @param  class-string<T>|null  $configured
     * @return T|null
     */
    private static function resolveOptionalSpi(Application $app, string $contract, ?string $configured): ?object
    {
        if ($app->bound($contract)) {
            $resolved = $app->make($contract);

            return $resolved instanceof $contract ? $resolved : null;
        }

        if ($configured !== null) {
            $resolved = $app->make($configured);

            return $resolved instanceof $contract ? $resolved : null;
        }

        return null;
    }

    /** @param class-string<GroupMembershipResolver>|null $configured */
    private static function resolveOptionalGroupResolver(Application $app, ?string $configured): ?GroupMembershipResolver
    {
        if ($app->bound(GroupMembershipResolver::class)) {
            return $app->make(GroupMembershipResolver::class);
        }

        if ($configured !== null) {
            $resolved = $app->make($configured);

            return $resolved instanceof GroupMembershipResolver ? $resolved : null;
        }

        if ($app->bound(PluginInspector::class)) {
            foreach ($app->make(PluginInspector::class)->describe() as $plugin) {
                if ($plugin->id === 'axiomasoft.sockit.groups') {
                    return new DatabaseGroupMembershipResolver(
                        tables: $app->make(SockitTables::class),
                        panels: $app->make(PanelDefinitions::class),
                    );
                }
            }
        }

        return null;
    }
}
