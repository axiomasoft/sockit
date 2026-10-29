<?php

declare(strict_types=1);

use SocKit\Errors\Enums\ProblemCode;
use SocKit\Tests\Fixtures\Runtime\EnvelopeRace;
use SocKit\Tests\Fixtures\Runtime\EnvelopeScenario;
use SocKit\Tests\Integration\Support\PgTarget;
use Symfony\Component\Process\Process;

it('serializes duplicate digest conflict CAS and rollback claimants in real independent PG processes', function (string $mode): void {
    $s = new EnvelopeScenario;
    try {
        $results = EnvelopeRace::run(s: $s, mode: $mode);
        $first = $results[0];
        $second = $results[1];
        if ($mode === 'duplicate') {
            expect($first->isSuccess())->toBeTrue()->and($second->toWire())->toBe($first->toWire());
        }
        if ($mode === 'digest') {
            expect($first->isSuccess())->toBeTrue()->and($second->problem)->toBe(ProblemCode::IdempotencyConflict);
        }
        if ($mode === 'cas') {
            expect($first->isSuccess())->toBeTrue()->and($second->problem)->toBe(ProblemCode::RevisionConflict);
        }
        if ($mode === 'rollback') {
            expect($first->isSuccess())->toBeFalse()->and($second->isSuccess())->toBeTrue();
        }
        expect($s->counts())->toBe(['effects' => 1, 'revision' => 2, 'receipts' => $mode === 'cas' ? 2 : 1, 'outbox' => 1]);
    } finally {
        $s->cleanup();
    }
})->with(['duplicate', 'digest', 'cas', 'rollback']);

it('serializes archive and delete against mutation under the shared surface lock', function (string $mode): void {
    $s = new EnvelopeScenario;
    try {
        $results = EnvelopeRace::run(s: $s, mode: $mode);
        $second = $results[1];
        expect($second->problem)->toBe($mode === 'archive' ? ProblemCode::SurfaceArchived : ProblemCode::ContentDeleted)
            ->and($second->resource)->toBeNull()->and($s->counts())->toBe(['effects' => 0, 'revision' => 1, 'receipts' => $mode === 'archive' ? 2 : 1, 'outbox' => 1]);
    } finally {
        $s->cleanup();
    }
})->with(['archive', 'delete']);

it('does not disclose the committed winner after authority revokes while a duplicate waits', function (): void {
    $s = new EnvelopeScenario;
    try {
        $results = EnvelopeRace::run(s: $s, mode: 'revoke');
        foreach ($results as $r) {
            $result = $r;
            expect($result->resource)->toBeNull()->and($result->problem)->toBe(ProblemCode::AuthorizationDenied);
        }
        expect($s->counts())->toBe(['effects' => 1, 'revision' => 2, 'receipts' => 1, 'outbox' => 1]);
    } finally {
        $s->cleanup();
    }
});

it('recovers a lost response by original key in a fresh process with only one committed effect', function (): void {
    $s = new EnvelopeScenario;
    try {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/Runtime/envelope-child.php', 'lost', 'duplicate']);
        $process->setTimeout(15);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        fwrite(STDERR, $process->getErrorOutput());
        $command = $s->command();
        $lookup = $s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key);
        $replay = $s->execute($command);
        expect($lookup?->isSuccess())->toBeTrue()->and($replay->toWire())->toBe($lookup?->toWire())->and($s->mutation->writes)->toBe(0)
            ->and($s->counts())->toBe(['effects' => 1, 'revision' => 2, 'receipts' => 1, 'outbox' => 1]);
    } finally {
        $s->cleanup();
    }
});

it('rolls back a newly won claim before any effect when authority revoked during UNIQUE wait and holder rollback', function (string $mode): void {
    $s = new EnvelopeScenario;
    try {
        $results = EnvelopeRace::run(s: $s, mode: $mode);
        expect($results[0]->isSuccess())->toBeFalse()->and($results[1]->problem)->toBe(ProblemCode::AuthorizationDenied)->and($results[1]->resource)->toBeNull()
            ->and($s->counts())->toBe(['effects' => 0, 'revision' => 1, 'receipts' => 1, 'outbox' => 0]);
        $pdo = $s->base->db->owner;
        expect(PgTarget::query($pdo, 'SELECT lifecycle FROM panel_a.driver_a_surface_instances')->fetchColumn())->toBe('live')
            ->and((int) PgTarget::query($pdo, 'SELECT revision FROM panel_a.driver_a_surface_instances')->fetchColumn())->toBe(1)
            ->and((int) PgTarget::query($pdo, "SELECT count(*) FROM panel_a.driver_a_receipts WHERE outcome='confirmed'")->fetchColumn())->toBe(0)
            ->and($s->base->consumer->calls)->toBe(0);
    } finally {
        $s->cleanup();
    }
})->with(['revoke_rollback', 'lifecycle_revoke_rollback']);

it('rolls back an unprovable authority generation then permits only an explicit same-key fresh retry', function (): void {
    $s = new EnvelopeScenario;
    try {
        $command = $s->command();
        $results = EnvelopeRace::run(s: $s, mode: 'generation_rollback');
        expect($results[1]->problem)->toBe(ProblemCode::AuthorizationUnknown)->and($s->counts())->toBe(['effects' => 0, 'revision' => 1, 'receipts' => 0, 'outbox' => 0]);
        expect($s->execute($command)->isSuccess())->toBeTrue()->and($s->counts())->toBe(['effects' => 1, 'revision' => 2, 'receipts' => 1, 'outbox' => 1]);
    } finally {
        $s->cleanup();
    }
});
