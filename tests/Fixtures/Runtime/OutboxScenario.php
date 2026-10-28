<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Application;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Configuration\SocKitConfig;
use SocKit\Modules\Modules;
use SocKit\Operations\Data\EventEnvelope;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Internal\Outbox\OutboxStore;
use SocKit\Operations\Internal\Storage\OwnerConnection;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Operations\Internal\Storage\SurfaceInstances;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\OutboxRelay;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Ids;
use SocKit\Testing\SocKitFake;
use SocKit\Tests\Integration\Support\PgTarget;

final class OutboxScenario
{
    public readonly Application $app;

    public readonly SocKitFake $fake;

    public readonly RuntimeDatabase $db;

    public readonly SqlInboxConsumer $consumer;

    public function __construct(string $generation = '1', bool $selected = true, bool $install = true)
    {
        $this->app = self::app(generation: $generation, selected: $selected);
        $this->fake = RuntimeScenario::trust($this->app);
        $this->fake->actors->register(RuntimeScenario::actor());
        $this->fake->authorizer->allow('sockit.events.deliver');
        $this->db = new RuntimeDatabase($this->app);
        if ($install) {
            SurfaceScenario::install($this->db);
            foreach (['panel_a.driver_a_', 'panel_b.other_b_'] as $prefix) {
                $this->db->owner->exec('DROP TABLE '.$prefix.'outbox');
                $this->db->owner->exec('CREATE TABLE '.$prefix.'outbox (id uuid PRIMARY KEY, installation varchar(64) COLLATE "C" NOT NULL, panel varchar(96) COLLATE "C" NOT NULL, kind text NOT NULL, host_type text NOT NULL, host_key text NOT NULL, incarnation bigint NOT NULL, source_generation varchar(64) COLLATE "C" NOT NULL, event_id uuid NOT NULL, digest text NOT NULL, envelope text NOT NULL, deliveries jsonb NOT NULL, UNIQUE (installation,panel,source_generation,event_id))');
            }
            $this->db->owner->exec('CREATE SCHEMA acme_consumer');
            foreach (['inbox', 'effects', 'projections'] as $table) {
                $this->db->owner->exec('CREATE TABLE acme_consumer.'.$table.' (consumer text COLLATE "C" NOT NULL, installation text COLLATE "C" NOT NULL, panel text COLLATE "C" NOT NULL, source_generation text COLLATE "C" NOT NULL, event_id uuid NOT NULL, digest text NOT NULL, PRIMARY KEY (consumer,installation,panel,source_generation,event_id))');
            }
            SurfaceScenario::create($this->owner());
            SurfaceScenario::create($this->owner('acme.b'));
            fwrite(STDERR, "P2.6 consumer-owned SQL snapshots installed AFTER verified identities\n");
        }
        $this->consumer = new SqlInboxConsumer(owner: $this->db->capsule->getConnection('owner_a'), consumer: PgTarget::fromEnvironment()->connect());
        $this->app->bind(abstract: SqlInboxConsumer::class, concrete: fn (): SqlInboxConsumer => $this->consumer);
    }

    public static function app(string $generation = '1', bool $selected = true): Application
    {
        $app = RuntimeScenario::app(boot: false);
        $config = $app->make('config');
        $config->set(key: 'sockit.events', value: ['source_generation' => $generation, 'lease_seconds' => 1, 'retry_seconds' => 1, 'max_attempts' => 3]);
        $config->set(key: 'sockit.modules.enabled', value: ['acme.runtime']);
        $app->instance(abstract: RuntimePanel::class, instance: new OutboxPanel(selected: $selected ? ['acme.runtime'] : []));
        $app->instance(abstract: AlternatePanel::class, instance: new OutboxPanel(id: 'acme.b', connection: 'owner_b', schema: 'panel_b', prefix: 'b_'));
        Modules::add(app: $app, modules: OutboxModule::class);
        $app->boot();

        return $app;
    }

    public function handle(string $panel = 'acme.a'): OperationHandle
    {
        $surface = RuntimeScenario::surface($panel);
        $this->fake->surfaces->allow(viewer: RuntimeScenario::actor(), surface: $surface);

        return $this->app->make(OperationHandles::class)->forSurface($surface);
    }

    public function owner(string $panel = 'acme.a'): OwnerConnection
    {
        return $this->app->make(OwnerStorage::class)->forHandle($this->handle($panel));
    }

    public function store(string $panel = 'acme.a'): OutboxStore
    {
        return new OutboxStore(owner: $this->owner($panel), config: $this->app->make(SocKitConfig::class)->events);
    }

    public function event(string $panel = 'acme.a', ?string $id = null): EventEnvelope
    {
        $owner = $this->owner($panel);
        $current = $owner->transaction(fn () => new SurfaceInstances($owner)->lock());
        $event = new EventEnvelope(eventId: $id ?? Ids::uuid7(), sourceInstallation: $current->installation, panel: $current->ref->panel,
            sourceGeneration: $this->app->make(SocKitConfig::class)->events->sourceGeneration, surface: $current->ref,
            sourceRevision: $current->revision, privacyRevision: $current->privacyGeneration, eventType: 'acme.runtime.changed',
            occurredAt: new DateTimeImmutable('2026-10-28 12:00:00', new DateTimeZone('UTC')), payload: ['surface' => $current->resource()], audience: AudienceSpec::platform());
        $this->fake->audience->verdict(key: $event->eventId, verdict: AudienceVerdict::allow('audience.allowed.platform'));

        return $event;
    }

    public function append(EventEnvelope $event): void
    {
        $owner = $this->owner($event->panel->value);
        $ids = array_keys($this->app->make(OutboxRelay::class)->consumers($owner->handle()));
        $store = new OutboxStore(owner: $owner, config: $this->app->make(SocKitConfig::class)->events);
        $owner->transaction(fn () => $store->append(event: $event, consumers: $ids));
    }

    /** @param array<string, mixed> $changes */
    public static function rewire(EventEnvelope $event, array $changes): EventEnvelope
    {
        $wire = array_replace($event->toWire(), $changes);
        unset($wire['digest']);
        if (! is_string($wire['event_type'])) {
            throw new \RuntimeException('Invalid fixture event type');
        }
        $wire['digest'] = PayloadDigest::of(commandType: $wire['event_type'], payload: $wire)->value;

        return EventEnvelope::fromWire($wire);
    }

    public function count(string $table = 'effects'): int
    {
        return (int) PgTarget::query(pdo: $this->db->owner, sql: 'SELECT count(*) FROM acme_consumer.'.$table)->fetchColumn();
    }

    public function cleanup(): void
    {
        $this->db->owner->exec('DROP SCHEMA IF EXISTS acme_consumer CASCADE');
        SurfaceScenario::decoyUntouched($this->db);
        $this->db->cleanup();
    }
}
