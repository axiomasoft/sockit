<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal\Envelope;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Actors\ActorContexts;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\OutboxRelay;
use SocKit\Panels\PanelDefinitions;
use SocKit\Support\Data\ActorRef;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;

final class WorkerRuntime
{
    public static function create(Application $host, ActorRef $actor): Application
    {
        // Copy container maps, never mutate/flush host bindings or its scoped instances.
        // Host adapters and connection manager remain host-owned: no PDO Fiber sharing is promised.
        $app = clone $host;
        $actors = new ActorContexts(resolver: new WorkerActor(actors: $host->make(ActorContexts::class), actor: $actor), tenants: $host->make(TenantResolver::class));
        $handles = new OperationHandles(actors: $actors, panels: $host->make(PanelDefinitions::class), surfaces: $host->make(SurfaceDefinitions::class), app: $app);
        $app->instance(abstract: ActorContexts::class, instance: $actors);
        $app->instance(abstract: OperationHandles::class, instance: $handles);
        $app->instance(abstract: OwnerStorage::class, instance: new OwnerStorage(connections: $host->make(ConnectionResolverInterface::class), handles: $handles));
        $app->instance(abstract: OutboxRelay::class, instance: new OutboxRelay($app));

        return $app;
    }
}
