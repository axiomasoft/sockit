<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitting;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Tests\Fixtures\Runtime\EnvelopeScenario;

it('commits one state revision receipt and outbox and replays only original references', function (): void {
    $s = new EnvelopeScenario;
    try {
        $first = $s->execute();
        expect($first->isSuccess())->toBeTrue();
        $second = $s->execute();
        $lookup = $s->operations->lookup(handle: $s->handle, namespace: $s->command()->namespace, key: $s->command()->key);
        expect($second->toWire())->toBe($first->toWire())->and($lookup?->toWire())->toBe($first->toWire())
            ->and($s->mutation->writes)->toBe(1)->and($s->counts())->toBe(['effects' => 1, 'revision' => 2, 'receipts' => 1, 'outbox' => 1])
            ->and($s->base->consumer->calls)->toBe(0);
    } finally {
        $s->cleanup();
    }
});

it('rolls back every set on a real SQL fault at each persist boundary', function (string $stage): void {
    $s = new EnvelopeScenario;
    try {
        $connection = $s->base->db->capsule->getConnection('owner_a');
        $armed = true;
        $throw = function () use (&$armed): void {
            if ($armed) {
                $armed = false;
                throw new RuntimeException('injected owner fault');
            }
        };
        if ($stage === 'before_commit') {
            ($connection->getEventDispatcher() ?? throw new RuntimeException('Missing event dispatcher'))->listen(TransactionCommitting::class, $throw);
        } else {
            $connection->listen(function (QueryExecuted $q) use ($stage, $throw): void {
                $sql = $q->sql;
                if (($stage === 'state' && str_starts_with($sql, 'update') && str_contains($sql, 'envelope_state'))
                    || ($stage === 'revision' && str_starts_with($sql, 'update') && str_contains($sql, 'envelope_revisions'))
                    || ($stage === 'receipt' && str_starts_with($sql, 'update') && str_contains($sql, '"outcome"'))
                    || ($stage === 'outbox' && str_starts_with($sql, 'INSERT') && str_contains($sql, 'a_outbox'))) {
                    $throw();
                }
            });
        }
        $result = $s->execute();
        expect($result->isSuccess())->toBeFalse()->and($armed)->toBeFalse()->and($s->counts())->toBe(['effects' => 0, 'revision' => 1, 'receipts' => 0, 'outbox' => 0])
            ->and($connection->transactionLevel())->toBe(0)->and($connection->getPdo()->inTransaction())->toBeFalse()->and($s->base->consumer->calls)->toBe(0);
    } finally {
        $s->cleanup();
    }
})->with(['state', 'revision', 'receipt', 'outbox', 'before_commit']);

it('rejects a nested owner transaction without changing or completing the caller transaction', function (): void {
    $s = new EnvelopeScenario;
    try {
        $command = $s->command();
        $connection = $s->base->db->capsule->getConnection('owner_a');
        $connection->beginTransaction();
        try {
            expect($s->execute($command)->problem)->toBe(ProblemCode::ConfigurationInvalid)->and($s->mutation->locks)->toBe(0)->and($s->target->levels)->toBe([])
                ->and($connection->transactionLevel())->toBe(1)->and($connection->getPdo()->inTransaction())->toBeTrue();
        } finally {
            $connection->rollBack();
        }
        expect($s->counts())->toBe(['effects' => 0, 'revision' => 1, 'receipts' => 0, 'outbox' => 0]);
    } finally {
        $s->cleanup();
    }
});

it('rolls back owner protocol violations instead of trusting a successful effect DTO', function (string $bad): void {
    $s = new EnvelopeScenario;
    try {
        $s->mutation->bad = $bad;
        expect($s->execute()->isSuccess())->toBeFalse()->and($s->counts())->toBe(['effects' => 0, 'revision' => 1, 'receipts' => 0, 'outbox' => 0]);
    } finally {
        $s->cleanup();
    }
})->with(['rows', 'revision', 'events', 'generation', 'safety']);
