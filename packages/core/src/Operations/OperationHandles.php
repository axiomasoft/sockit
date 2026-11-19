<?php

declare(strict_types=1);

namespace SocKit\Operations;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Api;
use SocKit\Actors\ActorContexts;
use SocKit\Actors\Exceptions\ActorContextMismatchException;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Operations\Internal\Storage\StorageBindingException;
use SocKit\Panels\PanelDefinitions;
use SocKit\Panels\PanelStorage;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Surfaces;

#[Api(since: '1.0')]
final readonly class OperationHandles
{
    public function __construct(private ActorContexts $actors, private PanelDefinitions $panels, private SurfaceDefinitions $surfaces, private Application $app) {}

    public static function configure(Application $app): void
    {
        $app->scopedIf(self::class);
        $app->scopedIf(abstract: OwnerStorage::class, concrete: static fn (Application $app): OwnerStorage => new OwnerStorage(connections: $app->make(ConnectionResolverInterface::class), handles: $app->make(self::class)));
    }

    /** Sample current target visibility outside transactions on every protected entry/replay. */
    public function resolveTarget(OperationHandle $handle, TargetRef $target): TargetResolution
    {
        return Surfaces::resolveTarget(app: $this->app, handle: $handle, target: $target);
    }

    /** Local target fence only; full operation lock ordering and authorization belong to the envelope. */
    public function recheckTarget(OperationHandle $handle, TargetResolution $resolved): AuthorizationDecision
    {
        return Surfaces::recheckTarget(app: $this->app, handle: $handle, resolved: $resolved);
    }

    public function forSurface(SurfaceRef $surface): OperationHandle
    {
        $actor = $this->actors->current();
        $panel = $this->panels->get($surface->panel);
        $definition = $this->surfaces->get(panel: $surface->panel, kind: $surface->kind);
        if ($surface->host->type !== $definition->hostType || ! in_array($surface->host->type, $panel->hostTypes, true)) {
            throw new StorageBindingException(message: 'Surface host does not match its panel binding', detail: ['panel' => $surface->panel->value]);
        }
        foreach (PanelStorage::KERNEL_ENTITIES as $entity) {
            if (! isset($panel->storage->tables[$entity])) {
                throw new InvalidConfigurationException(message: 'Kernel storage map is incomplete', detail: ['panel' => $surface->panel->value, 'key' => 'tables.'.$entity]);
            }
        }

        return new OperationHandle(surface: $surface, actor: $actor, panel: $panel, definition: $definition);
    }

    /** Resolve current trusted facts outside owner transactions. Serialized or caller-built snapshots confer no authority. */
    public function assertCurrent(OperationHandle $handle, ?CommandNamespace $namespace = null): void
    {
        $current = $this->forSurface($handle->surface);
        if ($handle->actor != $current->actor) {
            throw new ActorContextMismatchException('Operation snapshot differs from the current trusted actor or isolation');
        }
        if ($handle->panel != $current->panel || $handle->definition != $current->definition || ($namespace !== null && (! $namespace->panel->equals($current->surface->panel) || ! $namespace->installation->equals($current->actor->installation)))) {
            throw new StorageBindingException(message: 'Operation snapshot or namespace differs from its panel binding', detail: ['panel' => $handle->surface->panel->value]);
        }
    }
}
