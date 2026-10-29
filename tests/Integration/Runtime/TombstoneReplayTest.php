<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Assert;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Operations\Internal\Storage\SurfaceInstances;
use SocKit\Operations\Internal\Storage\SurfaceStateException;
use SocKit\Operations\OperationHandles;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Enums\SurfaceLifecycle;
use SocKit\Testing\SocKitFake;
use SocKit\Tests\Fixtures\Runtime\RuntimeDatabase;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario as Runtime;
use SocKit\Tests\Fixtures\Runtime\SurfaceScenario as Surfaces;

it('rejects reordered duplicate old generation events and explicitly recreates without losing tombstones or panel isolation', function (): void {
    $app = Runtime::app();
    $fake = Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        Surfaces::install($db);
        $a = Surfaces::owner($app);
        $b = Surfaces::owner(app: $app, panel: 'acme.b');
        Surfaces::create($a);
        Surfaces::create($b);
        $instances = new SurfaceInstances($a);
        $deleted = $a->transaction(fn () => $instances->transition(to: SurfaceLifecycle::Deleted, expectedRevision: new Revision(1), sourceGeneration: new Revision(5), privacyGeneration: new Revision(3)));
        $tombstone = $a->table('tombstones')->first();
        foreach (['create', 'transition', 'revise', 'writable'] as $operation) {
            try {
                $a->transaction(fn () => match ($operation) {
                    'create' => $instances->create(sourceGeneration: new Revision(1), privacyGeneration: new Revision(1)),
                    'transition' => $instances->transition(to: SurfaceLifecycle::Live, expectedRevision: new Revision(2), sourceGeneration: new Revision(6), privacyGeneration: new Revision(3)),
                    'revise' => $instances->revise(expectedRevision: new Revision(1), sourceGeneration: new Revision(1), privacyGeneration: new Revision(1)),
                    'writable' => $instances->writable(new Revision(2)),
                });
                Assert::fail('Old event resurrected deleted surface');
            } catch (SurfaceStateException $error) {
                expect($error->problem())->toBe(ProblemCode::ContentDeleted);
            }
        }
        $next = Surfaces::owner(app: $app, incarnation: 2);
        $new = new SurfaceInstances($next);
        foreach ([1, 5] as $oldGeneration) {
            try {
                $next->transaction(fn () => $new->recreate(expectedDeletedRevision: new Revision(2), sourceGeneration: new Revision($oldGeneration), privacyGeneration: new Revision(1)));
                Assert::fail('Old source generation passed');
            } catch (SurfaceStateException $error) {
                expect($error->problem())->toBe(ProblemCode::RevisionConflict);
            }
        }
        expect(fn () => $next->transaction(fn () => $new->create(sourceGeneration: new Revision(6), privacyGeneration: new Revision(1))))->toThrow(SurfaceStateException::class);
        $skipped = Surfaces::owner(app: $app, incarnation: 3);
        expect(fn () => $skipped->transaction(fn () => new SurfaceInstances($skipped)->recreate(expectedDeletedRevision: new Revision(2), sourceGeneration: new Revision(6), privacyGeneration: new Revision(1))))->toThrow(SurfaceStateException::class);
        expect(fn () => $next->transaction(fn () => $new->recreate(expectedDeletedRevision: new Revision(1), sourceGeneration: new Revision(6), privacyGeneration: new Revision(1))))->toThrow(SurfaceStateException::class);
        // Recreation rollback keeps both the deleted current identity and its tombstone intact.
        try {
            $next->transaction(function () use ($new): void {
                $new->recreate(expectedDeletedRevision: new Revision(2), sourceGeneration: new Revision(6), privacyGeneration: new Revision(1));
                throw new RuntimeException('fault after recreation');
            });
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe('fault after recreation');
        }
        expect($a->transaction(fn () => $instances->lock())->lifecycle)->toBe(SurfaceLifecycle::Deleted);
        expect($a->table('tombstones')->first())->toEqual($tombstone);
        $fresh = $next->transaction(fn () => $new->recreate(expectedDeletedRevision: new Revision(2), sourceGeneration: new Revision(6), privacyGeneration: new Revision(1)));
        expect($fresh->id)->not->toBe($deleted->id)->and($fresh->revision->value)->toBe(1)->and($fresh->ref->incarnation->value)->toBe(2);
        foreach (['lock', 'create', 'update'] as $operation) {
            try {
                $a->transaction(fn () => match ($operation) {
                    'lock' => $instances->lock(),
                    'create' => $instances->create(sourceGeneration: new Revision(7), privacyGeneration: new Revision(1)),
                    'update' => $instances->revise(expectedRevision: new Revision(1), sourceGeneration: new Revision(7), privacyGeneration: new Revision(1)),
                });
                Assert::fail('Stale incarnation passed');
            } catch (SurfaceStateException $error) {
                expect($error->problem())->toBe(ProblemCode::SurfaceIncarnationMismatch);
            }
        }
        // Even a matching new incarnation cannot apply an old source generation.
        try {
            $next->transaction(fn () => $new->revise(expectedRevision: new Revision(1), sourceGeneration: new Revision(5), privacyGeneration: new Revision(1)));
            Assert::fail('Generation regression passed');
        } catch (SurfaceStateException $error) {
            expect($error->problem())->toBe(ProblemCode::RevisionConflict);
        }
        expect($next->table('tombstones')->first())->toEqual($tombstone)->and($next->table('tombstones')->count())->toBe(1);
        $snapshotB = $b->transaction(fn () => new SurfaceInstances($b)->lock());
        expect($snapshotB->lifecycle)->toBe(SurfaceLifecycle::Live)->and($snapshotB->revision->value)->toBe(1)->and($b->table('tombstones')->count())->toBe(0);
        // Installation is part of SQL identity even in the same panel/table.
        $otherApp = Runtime::app();
        $otherFake = SocKitFake::install(app: $otherApp, installation: Runtime::actor(installation: 'other-test')->installation);
        $otherFake->actors->actingAs(Runtime::actor(installation: 'other-test'));
        $otherApp->instance(abstract: ConnectionResolverInterface::class, instance: $db->capsule->getDatabaseManager());
        $other = Surfaces::owner($otherApp);
        Surfaces::create($other);
        expect($other->table('surface_instances')->count())->toBe(2);
        expect($next->transaction(fn () => $new->lock())->id)->toBe($fresh->id);
        // A lossless key is not normalized onto host 42.
        $ref = Runtime::surface();
        $lossless = new SurfaceRef(panel: $ref->panel, kind: $ref->kind, host: new HostEntityRef(type: 'article', value: '00042:edge'), incarnation: $ref->incarnation);
        $different = $app->make(OwnerStorage::class)->forHandle($app->make(OperationHandles::class)->forSurface($lossless));
        Surfaces::create($different);
        expect($different->transaction(fn () => new SurfaceInstances($different)->lock())->ref->host->value)->toBe('00042:edge');
        Surfaces::decoyUntouched($db);
        fwrite(STDERR, "P2.4 SQL assertions: old/duplicate/generation denied; explicit incarnation 2 revision 1 new UUID; tombstone unchanged; panel B revision 1 Live; other installation and lossless host key isolated\n");
    } finally {
        $db->cleanup();
    }
});

it('rolls back instance and tombstone together if the tombstone writer fails', function (): void {
    $app = Runtime::app();
    Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        Surfaces::install($db);
        $owner = Surfaces::owner($app);
        Surfaces::create($owner);
        // Deterministic SQL failure between CAS update and tombstone persistence.
        $db->owner->exec('ALTER TABLE panel_a.driver_a_tombstones ADD CONSTRAINT fault CHECK (max_revision < 2)');
        expect(fn () => $owner->transaction(fn () => new SurfaceInstances($owner)->transition(to: SurfaceLifecycle::Deleted, expectedRevision: new Revision(1), sourceGeneration: new Revision(2), privacyGeneration: new Revision(2))))->toThrow(QueryException::class);
        $current = $owner->transaction(fn () => new SurfaceInstances($owner)->lock());
        expect($current->lifecycle)->toBe(SurfaceLifecycle::Live)->and($current->revision->value)->toBe(1)->and($owner->table('tombstones')->count())->toBe(0);
        Surfaces::decoyUntouched($db);
    } finally {
        $db->cleanup();
    }
});

it('does not resurrect a tombstoned ref even if the current row is missing and has no implicit purge', function (): void {
    $app = Runtime::app();
    Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        Surfaces::install($db);
        $owner = Surfaces::owner($app);
        Surfaces::create($owner);
        $owner->transaction(fn () => new SurfaceInstances($owner)->transition(to: SurfaceLifecycle::Deleted, expectedRevision: new Revision(1), sourceGeneration: new Revision(2), privacyGeneration: new Revision(2)));
        // Simulate inconsistent restore: a remaining tombstone must still prevent first creation.
        $db->owner->exec('DELETE FROM panel_a.driver_a_surface_instances');
        expect(fn () => $owner->transaction(fn () => new SurfaceInstances($owner)->create(sourceGeneration: new Revision(9), privacyGeneration: new Revision(1))))->toThrow(SurfaceStateException::class);
        expect($owner->table('surface_instances')->count())->toBe(0)->and($owner->table('tombstones')->count())->toBe(1);
    } finally {
        $db->cleanup();
    }
});
