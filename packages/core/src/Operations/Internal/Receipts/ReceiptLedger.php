<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal\Receipts;

use Closure;
use Illuminate\Database\Connection;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Data\ReceiptSnapshot;
use SocKit\Operations\Enums\OperationOutcome;
use SocKit\Operations\Exceptions\InvalidOperationResultException;
use SocKit\Operations\Internal\Storage\OwnerConnection;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Support\Ids;

/**
 * Owner-only primitive, never a public execute/replay/lookup entry point. P2.7 must run current
 * action/admission/containment/target/lifecycle guards before disclosing any snapshot, including
 * a rejected outcome. No host callbacks under locks: $write performs local owner SQL only.
 * Owns the complete transaction so an unfinished claim cannot be committed separately.
 */
final readonly class ReceiptLedger
{
    public function __construct(private OwnerConnection $owner) {}

    /**
     * $commandType and $payload come from the server's validated command, never from the envelope.
     * $write runs once for the winner and returns only a stable non-content result; all owner
     * mutation/revision/outbox work must use this same owner inside the callback.
     *
     * @param  array<mixed>  $payload
     * @param  Closure(string): OperationResult  $write
     * @param  (Closure(ReceiptSnapshot): void)|null  $afterRecord
     */
    public function transaction(CommandEnvelope $envelope, string $commandType, array $payload, Closure $write, ?string $guardContext = null, ?Closure $afterRecord = null): ReceiptSnapshot
    {
        $this->validate(envelope: $envelope, commandType: $commandType, payload: $payload);

        return $this->owner->transaction(function () use ($envelope, $write, $guardContext, $afterRecord): ReceiptSnapshot {
            $this->requireProfile();
            $table = $this->owner->table('receipts');
            $sqlTable = $table->getGrammar()->wrapTable($table->from);
            $actor = $this->owner->handle()->actor;
            $id = Ids::uuid7();
            // A conflict waits for the other transaction. No 23505 is swallowed and the transaction
            // stays usable. Explicit conflict target does not hide unrelated uniqueness failures.
            $inserted = $table->getConnection()->select(
                query: 'INSERT INTO '.$sqlTable.' (id, namespace, command_key, digest, actor_ref, actor_kind, on_behalf_of'.($guardContext === null ? '' : ', guard_context').') VALUES (?, ?, ?, ?, ?, ?, ?'.($guardContext === null ? '' : ', ?').') ON CONFLICT (namespace, command_key) DO NOTHING RETURNING id',
                bindings: [$id, $envelope->namespace->toWire(), $envelope->key->value, $envelope->digest->value, $actor->actor?->toWire(), $actor->kind->value, $actor->onBehalfOf?->toWire(), ...($guardContext === null ? [] : [$guardContext])],
                useReadPdo: false,
            );
            if ($inserted !== []) {
                $result = $write($id);
                if ($result->receiptId !== $id || $result->problemDetail !== []) {
                    throw new ReceiptException(ProblemCode::PayloadInvalid);
                }
                // Validate before storing; no body, arbitrary details, Pending or Unknown are persisted.
                $snapshot = new ReceiptSnapshot(id: $id, namespace: $envelope->namespace, keyHash: $envelope->key->hashForLog(), digest: $envelope->digest,
                    initiatingActor: $actor->actor ?? throw new ReceiptException(ProblemCode::AuthenticationRequired), outcome: $result->outcome, resource: $result->resource, revision: $result->revision, problem: $result->problem);
                $table->where('id', $id)->update(['outcome' => $snapshot->outcome->value, 'resource' => $snapshot->resource === null ? null : json_encode($snapshot->resource->toWire(), JSON_THROW_ON_ERROR), 'revision' => $snapshot->revision?->value, 'problem' => $snapshot->problem?->value]);
                if ($afterRecord !== null) {
                    $afterRecord($snapshot);
                }

                return $snapshot;
            }

            // A separate READ COMMITTED statement sees the committed winner after INSERT waited.
            return $this->read(envelope: $envelope, guardContext: $guardContext) ?? throw new ReceiptException(ProblemCode::ProviderUnavailable);
        });
    }

    /** Committed non-content guard metadata only. Caller must perform baseline guards first,
     * then current subject guards outside tx before requesting the result. */
    public function context(CommandNamespace $namespace, CommandKey $key): ?string
    {
        $row = $this->owner->table('receipts')->where('namespace', $namespace->toWire())->where('command_key', $key->value)->first();
        if ($row === null) {
            return null;
        }
        $this->identity($row);
        if (! isset($row->guard_context) || ! is_string($row->guard_context)) {
            throw new ReceiptException(ProblemCode::ProviderUnavailable);
        }

        return $row->guard_context;
    }

    public function result(CommandEnvelope $envelope, string $guardContext): ?ReceiptSnapshot
    {
        return $this->owner->transaction(function () use ($envelope, $guardContext): ?ReceiptSnapshot {
            $this->requireProfile();

            return $this->read(envelope: $envelope, guardContext: $guardContext);
        });
    }

    /** Internal metadata lookup using the ORIGINAL key/digest. Public output still needs P2.7 guards.
     * @param array<mixed> $payload */
    public function lookup(CommandEnvelope $envelope, string $commandType, array $payload): ?ReceiptSnapshot
    {
        $this->validate(envelope: $envelope, commandType: $commandType, payload: $payload);

        return $this->owner->transaction(function () use ($envelope): ?ReceiptSnapshot {
            $this->requireProfile();

            return $this->read($envelope);
        });
    }

    /** @param array<mixed> $payload */
    private function validate(CommandEnvelope $envelope, string $commandType, array $payload): void
    {
        $handle = $this->owner->handle();
        if (! $envelope->namespace->panel->equals($handle->surface->panel) || ! $envelope->namespace->installation->equals($handle->actor->installation)) {
            throw new ReceiptException(ProblemCode::PanelBindingMismatch);
        }
        if ($handle->actor->actor === null || $handle->actor->kind === ActorKind::Anonymous) {
            throw new ReceiptException(ProblemCode::AuthenticationRequired);
        }
        if ($envelope->namespace->commandType !== $commandType || ! PayloadDigest::of(commandType: $commandType, payload: $payload)->equals($envelope->digest)) {
            throw new ReceiptException(ProblemCode::PayloadInvalid);
        }
    }

    private function requireProfile(): void
    {
        $this->owner->requireTransaction();
        $connection = $this->owner->table('receipts')->getConnection();
        if (! $connection instanceof Connection || $connection->getDriverName() !== 'pgsql' || $connection->scalar('SHOW transaction_isolation', useReadPdo: false) !== 'read committed') {
            throw new InvalidConfigurationException('Receipts require PostgreSQL READ COMMITTED');
        }
    }

    private function read(CommandEnvelope $envelope, ?string $guardContext = null): ?ReceiptSnapshot
    {
        $row = $this->owner->table('receipts')->where('namespace', $envelope->namespace->toWire())->where('command_key', $envelope->key->value)->lockForUpdate()->first();
        if ($row === null) {
            return null;
        }
        $this->identity($row);
        $actor = $this->owner->handle()->actor;
        if ($guardContext !== null && (! isset($row->guard_context) || $row->guard_context !== $guardContext)) {
            throw new ReceiptException(ProblemCode::IdempotencyConflict);
        }
        if ($row->digest !== $envelope->digest->value) {
            throw new ReceiptException(ProblemCode::IdempotencyConflict);
        }
        if (! is_string($row->id) || ! is_string($row->outcome) || ($row->resource !== null && ! is_string($row->resource)) || ($row->revision !== null && ! is_int($row->revision)) || ($row->problem !== null && ! is_string($row->problem))) {
            throw new ReceiptException(ProblemCode::ProviderUnavailable);
        }
        try {
            $wire = $row->resource === null ? null : json_decode($row->resource, true, flags: JSON_THROW_ON_ERROR);
            if ($wire !== null && ! is_array($wire)) {
                throw new ReceiptException(ProblemCode::ProviderUnavailable);
            }
            $resource = $wire === null ? null : ResourceRef::fromWire(['panel' => $wire['panel'] ?? null, 'kind' => $wire['kind'] ?? null, 'id' => $wire['id'] ?? null]);

            return new ReceiptSnapshot(id: $row->id, namespace: $envelope->namespace, keyHash: $envelope->key->hashForLog(), digest: $envelope->digest,
                initiatingActor: $actor->actor ?? throw new ReceiptException(ProblemCode::AuthenticationRequired), outcome: OperationOutcome::from($row->outcome), resource: $resource, revision: $row->revision === null ? null : new Revision($row->revision), problem: $row->problem === null ? null : ProblemCode::from($row->problem));
        } catch (\JsonException|\ValueError|InvalidPayloadException|InvalidOperationResultException) {
            throw new ReceiptException(ProblemCode::ProviderUnavailable);
        }
    }

    private function identity(\stdClass $row): void
    {
        $actor = $this->owner->handle()->actor;
        if ($row->actor_ref !== $actor->actor?->toWire() || $row->actor_kind !== $actor->kind->value || $row->on_behalf_of !== $actor->onBehalfOf?->toWire()) {
            throw new ReceiptException(ProblemCode::ActorContextInvalid);
        }
    }
}
