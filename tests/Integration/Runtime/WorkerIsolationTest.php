<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Events\QueryExecuted;
use SocKit\Actors\ActorContexts;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Data\OperationJobReference;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\OperationJobs;
use SocKit\Tests\Fixtures\Runtime\EnvelopeMutation;
use SocKit\Tests\Fixtures\Runtime\WorkerScenario;
use SocKit\Tests\Integration\Support\PgTarget;

it('runs scalar jobs A B A in one process with fresh actors and separate owner rows', function (): void {
    $s = new WorkerScenario;
    try {
        $configA = $s->a->make('config')->all();
        $configB = $s->b->make('config')->all();
        $a = $s->job();
        $b = $s->job(b: true);
        expect(OperationJobReference::fromWire($a->toWire())->toWire())->toBe($a->toWire());
        $before = $s->actorA->resolutions;
        expect($s->run($a)->isSuccess())->toBeTrue()->and($s->run(job: $b, b: true)->isSuccess())->toBeTrue();
        $s->base->base->db->owner->exec("UPDATE panel_a.driver_worker_actors SET generation='2', age='minor' WHERE id='7'");
        $seen = null;
        $result = new OperationJobs($s->a)->execute(job: $s->job(key: 'worker.original.2', revision: 2), factory: function (OperationHandle $handle) use ($s, &$seen): EnvelopeMutation {
            $seen = $handle->actor;

            return $s->mutation();
        });
        expect($result->isSuccess())->toBeTrue()->and($seen?->generation)->toBe('2')->and($seen?->ageBand->value)->toBe('minor')
            ->and($seen?->provenance)->toBe(ContextProvenance::Worker)->and($s->actorA->resolutions)->toBeGreaterThan($before)
            ->and($s->a->make(ActorContexts::class)->current()->actor?->id)->toBe('caller')
            ->and($s->counts())->toBe(['effects' => 2, 'revision' => 3, 'receipts' => 2, 'outbox' => 2])
            ->and($s->counts(true))->toBe(['effects' => 1, 'revision' => 2, 'receipts' => 1, 'outbox' => 1]);
        expect($s->a->make('config')->all())->toBe($configA)->and($s->b->make('config')->all())->toBe($configB);
        foreach ([$s->a, $s->b] as $app) {
            expect($app->make(ConnectionResolverInterface::class)->getDefaultConnection())->toBe('decoy');
        }
        $pdo = $s->base->base->db->owner;
        foreach ([['panel_a.driver_a_', 'user:7', 'acme.a'], ['panel_b.other_b_', 'user:8', 'acme.b']] as [$prefix, $actor, $panel]) {
            expect(PgTarget::query($pdo, 'SELECT DISTINCT actor_ref FROM '.$prefix.'receipts')->fetchAll(PDO::FETCH_COLUMN))->toBe([$actor])
                ->and(PgTarget::query($pdo, 'SELECT DISTINCT panel FROM '.$prefix.'outbox')->fetchAll(PDO::FETCH_COLUMN))->toBe([$panel]);
        }
    } finally {
        $s->cleanup();
    }
});

it('keeps caller context after factory exceptions and real B write rollback', function (): void {
    $s = new WorkerScenario;
    try {
        $a = $s->job();
        $b = $s->job(b: true);
        expect($s->run($a)->isSuccess())->toBeTrue();
        $actors = $s->b->make(ActorContexts::class);
        $jobs = new OperationJobs($s->b);
        expect($jobs->execute(job: $b, factory: static function (): never {
            throw new RuntimeException('B factory exception');
        })->isSuccess())->toBeFalse();
        $armed = true;
        $s->dbB->capsule->getConnection('owner_b')->listen(function (QueryExecuted $q) use (&$armed): void {
            if ($armed && str_starts_with($q->sql, 'update') && str_contains($q->sql, 'envelope_state')) {
                $armed = false;
                throw new RuntimeException('B SQL persist exception');
            }
        });
        expect($s->run(job: $b, b: true)->isSuccess())->toBeFalse()->and($armed)->toBeFalse()
            ->and($s->b->make(ActorContexts::class))->toBe($actors)
            ->and($s->counts(true))->toBe(['effects' => 0, 'revision' => 1, 'receipts' => 0, 'outbox' => 0]);
        expect($s->run($s->job(key: 'worker.after.exception', revision: 2))->isSuccess())->toBeTrue()
            ->and($s->counts())->toBe(['effects' => 2, 'revision' => 3, 'receipts' => 2, 'outbox' => 2]);
    } finally {
        $s->cleanup();
    }
});

it('supports nested B handle during A factory without replacing either caller frame', function (): void {
    $s = new WorkerScenario;
    try {
        $a = $s->job();
        $b = $s->job(b: true);
        $caller = $s->a->make(ActorContexts::class);
        $result = new OperationJobs($s->a)->execute(job: $a, factory: function (OperationHandle $handle) use ($s, $b): EnvelopeMutation {
            expect($handle->actor->actor?->id)->toBe('7')->and($s->run(job: $b, b: true)->isSuccess())->toBeTrue()
                ->and($handle->surface->panel->value)->toBe('acme.a');

            return $s->mutation();
        });
        expect($result->isSuccess())->toBeTrue()->and($s->a->make(ActorContexts::class))->toBe($caller)
            ->and($s->counts()['effects'])->toBe(1)->and($s->counts(true)['effects'])->toBe(1);
    } finally {
        $s->cleanup();
    }
});

it('interleaves Fibers at manual barriers with independent PDO transactions', function (): void {
    $s = new WorkerScenario;
    try {
        $a = $s->job();
        $b = $s->job(b: true);
        $make = (fn (bool $isB, OperationJobReference $job): Fiber => new Fiber(fn () => new OperationJobs($isB ? $s->b : $s->a)->execute(job: $job, factory: function (OperationHandle $handle) use ($s, $isB): EnvelopeMutation {
            Fiber::suspend([$handle->surface->panel->value, $handle->actor->actor?->id]);
            expect($handle->actor->actor?->id)->toBe($isB ? '8' : '7');

            return $s->mutation($isB);
        })));
        $fa = $make(isB: false, job: $a);
        $fb = $make(isB: true, job: $b);
        expect($fa->start())->toBe(['acme.a', '7'])->and($fb->start())->toBe(['acme.b', '8']);
        foreach ([[$s->base->base->db, 'owner_a'], [$s->dbB, 'owner_b']] as [$db, $name]) {
            $db->capsule->getConnection($name)->listen(static function (QueryExecuted $q): void {
                if (str_starts_with($q->sql, 'update') && str_contains($q->sql, 'envelope_state')) {
                    Fiber::suspend('inside owner transaction');
                }
            });
        }
        expect($fa->resume())->toBe('inside owner transaction')->and($fb->resume())->toBe('inside owner transaction')
            ->and($s->base->base->db->capsule->getConnection('owner_a')->transactionLevel())->toBe(1)
            ->and($s->dbB->capsule->getConnection('owner_b')->transactionLevel())->toBe(1);
        $fb->resume();
        $fa->resume();
        $ra = $fa->getReturn();
        $rb = $fb->getReturn();
        if (! $ra instanceof OperationResult || ! $rb instanceof OperationResult) {
            throw new RuntimeException('Fiber returned no operation result');
        }
        expect($ra->isSuccess())->toBeTrue()->and($rb->isSuccess())->toBeTrue()
            ->and($s->counts()['effects'])->toBe(1)->and($s->counts(true)['effects'])->toBe(1)
            ->and($s->base->base->db->capsule->getConnection('owner_a')->getPdo())->not->toBe($s->dbB->capsule->getConnection('owner_b')->getPdo());
    } finally {
        $s->cleanup();
    }
});

it('rejects nested owner transaction and preserves the callers uncommitted write', function (): void {
    $s = new WorkerScenario;
    try {
        $job = $s->job();
        $sql = $s->base->base->db->capsule->getConnection('owner_a');
        $sql->beginTransaction();
        try {
            $sql->table('panel_a.envelope_state')->where('target', '42')->update(['effects' => 91]);
            $called = false;
            $result = new OperationJobs($s->a)->execute(job: $job, factory: function () use (&$called, $s): EnvelopeMutation {
                $called = true;

                return $s->mutation();
            });
            expect($result->problem)->toBe(ProblemCode::ConfigurationInvalid)->and($called)->toBeFalse()->and($sql->transactionLevel())->toBe(1)
                ->and($sql->table('panel_a.envelope_state')->where('target', '42')->value('effects'))->toBe(91);
        } finally {
            $sql->rollBack();
        }
        expect($s->run($job)->isSuccess())->toBeTrue()->and($s->counts()['effects'])->toBe(1);
    } finally {
        $s->cleanup();
    }
});

it('isolates reentrant calls on the same worker container after nested throw and success', function (): void {
    $s = new WorkerScenario;
    try {
        $outer = $s->job(key: 'worker.outer.actor.7', revision: 2);
        $inner = $s->job(key: 'worker.inner.actor.8', principal: '8');
        $jobs = new OperationJobs($s->a);
        $caller = $s->a->make(ActorContexts::class);
        $result = $jobs->execute(job: $outer, factory: function (OperationHandle $handle) use ($s, $jobs, $inner): EnvelopeMutation {
            $failed = $jobs->execute(job: $inner, factory: static function (OperationHandle $nested): never {
                expect($nested->actor->actor?->id)->toBe('8');
                throw new RuntimeException('nested same container exception');
            });
            expect($failed->isSuccess())->toBeFalse()->and($s->run($inner)->isSuccess())->toBeTrue()
                ->and($handle->actor->actor?->id)->toBe('7');

            return $s->mutation();
        });
        expect($result->isSuccess())->toBeTrue()->and($s->a->make(ActorContexts::class))->toBe($caller)
            ->and($s->counts())->toBe(['effects' => 2, 'revision' => 3, 'receipts' => 2, 'outbox' => 2])
            ->and($s->counts(true)['effects'])->toBe(0);
        $retry = $s->run($outer);
        expect($retry->toWire())->toBe($result->toWire())->and($s->counts()['effects'])->toBe(2);
    } finally {
        $s->cleanup();
    }
});
