<?php

declare(strict_types=1);

use SocKit\Audience\Data\AudienceSpec;
use SocKit\Configuration\SocKitConfig;
use SocKit\Operations\Data\EventEnvelope;
use SocKit\Operations\Internal\Outbox\OutboxStore;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Operations\Internal\Storage\SurfaceInstances;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\OutboxRelay;
use SocKit\Support\Ids;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Runtime\SqlInboxConsumer;
use SocKit\Tests\Fixtures\Runtime\SurfaceScenario;
use SocKit\Tests\Fixtures\Schema\MigrationFixture;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('preserves installed data and history through disable and re-enable while fresh disabled panels have no module tables', function (): void {
    $f = new MigrationFixture;
    try {
        expect($f->command()['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0)
            ->and(PgTarget::query($f->pg->owner, "SELECT to_regclass('panel_a.driver_article_demo_targets')")->fetchColumn())->toBeNull();
        $f->boot(selected: true);
        expect($f->command(upgrade: true)['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        $f->pg->owner->exec("INSERT INTO panel_a.driver_article_demo_targets (id,installation,panel,external_key) VALUES ('019a1ba0-0000-7000-8000-000000000042','host','acme.article','preserve')");
        $before = $f->tree();
        $oid = PgTarget::query($f->pg->owner, "SELECT 'panel_a.driver_article_demo_targets'::regclass::oid")->fetchColumn();
        $f->boot(selected: false, enableModule: false);
        expect($f->command(upgrade: true)['data']['status'])->toBe('no_op')->and($f->tree())->toBe($before)
            ->and(PgTarget::query($f->pg->owner, 'SELECT external_key FROM panel_a.driver_article_demo_targets')->fetchColumn())->toBe('preserve');
        $f->boot(selected: true);
        expect($f->command(upgrade: true)['data']['status'])->toBe('no_op')->and($f->tree())->toBe($before)
            ->and(PgTarget::query($f->pg->owner, "SELECT 'panel_a.driver_article_demo_targets'::regclass::oid")->fetchColumn())->toBe($oid);
        fwrite(STDERR, "P3.6 SQL fresh disabled=zero module tables; disable preserved row/history; re-enable retained original table OID\n");
    } finally {
        $f->cleanup();
    }
});

it('durably quarantines a pending delivery after disable without losing the event or resolving its consumer', function (): void {
    $f = new MigrationFixture;
    try {
        $f->boot(selected: true, consumers: true);
        expect($f->command()['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        $fake = RuntimeScenario::trust($f->app);
        $actor = RuntimeScenario::actor();
        $surface = RuntimeScenario::surface('acme.article');
        $fake->actors->register($actor);
        $fake->surfaces->allow(viewer: $actor, surface: $surface);
        $handle = $f->app->make(OperationHandles::class)->forSurface($surface);
        $owner = $f->app->make(OwnerStorage::class)->forHandle($handle);
        SurfaceScenario::create($owner);
        $current = $owner->transaction(fn () => new SurfaceInstances($owner)->lock());
        $event = new EventEnvelope(eventId: Ids::uuid7(), sourceInstallation: $current->installation, panel: $surface->panel,
            sourceGeneration: '1', surface: $surface, sourceRevision: $current->revision, privacyRevision: $current->privacyGeneration,
            eventType: 'acme.runtime.changed', occurredAt: new DateTimeImmutable, payload: ['surface' => $current->resource()], audience: AudienceSpec::platform());
        $store = new OutboxStore(owner: $owner, config: $f->app->make(SocKitConfig::class)->events);
        $consumers = array_keys($f->app->make(OutboxRelay::class)->consumers($handle));
        $owner->transaction(fn () => $store->append(event: $event, consumers: $consumers));
        $encoded = PgTarget::query($f->pg->owner, 'SELECT envelope FROM panel_a.driver_article_outbox')->fetchColumn();
        $f->boot(selected: false, enableModule: false, consumers: false);
        expect($f->command(upgrade: true)['data']['status'])->toBe('no_op')
            ->and(PgTarget::query($f->pg->owner, 'SELECT envelope FROM panel_a.driver_article_outbox')->fetchColumn())->toBe($encoded);
        RuntimeScenario::trust($f->app);
        $f->app->bind(SqlInboxConsumer::class, static fn () => throw new RuntimeException('Disabled consumer resolved'));
        $disabled = $f->app->make(OperationHandles::class)->forSurface($surface);
        $result = $f->app->make(OutboxRelay::class)->poll($disabled);
        expect($result)->toHaveCount(1)->and($result[0]->status)->toBe('quarantine')->and($result[0]->reason)->toBe('outbox.consumer_unavailable');
        $deliveries = Scenario::object(json_decode(Scenario::text(PgTarget::query($f->pg->owner, 'SELECT deliveries FROM panel_a.driver_article_outbox')->fetchColumn()), true, flags: JSON_THROW_ON_ERROR));
        expect(Scenario::object($deliveries['acme.inbox'])['status'])->toBe('quarantine')
            ->and(Scenario::object($deliveries['acme.inbox'])['reason'])->toBe('outbox.consumer_unavailable')
            ->and(PgTarget::query($f->pg->owner, 'SELECT envelope FROM panel_a.driver_article_outbox')->fetchColumn())->toBe($encoded)
            ->and(PgTarget::query($f->pg->owner, 'SELECT count(*) FROM panel_a.driver_article_outbox')->fetchColumn())->toBe(1);
        $f->boot(selected: true, consumers: true);
        RuntimeScenario::trust($f->app);
        expect($f->command(upgrade: true)['data']['status'])->toBe('no_op')
            ->and($f->app->make(OutboxRelay::class)->poll($f->app->make(OperationHandles::class)->forSurface($surface)))->toBe([])
            ->and(PgTarget::query($f->pg->owner, 'SELECT envelope FROM panel_a.driver_article_outbox')->fetchColumn())->toBe($encoded);
        fwrite(STDERR, "P3.6 SQL pending event retained; disabled consumer never resolved; durable quarantine reason=outbox.consumer_unavailable; re-enable does not discard or redeliver quarantined rows\n");
    } finally {
        $f->cleanup();
    }
});
