<?php

declare(strict_types=1);

use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Operations\Internal\Storage\OwnerConnection;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Operations\OperationHandles;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Tests\Fixtures\Runtime\LocalTargetAdapter;
use SocKit\Tests\Fixtures\Runtime\RuntimeDatabase;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario as Runtime;
use SocKit\Tests\Fixtures\Runtime\TargetAdapter;
use SocKit\Tests\Fixtures\Runtime\TargetScenario as Targets;
use SocKit\Tests\Integration\Support\PgTarget;

it('fences local host generation on the owner connection and never resolves remote authority under lock', function (): void {
    $app = Runtime::app();
    $fake = Runtime::trust($app);
    $fake->surfaces->allow(viewer: Runtime::actor(), surface: Runtime::surface());
    $db = new RuntimeDatabase($app); // PDO identities, marker, endpoint and version before all fixture DDL.
    try {
        $db->install();
        $db->owner->exec('CREATE TABLE panel_a.driver_host_targets (id text PRIMARY KEY, generation text NOT NULL, incarnation int NOT NULL, visible boolean NOT NULL, privacy_revision int NOT NULL)');
        $db->owner->exec("INSERT INTO panel_a.driver_host_targets VALUES ('00042', 'g1', 1, true, 1)");
        $adapter = $app->make(LocalTargetAdapter::class);
        $app->instance(TargetResolver::class, $adapter);
        $handles = $app->make(OperationHandles::class);
        $handle = $handles->forSurface(Runtime::surface());
        $sample = $handles->resolveTarget(handle: $handle, target: Targets::target('article'));
        expect($sample->generation)->toBe('g1')->and($sample->write->canWrite())->toBeTrue();
        $owner = $app->make(OwnerStorage::class)->forHandle($handle);
        expect($handles->recheckTarget(handle: $handle, resolved: $sample)->isAllowed())->toBeFalse();
        $owner->transaction(function (OwnerConnection $connection) use ($handles, $handle, $sample, $adapter): void {
            expect(fn () => $handles->resolveTarget(handle: $handle, target: $sample->target))->toThrow(InvalidConfigurationException::class);
            expect($handles->recheckTarget(handle: $handle, resolved: $sample)->isAllowed())->toBeTrue();
            $connection->table('receipts')->insert(['id' => 'fenced', 'payload' => 'fixture-only']);
            expect($adapter->calls)->toBe(1);
        });
        expect($adapter->fences)->toBe(1);
        // Revoke committed after sampling must reject the stale sample locally, with no second resolve.
        $db->owner->exec("UPDATE panel_a.driver_host_targets SET generation = 'g2', visible = false WHERE id = '00042'");
        $result = $owner->transaction(fn (): AuthorizationDecision => $handles->recheckTarget(handle: $handle, resolved: $sample));
        expect($result->isAllowed())->toBeFalse()->and($adapter->calls)->toBe(1);
        $db->owner->exec("UPDATE panel_a.driver_host_targets SET generation = 'g1', incarnation = 2, visible = true WHERE id = '00042'");
        $result = $owner->transaction(fn (): AuthorizationDecision => $handles->recheckTarget(handle: $handle, resolved: $sample));
        expect($result->isAllowed())->toBeFalse();
        $db->owner->exec("UPDATE panel_a.driver_host_targets SET incarnation = 1, privacy_revision = 2 WHERE id = '00042'");
        $result = $owner->transaction(fn (): AuthorizationDecision => $handles->recheckTarget(handle: $handle, resolved: $sample));
        expect($result->isAllowed())->toBeFalse();
        // A remote-only adapter never gains writes from a sampled allow.
        $app->instance(TargetResolver::class, new TargetAdapter);
        $result = $owner->transaction(fn (): AuthorizationDecision => $handles->recheckTarget(handle: $handle, resolved: $sample));
        expect($result->isAllowed())->toBeFalse()->and($result->reason)->toBe('target.local_fence_unavailable');
        $app->instance(TargetResolver::class, $adapter);
        $adapter->fenceFailure = true;
        $result = $owner->transaction(fn (): AuthorizationDecision => $handles->recheckTarget(handle: $handle, resolved: $sample));
        expect($result->isAllowed())->toBeFalse()->and($result->reason)->toBe('target.local_fence_error');
        expect(PgTarget::query($db->decoy, "SELECT payload FROM panel_a.driver_a_receipts WHERE id = '42'")->fetchColumn())->toBe('decoy-sentinel');
        $lockQueries = array_values(array_filter($db->queries, static fn (array $query): bool => str_contains($query['sql'], 'for update')));
        expect($lockQueries)->toHaveCount(4);
        foreach ($lockQueries as $query) {
            expect($query['connection'])->toBe('owner_a')->and($query['sql'])->toContain('"panel_a"."driver_host_targets"');
        }
        fwrite(STDERR, 'P2.3 SQL assertions: local fence SELECT FOR UPDATE on owner_a; stale generation/recycled incarnation/remote-only/failure denied; decoy unchanged'.PHP_EOL);
    } finally {
        $db->cleanup();
    }
});
