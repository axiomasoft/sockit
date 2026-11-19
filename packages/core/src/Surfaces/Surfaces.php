<?php

declare(strict_types=1);

namespace SocKit\Surfaces;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Enums\AuthorizationStatus;
use SocKit\Audience\Enums\VerdictDecision;
use SocKit\Audience\Exceptions\AuthorizationDeniedException;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Configuration\SocKitConfig;
use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Modules\Modules;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\OperationHandles;
use SocKit\Panels\PanelDefinitions;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Enums\TargetLifecycle;
use SocKit\Surfaces\Internal\SurfaceCompiler;
use SocKit\Surfaces\Internal\TargetCapabilities;

/** Boot coordinator API. Only the validated definitions contract is a runtime registry. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class Surfaces
{
    public static function configure(Application $app): void
    {
        self::compiler($app);
        $app->singleton(TargetCapabilities::class);
        $app->singleton(abstract: SurfaceDefinitions::class, concrete: static fn (): SurfaceDefinitions => self::compiler($app)->published());
    }

    public static function add(Application $app, string ...$providers): void
    {
        foreach ($providers as $provider) {
            self::compiler($app)->add($provider);
        }
    }

    public static function compile(Application $app, SocKitConfig $config, Modules $modules, PanelDefinitions $panels): void
    {
        self::compiler($app)->compile(app: $app, config: $config, modules: $modules, panels: $panels);
        $app->make(TargetCapabilities::class)->compile(modules: $modules, panels: $panels);
    }

    public static function publish(Application $app): void
    {
        self::compiler($app)->publish();
        $app->make(TargetCapabilities::class)->publish();
    }

    /** Current sampled host visibility plus hard surface admission. Call again on reads/replay/lookup. */
    public static function resolveTarget(Application $app, OperationHandle $handle, TargetRef $target): TargetResolution
    {
        self::assertOutsideTransaction(app: $app, handle: $handle);
        $app->make(OperationHandles::class)->assertCurrent($handle);
        try {
            $admission = $app->make(SurfaceAudiencePolicy::class)->admit(viewer: $handle->actor, surface: $handle->surface);
            if (! self::fresh($admission) || ! $admission->canRead()) {
                AuthorizationDecision::deny('target.surface_admission_denied')->requireAllowed();
            }
            $resolved = $app->make(TargetCapabilities::class)->resolve(app: $app, handle: $handle, target: $target);
        } catch (SocKitException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new AuthorizationDeniedException(status: AuthorizationStatus::Unknown, reason: 'target.resolver_error');
        }
        if (! $resolved->surface->equals($handle->surface) || ! $resolved->target->equals($target) || ! $resolved->installation->equals($handle->actor->installation)) {
            AuthorizationDecision::unknown('target.binding_mismatch')->requireAllowed();
        }
        $read = $resolved->read;
        $write = $resolved->write;
        if (! self::fresh($read) || ! $read->canRead() || ! in_array($resolved->lifecycle, [TargetLifecycle::Live, TargetLifecycle::ReadOnly], true)) {
            $read = $write = AudienceVerdict::deny('audience.unknown');
        } elseif (! $admission->canWrite() || $resolved->lifecycle !== TargetLifecycle::Live || ! self::fresh($write) || ! $write->canWrite()) {
            $write = AudienceVerdict::deny('audience.unknown');
        }

        if ($read->canRead()) {
            $expiry = $read->expiresAt;
            if ($admission->expiresAt !== null && ($expiry === null || $admission->expiresAt < $expiry)) {
                $expiry = $admission->expiresAt;
            }
            $read = new AudienceVerdict(decision: $admission->canWrite() ? $read->decision : VerdictDecision::AllowReadOnly,
                reason: $admission->canWrite() ? $read->reason : $admission->reason, policyVersion: $read->policyVersion, expiresAt: $expiry);
        }

        return new TargetResolution(surface: $resolved->surface, target: $resolved->target, installation: $resolved->installation,
            read: $read, write: $write, lifecycle: $resolved->lifecycle, privacyRevision: $resolved->privacyRevision, generation: $resolved->generation);
    }

    /** Local stage only. Does not call actor, tenant, admission or remote target resolvers under lock. */
    public static function recheckTarget(Application $app, OperationHandle $handle, TargetResolution $resolved): AuthorizationDecision
    {
        try {
            $panel = $app->make(PanelDefinitions::class)->get($handle->surface->panel);
            $surface = $app->make(SurfaceDefinitions::class)->get(panel: $handle->surface->panel, kind: $handle->surface->kind);
            if ($panel != $handle->panel || $surface != $handle->definition || $handle->surface->host->type !== $surface->hostType
                || ! $resolved->surface->equals($handle->surface) || ! $resolved->installation->equals($handle->actor->installation)
                || ! self::inTransaction(app: $app, handle: $handle)) {
                return AuthorizationDecision::unknown('target.local_boundary_unproven');
            }
            if ($resolved->lifecycle !== TargetLifecycle::Live || ! $resolved->read->canRead() || ! $resolved->write->canWrite() || ! self::fresh($resolved->read) || ! self::fresh($resolved->write)) {
                return AuthorizationDecision::deny('target.write_denied');
            }

            return $app->make(TargetCapabilities::class)->recheck(app: $app, handle: $handle, resolved: $resolved);
        } catch (\Throwable) {
            return AuthorizationDecision::unknown('target.local_fence_error');
        }
    }

    private static function fresh(AudienceVerdict $verdict): bool
    {
        return $verdict->expiresAt === null || $verdict->expiresAt > new \DateTimeImmutable('now');
    }

    private static function assertOutsideTransaction(Application $app, OperationHandle $handle): void
    {
        $connections = $app->resolved(ConnectionResolverInterface::class) ? $app->make(ConnectionResolverInterface::class) : null;
        $active = $connections instanceof DatabaseManager && array_any($connections->getConnections(), static fn ($connection): bool => $connection->transactionLevel() > 0);
        if ($active || self::inTransaction(app: $app, handle: $handle)) {
            throw new InvalidConfigurationException(message: 'Target resolution requires rollback before remote IO', detail: ['key' => 'transaction', 'panel' => $handle->surface->panel->value]);
        }
    }

    private static function inTransaction(Application $app, OperationHandle $handle): bool
    {
        return $app->resolved(ConnectionResolverInterface::class)
            && $app->make(ConnectionResolverInterface::class)->connection($handle->panel->storage->connection)->transactionLevel() > 0;
    }

    private static function compiler(Application $app): SurfaceCompiler
    {
        if (! $app->bound(SurfaceCompiler::class)) {
            $app->singleton(SurfaceCompiler::class);
        }

        return $app->make(SurfaceCompiler::class);
    }
}
