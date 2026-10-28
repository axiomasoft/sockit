<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use PHPUnit\Framework\Assert;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Exceptions\InvalidOperationResultException;
use SocKit\Operations\Internal\Receipts\ReceiptException;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Tests\Fixtures\Runtime\ReceiptScenario as Receipts;
use SocKit\Tests\Fixtures\Runtime\RuntimeDatabase;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario as Runtime;
use SocKit\Tests\Integration\Support\PgTarget;

it('recovers a committed outcome by original key after closing the client before its response', function (): void {
    $app = Runtime::app();
    Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        Receipts::install($db);
        $process = proc_open([PHP_BINARY, 'tests/Fixtures/Runtime/receipt-child.php', 'lost', 'same'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start lost-response client');
        }
        stream_set_timeout($pipes[1], 10);
        $output = trim((string) stream_get_contents($pipes[1]));
        $error = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        expect(proc_close($process))->toBe(0, $error)->and(ctype_digit($output))->toBeTrue(); // PID only, no outcome
        fwrite(STDERR, $error);
        $ledger = Receipts::ledger($app);
        $envelope = Receipts::envelope();
        $snapshot = $ledger->lookup(envelope: $envelope, commandType: Receipts::TYPE, payload: ['value' => 1]);
        expect($snapshot)->not->toBeNull();
        if ($snapshot === null) {
            throw new RuntimeException('Missing committed receipt');
        }
        expect($snapshot->resource?->id)->toBe($snapshot->id)->and($snapshot->revision?->value)->toBe(1)->and($snapshot->outcome->value)->toBe('confirmed');
        $replay = $ledger->transaction(envelope: $envelope, commandType: Receipts::TYPE, payload: ['value' => 1], write: static fn (string $id): OperationResult => throw new RuntimeException('Duplicate effect'));
        expect($replay)->toEqual($snapshot);
        Receipts::counts(db: $db, effects: 1, receipts: 1);
        expect($ledger->lookup(envelope: Receipts::envelope(key: 'req.absent.0000001'), commandType: Receipts::TYPE, payload: ['value' => 1]))->toBeNull();
        Receipts::counts(db: $db, effects: 1, receipts: 1);
    } finally {
        $db->cleanup();
    }
});

it('rejects caller digest type namespace and anonymous identity before any claim', function (): void {
    $app = Runtime::app();
    $fake = Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        Receipts::install($db);
        $valid = Receipts::envelope();
        $invalidDigest = new CommandEnvelope(key: $valid->key, namespace: $valid->namespace, digest: new PayloadDigest('sha256:'.str_repeat('0', 64)));
        foreach ([[$invalidDigest, Receipts::TYPE, ProblemCode::PayloadInvalid], [$valid, 'acme.runtime.forged', ProblemCode::PayloadInvalid], [Receipts::envelope(panel: 'acme.b'), Receipts::TYPE, ProblemCode::PanelBindingMismatch], [Receipts::envelope(installation: 'other'), Receipts::TYPE, ProblemCode::PanelBindingMismatch]] as [$envelope, $type, $problem]) {
            try {
                Receipts::ledger($app)->transaction(envelope: $envelope, commandType: $type, payload: ['value' => 1], write: static fn (string $id): OperationResult => throw new RuntimeException('Invalid request reached mutation'));
                Assert::fail('Invalid request passed');
            } catch (ReceiptException $error) {
                expect($error->problem())->toBe($problem);
            }
        }
        $fake->actors->actingAs(ActorContext::anonymous(installation: new InstallationRef('runtime-test'), provenance: ContextProvenance::Direct));
        try {
            Receipts::ledger($app)->lookup(envelope: $valid, commandType: Receipts::TYPE, payload: ['value' => 1]);
            Assert::fail('Anonymous lookup passed');
        } catch (ReceiptException $error) {
            expect($error->problem())->toBe(ProblemCode::AuthenticationRequired);
        }
        Receipts::counts(db: $db, effects: 0, receipts: 0);
        expect(array_filter($db->queries, static fn (array $q): bool => str_starts_with($q['sql'], 'INSERT INTO')))->toBe([]);
    } finally {
        $db->cleanup();
    }
});

it('denies another actor before exposing digest conflict or stored result on lookup and replay', function (): void {
    $app = Runtime::app();
    $fake = Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        Receipts::install($db);
        $owner = Receipts::owner($app);
        $ledger = Receipts::ledger($app);
        $envelope = Receipts::envelope();
        $ledger->transaction(envelope: $envelope, commandType: Receipts::TYPE, payload: ['value' => 1], write: fn (string $id): OperationResult => Receipts::effect(owner: $owner, id: $id));
        $fake->actors->actingAs(Runtime::actor('8'));
        foreach (['lookup', 'transaction'] as $method) {
            foreach ([1, 2] as $value) {
                $ledger = Receipts::ledger($app);
                $foreign = Receipts::envelope(payload: ['value' => $value]);
                try {
                    if ($method === 'transaction') {
                        $ledger->transaction(envelope: $foreign, commandType: Receipts::TYPE, payload: ['value' => $value], write: static fn (string $id): OperationResult => throw new RuntimeException('Foreign actor mutated'));
                    } else {
                        $ledger->lookup(envelope: $foreign, commandType: Receipts::TYPE, payload: ['value' => $value]);
                    }
                    Assert::fail('Foreign actor received outcome');
                } catch (ReceiptException $error) {
                    expect($error->problem())->toBe(ProblemCode::ActorContextInvalid)->and($error->detail())->toBe([]);
                }
            }
        }
        Receipts::counts(db: $db, effects: 1, receipts: 1);
    } finally {
        $db->cleanup();
    }
});

it('rolls back claims and effects for faults non-recordable problems pending unknown and content details', function (string $failure): void {
    $app = Runtime::app();
    Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        Receipts::install($db);
        $owner = Receipts::owner($app);
        try {
            Receipts::ledger($app)->transaction(envelope: Receipts::envelope(), commandType: Receipts::TYPE, payload: ['value' => 1], write: function (string $id) use ($owner, $failure): OperationResult {
                Receipts::effect(owner: $owner, id: $id);

                return match ($failure) {
                    'fault' => throw new RuntimeException('injected failure'),
                    'pending' => OperationResult::pending($id),
                    'unknown' => OperationResult::unknown($id),
                    'detail' => OperationResult::rejected(problem: ProblemCode::AuthorizationDenied, detail: ['body' => 'must never be stored'], receiptId: $id),
                    default => OperationResult::rejected(problem: ProblemCode::ProviderUnavailable, receiptId: $id),
                };
            });
            Assert::fail('Unstable/non-recordable outcome committed');
        } catch (ReceiptException|InvalidOperationResultException $error) {
            expect($failure)->not->toBe('fault');
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe('injected failure');
        }
        Receipts::counts(db: $db, effects: 0, receipts: 0);
        expect($db->capsule->getConnection('owner_a')->scalar('SELECT 42'))->toBe(42);
    } finally {
        $db->cleanup();
    }
})->with(['fault', 'no-receipt', 'pending', 'unknown', 'detail']);

it('persists only a minimal stable rejection and keeps the original owner transaction usable', function (): void {
    $app = Runtime::app();
    Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        Receipts::install($db);
        $ledger = Receipts::ledger($app);
        $envelope = Receipts::envelope();
        $first = $ledger->transaction(envelope: $envelope, commandType: Receipts::TYPE, payload: ['value' => 1], write: static fn (string $id): OperationResult => OperationResult::rejected(problem: ProblemCode::RevisionConflict, receiptId: $id));
        expect($first->outcome->value)->toBe('rejected')->and($first->problem)->toBe(ProblemCode::RevisionConflict)->and($first->resource)->toBeNull();
        expect($ledger->lookup(envelope: $envelope, commandType: Receipts::TYPE, payload: ['value' => 1]))->toEqual($first);
        expect($ledger->transaction(envelope: $envelope, commandType: Receipts::TYPE, payload: ['value' => 1], write: static fn (string $id): OperationResult => throw new RuntimeException('Rejection repeated effect')))->toEqual($first);
        $conflict = Receipts::envelope(payload: ['value' => 2]);
        try {
            $ledger->lookup(envelope: $conflict, commandType: Receipts::TYPE, payload: ['value' => 2]);
            Assert::fail('Different digest passed');
        } catch (ReceiptException $error) {
            expect($error->problem())->toBe(ProblemCode::IdempotencyConflict)->and($error->detail())->toBe([]);
        }
        Receipts::counts(db: $db, effects: 0, receipts: 1);
        $row = PgTarget::query($db->owner, 'SELECT * FROM panel_a.driver_a_receipts')->fetch(PDO::FETCH_ASSOC);
        if (! is_array($row)) {
            throw new RuntimeException('Missing stored rejection');
        }
        expect(array_keys($row))->toBe(['id', 'namespace', 'command_key', 'digest', 'actor_ref', 'actor_kind', 'on_behalf_of', 'outcome', 'resource', 'revision', 'problem']);
    } finally {
        $db->cleanup();
    }
});

it('propagates unrelated unique failures and rejects nested or non READ COMMITTED transactions', function (): void {
    $app = Runtime::app();
    Runtime::trust($app);
    $db = new RuntimeDatabase($app);
    try {
        Receipts::install($db);
        $owner = Receipts::owner($app);
        $ledger = Receipts::ledger($app);
        $envelope = Receipts::envelope();
        $connection = $db->capsule->getConnection('owner_a');
        expect(fn () => $owner->transaction(fn () => $ledger->transaction(envelope: $envelope, commandType: Receipts::TYPE, payload: ['value' => 1], write: static fn (string $id): OperationResult => throw new RuntimeException('Nested effect'))))->toThrow(InvalidConfigurationException::class);
        $connection->statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        expect(fn () => $ledger->transaction(envelope: $envelope, commandType: Receipts::TYPE, payload: ['value' => 1], write: static fn (string $id): OperationResult => throw new RuntimeException('Wrong isolation effect')))->toThrow(InvalidConfigurationException::class);
        $connection->statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED');
        Receipts::counts(db: $db, effects: 0, receipts: 0);
        $ledger->transaction(envelope: $envelope, commandType: Receipts::TYPE, payload: ['value' => 1], write: fn (string $id): OperationResult => Receipts::effect(owner: $owner, id: $id));
        // Only (namespace,key) is a duplicate receipt. An unrelated unique constraint must escape.
        $db->owner->exec('CREATE UNIQUE INDEX test_only_digest_unique ON panel_a.driver_a_receipts (digest)');
        try {
            $ledger->transaction(envelope: Receipts::envelope(key: 'req.other.0000001'), commandType: Receipts::TYPE, payload: ['value' => 1], write: static fn (string $id): OperationResult => throw new RuntimeException('Integrity failure reached effect'));
            Assert::fail('Unrelated 23505 was swallowed');
        } catch (QueryException $error) {
            expect($error->errorInfo[0] ?? null)->toBe('23505'); // no exception/bindings/key emitted in evidence
        }
        expect($connection->transactionLevel())->toBe(0)->and($connection->scalar('SELECT 42'))->toBe(42);
        Receipts::counts(db: $db, effects: 1, receipts: 1);
    } finally {
        $db->cleanup();
    }
});
