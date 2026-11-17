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
use Packman\Plugins\Internal\Contributions\ExtensionRegistry;
use RuntimeException;
use SocKit\Actors\ActorContexts;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Actors\SingleInstallationTenantResolver;
use SocKit\Audience\AllowAuthenticatedPersonAuthorizer;
use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Configuration\SockitOptions;
use SocKit\Console\DiagnoseCommand;
use SocKit\Console\OutboxRunCommand;
use SocKit\Console\RebuildReactionsCommand;
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
use SocKit\Operations\Contracts\ContentSafetyChecker;
use SocKit\Operations\Contracts\OperationEnvelopeRunner;
use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Operations\Contracts\OutboxRelay;
use SocKit\Operations\Internal\DatabaseOperationEnvelope;
use SocKit\Operations\Internal\DatabaseOutboxService;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Internal\SockitPanelRegistry;
use SocKit\Panels\SockitPanelProvider;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Contracts\SurfaceService;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Internal\DatabaseSurfaceService;
use SocKit\Surfaces\Internal\SurfaceRegistry;

/**
 * Registers SocKit's runtime services, capability implementations, slot adapters, and Artisan commands.
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
            actors: $app->bound(ActorContextResolver::class) ? $app->make(ActorContextResolver::class) : null,
            tenants: $app->make(TenantResolver::class),
            authorizer: $app->make(ActionAuthorizer::class),
        ));

        ProblemDefinitions::register($app, dirname(__DIR__, 2).'/composer.json', SockitProblemDefinitions::class);
    }

    #[Override]
    public function boot(Application $app): void
    {
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

    private function registerSpiCapabilities(Application $app): void
    {
        $app->singletonIf(TenantResolver::class, static function (Application $app): TenantResolver {
            $configured = $app->make(SockitOptions::class)->tenantResolver;

            /** @var TenantResolver */
            return $app->make($configured ?? SingleInstallationTenantResolver::class);
        });

        $app->singletonIf(ActorContextResolver::class, static function (Application $app): ActorContextResolver {
            $configured = $app->make(SockitOptions::class)->actorResolver;
            if ($configured === null) {
                throw new RuntimeException('No SocKit ActorContextResolver is configured or bound.');
            }

            /** @var ActorContextResolver */
            return $app->make($configured);
        });

        $app->singletonIf(ActionAuthorizer::class, static function (Application $app): ActionAuthorizer {
            $configured = $app->make(SockitOptions::class)->authorizerResolver;

            /** @var ActionAuthorizer */
            return $app->make($configured ?? AllowAuthenticatedPersonAuthorizer::class);
        });

        $app->singletonIf(AudienceResolver::class, static function (Application $app): AudienceResolver {
            $options = $app->make(SockitOptions::class);
            if ($options->audienceResolver !== null) {
                /** @var AudienceResolver */
                return $app->make($options->audienceResolver);
            }

            $contour = self::resolveOptionalSpi($app, ContourResolver::class, $options->contourResolver);
            $groups = self::resolveOptionalGroupResolver($app, $options->groupResolver);

            return new AudienceEvaluator($contour, $groups);
        });

        $app->singletonIf(ActorContexts::class, static fn (Application $app): ActorContexts => new ActorContexts(
            resolver: $app->make(ActorContextResolver::class),
            tenants: $app->make(TenantResolver::class),
        ));

        $app->singletonIf(AudienceGate::class, static function (Application $app): AudienceGate {
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
        $app->singleton(DatabaseSurfaceService::class, static fn (Application $app): DatabaseSurfaceService => new DatabaseSurfaceService(
            tables: $app->make(SockitTables::class),
            panels: $app->make(PanelDefinitions::class),
            surfaces: $app->make(SurfaceDefinitions::class),
            actors: $app->make(ActorContexts::class),
        ));
        $app->alias(DatabaseSurfaceService::class, SurfaceService::class);

        $app->singleton(DatabaseOperationEnvelope::class, static fn (Application $app): DatabaseOperationEnvelope => new DatabaseOperationEnvelope(
            tables: $app->make(SockitTables::class),
            panels: $app->make(PanelDefinitions::class),
            surfaces: $app->make(SurfaceDefinitions::class),
            actors: $app->make(ActorContexts::class),
        ));
        $app->alias(DatabaseOperationEnvelope::class, OperationEnvelopeRunner::class);

        $app->singleton(DatabaseOutboxService::class, static function (Application $app): DatabaseOutboxService {
            $consumers = [];
            if ($app->bound(ExtensionRegistry::class)) {
                /** @var list<OutboxConsumer> $slotConsumers */
                $slotConsumers = $app->make(ExtensionRegistry::class)->resolve(OutboxConsumer::class);
                $consumers = $slotConsumers;
            }
            foreach ($app->tagged(OutboxConsumer::class) as $tagged) {
                if ($tagged instanceof OutboxConsumer) {
                    $consumers[] = $tagged;
                }
            }

            return new DatabaseOutboxService(
                tables: $app->make(SockitTables::class),
                panels: $app->make(PanelDefinitions::class),
                consumers: $consumers,
            );
        });
        $app->alias(DatabaseOutboxService::class, OutboxRelay::class);

        $app->singleton(DatabaseReactionService::class, static function (Application $app): DatabaseReactionService {
            $options = $app->make(SockitOptions::class);
            $policy = self::resolveOptionalSpi($app, SurfaceAudiencePolicy::class, $options->surfacePolicyResolver);
            $resolvers = [];
            if ($options->targetResolver !== null) {
                $made = $app->make($options->targetResolver);
                if ($made instanceof TargetResolver) {
                    $resolvers[] = $made;
                }
            }
            if ($app->bound(TargetResolver::class)) {
                $resolvers[] = $app->make(TargetResolver::class);
            }
            if ($app->bound(ExtensionRegistry::class)) {
                foreach ($app->make(ExtensionRegistry::class)->resolve(TargetResolver::class) as $slotResolver) {
                    $resolvers[] = $slotResolver;
                }
            }

            return new DatabaseReactionService(
                tables: $app->make(SockitTables::class),
                panels: $app->make(PanelDefinitions::class),
                surfaces: $app->make(SurfaceDefinitions::class),
                actors: $app->make(ActorContexts::class),
                envelope: $app->make(OperationEnvelopeRunner::class),
                authorizer: $app->make(ActionAuthorizer::class),
                audienceGate: $app->make(AudienceGate::class),
                surfacePolicy: $policy,
                targetResolvers: $resolvers,
            );
        });
        $app->alias(DatabaseReactionService::class, ReactionService::class);

        $app->singleton(DatabasePostService::class, static function (Application $app): DatabasePostService {
            $checkers = [];
            if ($app->bound(ContentSafetyChecker::class)) {
                $checkers[] = $app->make(ContentSafetyChecker::class);
            }
            if ($app->bound(ExtensionRegistry::class)) {
                foreach ($app->make(ExtensionRegistry::class)->resolve(ContentSafetyChecker::class) as $slotChecker) {
                    $checkers[] = $slotChecker;
                }
            }

            return new DatabasePostService(
                tables: $app->make(SockitTables::class),
                panels: $app->make(PanelDefinitions::class),
                surfaces: $app->make(SurfaceDefinitions::class),
                actors: $app->make(ActorContexts::class),
                envelope: $app->make(OperationEnvelopeRunner::class),
                authorizer: $app->make(ActionAuthorizer::class),
                audienceGate: $app->make(AudienceGate::class),
                safetyCheckers: $checkers,
            );
        });
        $app->alias(DatabasePostService::class, PostService::class);

        $app->singleton(DatabaseFeedService::class, static fn (Application $app): DatabaseFeedService => new DatabaseFeedService(
            tables: $app->make(SockitTables::class),
            panels: $app->make(PanelDefinitions::class),
            surfaces: $app->make(SurfaceDefinitions::class),
            actors: $app->make(ActorContexts::class),
            authorizer: $app->make(ActionAuthorizer::class),
            audienceGate: $app->make(AudienceGate::class),
        ));
        $app->alias(DatabaseFeedService::class, FeedService::class);

        $app->singleton(DatabaseGroupService::class, static fn (Application $app): DatabaseGroupService => new DatabaseGroupService(
            tables: $app->make(SockitTables::class),
            panels: $app->make(PanelDefinitions::class),
            surfaces: $app->make(SurfaceDefinitions::class),
            actors: $app->make(ActorContexts::class),
            envelope: $app->make(OperationEnvelopeRunner::class),
            authorizer: $app->make(ActionAuthorizer::class),
            audienceGate: $app->make(AudienceGate::class),
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
