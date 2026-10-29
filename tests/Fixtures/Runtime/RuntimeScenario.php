<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Actors\Enums\AgeBand;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\PanelDefinition;
use SocKit\Panels\PanelDefinitions;
use SocKit\Panels\PanelStorage;
use SocKit\SocKitServiceProvider;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Testing\SocKitFake;

final class RuntimeScenario
{
    public static function app(bool $complete = true, bool $boot = true): Application
    {
        $app = new Application;
        $app->instance('config', new Repository(['sockit' => ['panels' => [RuntimePanel::class, AlternatePanel::class, DecoyPanel::class], 'surfaces' => [RuntimeSurface::class, AlternateSurface::class, DecoySurface::class]]]));
        $app->instance(RuntimePanel::class, new RuntimePanel);
        $app->instance(AlternatePanel::class, new AlternatePanel(id: 'acme.b', connection: 'owner_b', schema: 'panel_b', prefix: 'b_'));
        $app->instance(DecoyPanel::class, new DecoyPanel(id: 'acme.decoy', connection: 'decoy', schema: 'panel_a', prefix: 'a_'));
        $app->instance(AlternateSurface::class, new AlternateSurface('acme.b'));
        $app->instance(DecoySurface::class, new DecoySurface('acme.decoy'));
        $app->register(SocKitServiceProvider::class);
        if ($boot) {
            $app->boot();
        }
        if (! $complete) {
            // P1's low-level descriptor permits partial maps; its fluent builder always fills kernel defaults.
            $registry = $app->make(PanelDefinitions::class);
            $definitions = $registry->all();
            $panel = $definitions['acme.a'];
            $tables = $panel->storage->tables;
            unset($tables['outbox']);
            $definitions['acme.a'] = new PanelDefinition(id: $panel->id, hostTypes: $panel->hostTypes,
                storage: new PanelStorage(connection: $panel->storage->connection, schema: $panel->storage->schema, prefix: $panel->storage->prefix, tables: $tables, models: []),
                modules: $panel->modules, moduleSettings: $panel->moduleSettings, floor: $panel->floor, provider: $panel->provider);
            $partial = new \ReflectionClass(PanelDefinitions::class)->newInstanceWithoutConstructor();
            new \ReflectionProperty($partial, 'definitions')->setValue($partial, $definitions);
            $app->instance(abstract: PanelDefinitions::class, instance: $partial);
        }

        return $app;
    }

    public static function trust(Application $app): SocKitFake
    {
        $fake = SocKitFake::install(app: $app, installation: new InstallationRef('runtime-test'));
        $fake->actors->actingAs(self::actor());

        return $fake;
    }

    public static function actor(string $actor = '7', string $installation = 'runtime-test', string $generation = '1'): ActorContext
    {
        return new ActorContext(actor: new ActorRef(type: 'user', id: $actor), kind: ActorKind::Person, installation: new InstallationRef($installation), ageBand: AgeBand::Adult, provenance: ContextProvenance::Direct, generation: $generation);
    }

    public static function surface(string $panel = 'acme.a', string $host = 'article'): SurfaceRef
    {
        return new SurfaceRef(panel: new PanelId($panel), kind: new SurfaceKindId('acme.runtime'), host: new HostEntityRef(type: $host, value: '42'), incarnation: new Incarnation(1));
    }
}
