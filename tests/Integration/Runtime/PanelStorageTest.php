<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use SocKit\Actors\Exceptions\ActorContextMismatchException;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Internal\Storage\OwnerConnection;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Operations\Internal\Storage\StorageBindingException;
use SocKit\Operations\OperationHandles;
use SocKit\Panels\Exceptions\PanelNotFoundException;
use SocKit\Panels\PanelStorage;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Tests\Fixtures\Runtime\RuntimeDatabase;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario as Runtime;
use SocKit\Tests\Integration\Support\PgTarget;

it('routes all four logical repositories to explicit owner tables and rolls back without touching B or default decoy', function (): void {
    $app = Runtime::app();
    Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        $db->install();
        $config = $app->make('config')->all();
        $handles = $app->make(OperationHandles::class);
        $storage = $app->make(OwnerStorage::class);
        $a = $storage->forHandle($handles->forSurface(Runtime::surface()));
        $b = $storage->forHandle($handles->forSurface(Runtime::surface('acme.b')));
        $wakeups = [];
        try {
            $a->transaction(function (OwnerConnection $owner) use (&$wakeups): void {
                foreach (PanelStorage::KERNEL_ENTITIES as $entity) {
                    $owner->table($entity)->insert(['id' => '42', 'payload' => 'a-fault']);
                }
                $owner->afterCommit(function () use (&$wakeups): void {
                    $wakeups[] = 'rolled-back';
                });
                throw new RuntimeException('injected owner fault');
            });
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe('injected owner fault');
        }
        foreach (PanelStorage::KERNEL_ENTITIES as $entity) {
            expect($a->table($entity)->count())->toBe(0);
        }
        expect($wakeups)->toBe([])->and($b->table('receipts')->value('payload'))->toBe('b-sentinel');
        $a->transaction(function (OwnerConnection $owner) use (&$wakeups): void {
            foreach (PanelStorage::KERNEL_ENTITIES as $entity) {
                $owner->table($entity)->insert(['id' => '42', 'payload' => 'a-committed']);
            }
            $owner->afterCommit(function () use (&$wakeups): void {
                $wakeups[] = 'committed';
            });
            expect($wakeups)->toBe([]);
        });
        expect($wakeups)->toBe(['committed']);
        foreach (PanelStorage::KERNEL_ENTITIES as $entity) {
            expect($b->table($entity)->count())->toBe($entity === 'receipts' ? 1 : 0);
            expect((int) PgTarget::query($db->decoy, 'SELECT count(*) FROM panel_a.driver_a_'.$entity)->fetchColumn())->toBe($entity === 'receipts' ? 1 : 0);
        }
        expect(PgTarget::query($db->decoy, 'SELECT payload FROM panel_a.driver_a_receipts')->fetchColumn())->toBe('decoy-sentinel');
        $decoy = $storage->forHandle($handles->forSurface(Runtime::surface('acme.decoy')));
        expect($decoy->table('receipts')->value('payload'))->toBe('decoy-sentinel');
        $mutations = array_values(array_filter($db->queries, static fn (array $query): bool => str_starts_with($query['sql'], 'insert')));
        expect($mutations)->toHaveCount(8);
        foreach ($mutations as $query) {
            expect($query['connection'])->toBe('owner_a')->and($query['sql'])->toStartWith('insert into "panel_a"."driver_a_');
            expect($query['sql'])->not()->toContain('driver_driver_')->not()->toContain('"driver_panel_a"');
        }
        foreach (PanelStorage::KERNEL_ENTITIES as $entity) {
            expect(array_column($mutations, 'sql'))->toContain('insert into "panel_a"."driver_a_'.$entity.'" ("id", "payload") values (?, ?)');
        }
        expect($app->make('config')->all())->toBe($config)
            ->and($db->capsule->getDatabaseManager()->getDefaultConnection())->toBe('decoy');
        fwrite(STDERR, 'P2.2 SQL assertions '.json_encode($db->queries, JSON_THROW_ON_ERROR).PHP_EOL);
    } finally {
        $db->cleanup();
    }
});

it('keeps simultaneous and nested A B A handles independent and rejects nested owner transactions before effects', function (): void {
    $app = Runtime::app();
    Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        $db->install();
        $handles = $app->make(OperationHandles::class);
        $storage = $app->make(OwnerStorage::class);
        $a = $storage->forHandle($handles->forSurface(Runtime::surface()));
        $b = $storage->forHandle($handles->forSurface(Runtime::surface('acme.b')));
        $a->transaction(function (OwnerConnection $owner) use ($a, $b): void {
            $owner->table('receipts')->insert(['id' => '42', 'payload' => 'a']);
            $b->transaction(function (OwnerConnection $other) use ($a): void {
                expect($other->table('receipts')->value('payload'))->toBe('b-sentinel')
                    ->and($a->table('receipts')->value('payload'))->toBe('a');
            });
            expect(fn () => $a->transaction(static fn (OwnerConnection $nested): bool => $nested->table('outbox')->insert(['id' => 'bad', 'payload' => 'bad'])))->toThrow(InvalidConfigurationException::class);
        });
        expect($a->table('outbox')->count())->toBe(0);
        // Two interleaved Fibers use explicit handles; no claim that scoped bindings isolate arbitrary host state.
        $readA = new Fiber(function () use ($a): void {
            Fiber::suspend($a->table('receipts')->value('payload'));
            expect($a->table('receipts')->value('payload'))->toBe('a');
        });
        $readB = new Fiber(function () use ($b): void {
            Fiber::suspend($b->table('receipts')->value('payload'));
            expect($b->table('receipts')->value('payload'))->toBe('b-sentinel');
        });
        expect($readA->start())->toBe('a')->and($readB->start())->toBe('b-sentinel');
        $readB->resume();
        $readA->resume();
        expect($db->capsule->getDatabaseManager()->getDefaultConnection())->toBe('decoy');
    } finally {
        $db->cleanup();
    }
});

it('rejects forged bindings namespaces and stale trusted contexts before mutation', function (): void {
    $app = Runtime::app();
    $fake = Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        $db->install();
        $handles = $app->make(OperationHandles::class);
        $storage = $app->make(OwnerStorage::class);
        $a = $handles->forSurface(Runtime::surface());
        $b = $handles->forSurface(Runtime::surface('acme.b'));
        $forged = new OperationHandle(surface: $a->surface, actor: $a->actor, panel: $b->panel, definition: $a->definition);
        expect(fn () => $storage->forHandle($forged))->toThrow(StorageBindingException::class)
            ->and(fn () => $storage->forHandle(handle: $a, namespace: new CommandNamespace(installation: $a->actor->installation, panel: $b->surface->panel, integration: 'acme.runtime', commandType: 'acme.write')))->toThrow(StorageBindingException::class)
            ->and(fn () => $storage->forHandle($a)->table('arbitrary_table'))->toThrow(InvalidConfigurationException::class);
        $owner = $storage->forHandle($a);
        $fake->actors->actingAs(Runtime::actor('99'));
        expect(fn () => $owner->transaction(static fn (OwnerConnection $connection): bool => $connection->table('receipts')->insert(['id' => 'forged', 'payload' => 'bad'])))->toThrow(ActorContextMismatchException::class);
        expect($db->queries)->toBe([]);
        $fake->actors->actingAs(Runtime::actor());
        expect($owner->table('receipts')->count())->toBe(0);
    } finally {
        $db->cleanup();
    }
});

it('rejects missing maps unknown connections and invalid final prefix identifiers without SQL', function (): void {
    $app = Runtime::app(complete: false);
    Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        expect(fn () => $app->make(OwnerStorage::class)->forHandle($app->make(OperationHandles::class)->forSurface(Runtime::surface())))->toThrow(InvalidConfigurationException::class);
        $completeApp = Runtime::app();
        Runtime::trust($completeApp);
        $handles = $completeApp->make(OperationHandles::class);
        expect(fn () => $handles->forSurface(Runtime::surface('acme.unknown')))->toThrow(PanelNotFoundException::class);
        $storage = new OwnerStorage(connections: $db->capsule->getDatabaseManager(), handles: $handles);
        foreach (['UPPER_', 'bad-prefix_', str_repeat('x', 62)] as $prefix) {
            $db->capsule->getConnection('owner_a')->setTablePrefix($prefix);
            expect(fn () => $storage->forHandle($handles->forSurface(Runtime::surface())))->toThrow(InvalidConfigurationException::class);
        }
        $db->capsule->getConnection('owner_a')->setTablePrefix('driver_');
        $bound = $storage->forHandle($handles->forSurface(Runtime::surface()));
        $db->capsule->getConnection('owner_a')->setTablePrefix('changed_');
        expect(fn () => $bound->table('receipts'))->toThrow(InvalidConfigurationException::class);
        $db->capsule->getConnection('owner_a')->setTablePrefix(str_repeat('x', 63 - strlen('a_surface_instances')));
        $storage->forHandle($handles->forSurface(Runtime::surface())); // Exactly 63 bytes is valid.
        $missing = new OwnerStorage(connections: new Manager()->getDatabaseManager(), handles: $handles);
        expect(fn () => $missing->forHandle($handles->forSurface(Runtime::surface())))->toThrow(InvalidConfigurationException::class);
        expect($db->queries)->toBe([]);
    } finally {
        $db->cleanup();
    }
});
