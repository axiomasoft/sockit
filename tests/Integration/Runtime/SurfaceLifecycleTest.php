<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Internal\Storage\SurfaceInstances;
use SocKit\Operations\Internal\Storage\SurfaceStateException;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Enums\SurfaceLifecycle;
use SocKit\Tests\Fixtures\Runtime\RuntimeDatabase;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario as Runtime;
use SocKit\Tests\Fixtures\Runtime\SurfaceScenario as Surfaces;
use SocKit\Tests\Integration\Support\PgTarget;

it('keeps typed owner snapshots CAS and Live Archived Deleted transitions with rollback', function (): void {
    $app = Runtime::app();
    Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        Surfaces::install($db);
        $owner = Surfaces::owner($app);
        $instances = new SurfaceInstances($owner);
        expect(fn () => $instances->create(sourceGeneration: new Revision(1), privacyGeneration: new Revision(1)))->toThrow(InvalidConfigurationException::class);
        try {
            $owner->transaction(function () use ($instances): void {
                $instances->create(sourceGeneration: new Revision(1), privacyGeneration: new Revision(1));
                throw new RuntimeException('fault after creation');
            });
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe('fault after creation');
        }
        expect($owner->table('surface_instances')->count())->toBe(0);
        $first = $owner->transaction(fn () => $instances->create(sourceGeneration: new Revision(1), privacyGeneration: new Revision(1)));
        expect($first->revision->value)->toBe(1)->and($first->ref->toWire())->toBe(Runtime::surface()->toWire())
            ->and($first->installation->id)->toBe('runtime-test')->and($first->resource()->panel->value)->toBe('acme.a')
            ->and($first->lifecycle->canRead())->toBeTrue()->and($first->lifecycle->canWrite())->toBeTrue()
            ->and($first->createdAt->getOffset())->toBe(0);
        $archived = $owner->transaction(fn () => $instances->transition(to: SurfaceLifecycle::Archived, expectedRevision: new Revision(1), sourceGeneration: new Revision(2), privacyGeneration: new Revision(2)));
        expect($archived->revision->value)->toBe(2)->and($archived->lifecycle->canRead())->toBeTrue()->and($archived->lifecycle->canWrite())->toBeFalse();
        try {
            $owner->transaction(fn () => $instances->writable(new Revision(2)));
            Assert::fail('Archived write must fail');
        } catch (SurfaceStateException $error) {
            expect($error->problem())->toBe(ProblemCode::SurfaceArchived);
        }
        expect(fn () => $owner->transaction(fn () => $instances->revise(expectedRevision: new Revision(2), sourceGeneration: new Revision(2), privacyGeneration: new Revision(3))))->toThrow(SurfaceStateException::class);
        foreach ([[1, 2], [2, 1]] as [$revision, $generation]) {
            try {
                $owner->transaction(fn () => $instances->transition(to: SurfaceLifecycle::Live, expectedRevision: new Revision($revision), sourceGeneration: new Revision($generation), privacyGeneration: new Revision(2)));
                Assert::fail('Stale revision/generation must fail');
            } catch (SurfaceStateException $error) {
                expect($error->problem())->toBe(ProblemCode::RevisionConflict);
            }
        }
        $restored = $owner->transaction(fn () => $instances->transition(to: SurfaceLifecycle::Live, expectedRevision: new Revision(2), sourceGeneration: new Revision(3), privacyGeneration: new Revision(3)));
        expect($restored->revision->value)->toBe(3)->and($restored->id)->toBe($first->id);
        $edited = $owner->transaction(fn () => $instances->revise(expectedRevision: new Revision(3), sourceGeneration: new Revision(3), privacyGeneration: new Revision(4)));
        expect($edited->revision->value)->toBe(4)->and($edited->privacyGeneration->value)->toBe(4);
        try {
            $owner->transaction(function () use ($instances): void {
                $instances->transition(to: SurfaceLifecycle::Deleted, expectedRevision: new Revision(4), sourceGeneration: new Revision(4), privacyGeneration: new Revision(4));
                throw new RuntimeException('fault after tombstone');
            });
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe('fault after tombstone');
        }
        expect($owner->table('tombstones')->count())->toBe(0);
        expect($owner->transaction(fn () => $instances->lock())->lifecycle)->toBe(SurfaceLifecycle::Live);
        $deleted = $owner->transaction(fn () => $instances->transition(to: SurfaceLifecycle::Deleted, expectedRevision: new Revision(4), sourceGeneration: new Revision(4), privacyGeneration: new Revision(4)));
        expect($deleted->revision->value)->toBe(5)->and($deleted->lifecycle->canRead())->toBeFalse()->and($deleted->lifecycle->canWrite())->toBeFalse()->and($deleted->deletedAt)->not->toBeNull();
        $tombstone = $owner->table('tombstones')->first();
        if ($tombstone === null) {
            throw new RuntimeException('Missing committed tombstone');
        }
        expect($tombstone->max_revision)->toBe(5)->and($tombstone->source_generation)->toBe(4)->and($tombstone->surface_id)->toBe($first->id);
        // Only complete identity and deletion metadata: no content or actor PII.
        expect(array_keys((array) $tombstone))->toBe(['installation', 'panel', 'kind', 'host_type', 'host_key', 'incarnation', 'surface_id', 'max_revision', 'source_generation', 'deleted_at']);
        Surfaces::decoyUntouched($db);
        $sql = array_values(array_filter($db->queries, static fn (array $q): bool => str_starts_with($q['sql'], 'update')));
        expect($sql)->not->toBeEmpty();
        foreach ($sql as $query) {
            expect($query['connection'])->toBe('owner_a')->and($query['sql'])->toContain('"panel_a"."driver_a_surface_instances"')->toContain('"revision" = ?')->toContain('"incarnation" = ?')->toContain('"installation" = ?')->toContain('"panel" = ?')->toContain('"host_key" = ?');
        }
        fwrite(STDERR, 'P2.4 SQL CAS assertions '.json_encode($sql, JSON_THROW_ON_ERROR).PHP_EOL);
    } finally {
        $db->cleanup();
    }
});

it('arbitrates concurrent explicit creation by UNIQUE without an absent row claim lock', function (): void {
    $app = Runtime::app();
    Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        Surfaces::install($db);
        expect(Surfaces::race(db: $db, holder: 'create', waiter: 'create'))->toBe(['committed', 'sockit.revision_conflict']);
        expect((int) PgTarget::query($db->owner, 'SELECT count(*) FROM panel_a.driver_a_surface_instances')->fetchColumn())->toBe(1);
        Surfaces::decoyUntouched($db);
    } finally {
        $db->cleanup();
    }
});

it('serializes interfering lifecycle and edit operations on the surface row', function (string $holder, string $waiter, string $result, int $revision, string $lifecycle): void {
    $app = Runtime::app();
    Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        Surfaces::install($db);
        Surfaces::create(Surfaces::owner($app));
        expect(Surfaces::race(db: $db, holder: $holder, waiter: $waiter))->toBe(['committed', $result]);
        $row = PgTarget::query($db->owner, 'SELECT revision,lifecycle FROM panel_a.driver_a_surface_instances')->fetch(PDO::FETCH_ASSOC);
        expect($row)->toBe(['revision' => $revision, 'lifecycle' => $lifecycle]);
        Surfaces::decoyUntouched($db);
    } finally {
        $db->cleanup();
    }
})->with([
    'archive vs archive one CAS winner' => ['archive', 'archive', 'sockit.revision_conflict', 2, 'archived'],
    'edit vs edit one CAS winner' => ['edit', 'edit', 'sockit.revision_conflict', 2, 'live'],
    'edit vs archive one CAS winner' => ['edit', 'archive', 'sockit.revision_conflict', 2, 'live'],
    'archive before write' => ['archive', 'write', 'sockit.surface_archived', 2, 'archived'],
    'write before archive' => ['write', 'archive', 'committed', 2, 'archived'],
    'archive before edit' => ['archive', 'edit', 'sockit.surface_archived', 2, 'archived'],
    'delete before replay' => ['delete', 'replay', 'sockit.content_deleted', 2, 'deleted'],
    'replay before delete' => ['replay', 'delete', 'committed', 2, 'deleted'],
]);

it('deletes an archived instance and rejects missing and regressing policy state', function (): void {
    $app = Runtime::app();
    Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        Surfaces::install($db);
        $owner = Surfaces::owner($app);
        $instances = new SurfaceInstances($owner);
        try {
            $owner->transaction(fn () => $instances->lock());
            Assert::fail('Missing surface passed');
        } catch (SurfaceStateException $error) {
            expect($error->problem())->toBe(ProblemCode::SurfaceNotFound);
        }
        Surfaces::create($owner);
        $owner->transaction(fn () => $instances->revise(expectedRevision: new Revision(1), sourceGeneration: new Revision(1), privacyGeneration: new Revision(2)));
        expect(fn () => $owner->transaction(fn () => $instances->revise(expectedRevision: new Revision(2), sourceGeneration: new Revision(1), privacyGeneration: new Revision(1))))->toThrow(SurfaceStateException::class);
        $owner->transaction(fn () => $instances->transition(to: SurfaceLifecycle::Archived, expectedRevision: new Revision(2), sourceGeneration: new Revision(1), privacyGeneration: new Revision(2)));
        $deleted = $owner->transaction(fn () => $instances->transition(to: SurfaceLifecycle::Deleted, expectedRevision: new Revision(3), sourceGeneration: new Revision(2), privacyGeneration: new Revision(2)));
        expect($deleted->lifecycle)->toBe(SurfaceLifecycle::Deleted)->and($deleted->revision->value)->toBe(4)->and($owner->table('tombstones')->count())->toBe(1);
    } finally {
        $db->cleanup();
    }
});
