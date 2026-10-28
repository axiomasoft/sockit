<?php

declare(strict_types=1);

use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Errors\Contracts\ProvidesProblem;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Data\OperationJobReference;
use SocKit\Operations\OperationJobs;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Testing\FakeTenantResolver;
use SocKit\Tests\Fixtures\Runtime\EnvelopeMutation;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Runtime\WorkerScenario;

it('denies changed current facts before effect and before stale receipt output', function (string $change, bool $replay): void {
    $s = new WorkerScenario;
    try {
        $job = $s->job();
        if ($replay) {
            expect($s->run($job)->isSuccess())->toBeTrue();
        }
        $pdo = $s->base->base->db->owner;
        match ($change) {
            'permission' => $pdo->exec('UPDATE panel_a.driver_envelope_authority SET allowed=false, generation=generation+1'),
            'actor' => $pdo->exec("DELETE FROM panel_a.driver_worker_actors WHERE id='7'"),
            'isolation' => $s->a->instance(abstract: TenantResolver::class, instance: new FakeTenantResolver(new InstallationRef('another-installation'))),
            'source' => $job = OperationJobReference::fromWire(array_replace($job->toWire(), ['source_generation' => 'old-source'])),
            'target_incarnation' => $pdo->exec("UPDATE panel_a.driver_envelope_targets SET incarnation=2 WHERE id='42'"),
            'surface_incarnation' => $pdo->exec('UPDATE panel_a.driver_a_surface_instances SET incarnation=2'),
            'target_generation' => $pdo->exec("UPDATE panel_a.driver_envelope_targets SET generation='changed' WHERE id='42'"),
            default => throw new LogicException('Unknown test change'),
            'admission' => $s->base->base->fake->surfaces->deny(viewer: RuntimeScenario::actor(), surface: $job->surface),
        };
        $result = $s->run($job);
        // A generation change is sampled afresh on a first consume; on replay old target generation cannot reveal an old result.
        if ($change === 'target_generation' && ! $replay) {
            expect($result->isSuccess())->toBeTrue()->and($s->counts()['effects'])->toBe(1);
        } else {
            expect($result->isSuccess())->toBeFalse()->and($result->resource)->toBeNull()->and($result->revision)->toBeNull()
                ->and($s->counts()['effects'])->toBe($replay ? 1 : 0)->and($s->counts()['outbox'])->toBe($replay ? 1 : 0);
        }
        expect($s->counts(true))->toBe(['effects' => 0, 'revision' => 1, 'receipts' => 0, 'outbox' => 0]);
    } finally {
        $s->cleanup();
    }
})->with(['permission', 'actor', 'isolation', 'source', 'target_incarnation', 'surface_incarnation', 'target_generation', 'admission'])->with([false, true]);

it('binds factory command type target namespace and digest to original metadata', function (string $change): void {
    $s = new WorkerScenario;
    try {
        $wire = $s->job()->toWire();
        if ($change === 'namespace') {
            $wire['namespace'] = 'runtime-test/acme.a/acme.worker/other.operation';
        } elseif ($change === 'digest') {
            $wire['digest'] = 'sha256:'.str_repeat('0', 64);
        } elseif ($change === 'principal') {
            $wire['actor'] = 'user:8';
        }
        $job = OperationJobReference::fromWire($wire);
        $result = new OperationJobs($s->a)->execute(job: $job, factory: function (OperationHandle $handle) use ($s, $change): EnvelopeMutation {
            $mutation = $s->mutation();
            if ($change === 'target') {
                $mutation->targetKey = '43';
            }

            return $mutation;
        });
        expect($result->isSuccess())->toBeFalse()->and($result->resource)->toBeNull()
            ->and($s->counts())->toBe(['effects' => 0, 'revision' => 1, 'receipts' => 0, 'outbox' => 0]);
    } finally {
        $s->cleanup();
    }
})->with(['namespace', 'digest', 'principal', 'target']);

it('rejects malformed scalar codec and unsupported versions without accepting actor snapshots', function (): void {
    $s = new WorkerScenario;
    try {
        $wire = $s->job()->toWire();
        foreach ([['actor' => ['kind' => 'system']], ['permissions' => ['allow']], ['expected_revision' => '1'], ['source_generation' => '']] as $change) {
            expect(fn () => OperationJobReference::fromWire(array_replace($wire, $change)))->toThrow(InvalidPayloadException::class);
        }
        try {
            OperationJobReference::fromWire(array_replace($wire, ['version' => '2.0']));
            throw new RuntimeException('Unsupported job version accepted');
        } catch (ProvidesProblem $error) {
            expect($error->problem())->toBe(ProblemCode::WireVersionUnsupported);
        }
    } finally {
        $s->cleanup();
    }
});
