<?php

declare(strict_types=1);

use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Data\ReceiptSnapshot;
use SocKit\Operations\Internal\Receipts\ReceiptLedger;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Operations\OperationHandles;
use SocKit\Support\Data\InstallationRef;
use SocKit\Testing\SocKitFake;
use SocKit\Tests\Fixtures\Runtime\ReceiptScenario as Receipts;
use SocKit\Tests\Fixtures\Runtime\RuntimeDatabase;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario as Runtime;
use SocKit\Tests\Integration\Support\PgTarget;

it('blocks on unique identity and resolves same digest conflict rollback and foreign actor', function (string $mode): void {
    $app = Runtime::app();
    Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        Receipts::install($db);
        $results = Receipts::race(db: $db, mode: $mode);
        foreach ($results as $result) {
            expect($result['usable_inside'])->toBeTrue()->and($result['usable'])->toBeTrue()->and($result['transaction_level'])->toBe(0);
        }
        if ($mode === 'same') {
            expect($results[0]['writes'])->toBe(1)->and($results[1]['writes'])->toBe(0)
                ->and($results[0]['resource'])->toBe($results[1]['resource'])
                ->and($results[0]['revision'])->toBe($results[1]['revision'])
                ->and($results[0]['receipt'])->toBe($results[1]['receipt']);
        } elseif ($mode === 'rollback') {
            expect($results[0]['rollback'])->toBeTrue()->and($results[1]['writes'])->toBe(1)->and($results[1]['outcome'])->toBe('confirmed');
        } else {
            expect($results[0]['writes'])->toBe(1)->and($results[1]['writes'])->toBe(0)
                ->and($results[1]['problem'])->toBe($mode === 'actor' ? 'sockit.actor_context_invalid' : 'sockit.idempotency_conflict');
        }
        Receipts::counts(db: $db, effects: 1, receipts: 1);
    } finally {
        $db->cleanup();
    }
})->with(['same', 'conflict', 'rollback', 'actor']);

it('isolates installation panel integration command type and exact case sensitive lossless keys', function (): void {
    $app = Runtime::app();
    $fake = Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        Receipts::install($db);
        foreach ([['acme.a', 'runtime-test', 'acme.client', Receipts::TYPE, 'Req.2026-10-28.0001'], ['acme.a', 'runtime-test', 'acme.client', Receipts::TYPE, 'req.2026-10-28.0001'], ['acme.b', 'runtime-test', 'acme.client', Receipts::TYPE, 'req.2026-10-28.0001'], ['acme.a', 'runtime-test', 'acme.other', Receipts::TYPE, 'req.2026-10-28.0001'], ['acme.a', 'runtime-test', 'acme.client', 'acme.runtime.other', 'req.2026-10-28.0001'], ['acme.a', 'runtime-other', 'acme.client', Receipts::TYPE, 'req.2026-10-28.0001'], ['acme.a', 'runtime-other', 'acme.client', Receipts::TYPE, str_repeat('X', 128)], ['acme.a', str_repeat('R', 64), str_repeat('i', 64), str_repeat('c', 120), str_repeat('K', 128)]] as [$panel, $installation, $integration, $type, $key]) {
            $fake = SocKitFake::install(app: $app, installation: new InstallationRef($installation));
            $app->forgetInstance(OperationHandles::class);
            $app->forgetInstance(OwnerStorage::class);
            $fake->actors->actingAs(Runtime::actor(installation: $installation));
            $owner = Receipts::owner(app: $app, panel: $panel);
            $envelope = Receipts::envelope(panel: $panel, installation: $installation, integration: $integration, key: $key, type: $type);
            $snapshot = new ReceiptLedger($owner)->transaction(envelope: $envelope, commandType: $type, payload: ['value' => 1], write: fn (string $id): OperationResult => Receipts::effect(owner: $owner, id: $id));
            expect($snapshot)->toBeInstanceOf(ReceiptSnapshot::class)->and($snapshot->namespace->toWire())->toBe($envelope->namespace->toWire())->and($snapshot->keyHash)->toBe($envelope->key->hashForLog());
        }
        Receipts::counts(db: $db, effects: 7, receipts: 7);
        expect((int) PgTarget::query($db->owner, 'SELECT count(*) FROM panel_b.other_b_receipts')->fetchColumn())->toBe(1);
        $claims = array_values(array_filter($db->queries, static fn (array $query): bool => str_starts_with($query['sql'], 'INSERT INTO')));
        expect($claims)->toHaveCount(8);
        foreach ($claims as $query) {
            expect($query['connection'])->toBeIn(['owner_a', 'owner_b'])->and($query['sql'])->toContain('ON CONFLICT (namespace, command_key) DO NOTHING RETURNING id');
        }
        fwrite(STDERR, 'P2.5 routed SQL without bindings '.json_encode($claims, JSON_THROW_ON_ERROR).PHP_EOL);
    } finally {
        $db->cleanup();
    }
});
