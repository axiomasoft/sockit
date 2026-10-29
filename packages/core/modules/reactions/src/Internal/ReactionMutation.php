<?php

declare(strict_types=1);

namespace SocKit\Reactions\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Contracts\OwnerMutation;
use SocKit\Operations\Data\MutationEffect;
use SocKit\Operations\Data\MutationEvent;
use SocKit\Operations\Data\MutationRequest;
use SocKit\Operations\Data\MutationSnapshot;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\OperationHandles;
use SocKit\Reactions\Data\ReactionCommand;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Ids;
use SocKit\Surfaces\Enums\TargetLifecycle;

/** One invocation, one immutable owner routing. The kernel owns transaction/receipt/outbox and fences. */
final readonly class ReactionMutation implements OwnerMutation
{
    public function __construct(private Application $app, private ReactionCommand $reaction, private string $action)
    {
        if (! in_array($action, ['add', 'remove'], true)) {
            throw new ReactionException(ProblemCode::PayloadInvalid);
        }
    }

    public function commandType(): string
    {
        return 'reactions.'.$this->action;
    }

    public function payload(): array
    {
        return ['kind' => $this->reaction->kind];
    }

    public function request(OperationHandle $handle): MutationRequest
    {
        $this->app->make(OperationHandles::class)->assertCurrent($handle);
        if (! $this->reaction->surface->equals($handle->surface)) {
            throw new ReactionException(ProblemCode::PayloadInvalid);
        }
        if (! in_array('sockit.reactions', $handle->panel->modules, true) || ! in_array('reactions', $handle->definition->features, true)) {
            throw new ReactionException(ProblemCode::ModuleInvalid);
        }
        $kinds = $handle->panel->moduleSettings['sockit.reactions']['kinds'] ?? null;
        if (! is_array($kinds) || ! in_array($this->reaction->kind, $kinds, true)) {
            throw new ReactionException(ProblemCode::PayloadInvalid);
        }
        $actor = $handle->actor->actor ?? throw new ReactionException(ProblemCode::AuthenticationRequired);

        return new MutationRequest(action: $this->commandType(), subject: new AudienceSubject(key: 'reactions.membership', spec: AudienceSpec::direct($actor), author: null,
            surface: $handle->surface, installation: $handle->actor->installation), intent: 'write', safety: AuthorizationDecision::allow('reactions.refs_only'),
            generation: 'reactions.v1', target: $this->reaction->target);
    }

    public function lock(OperationHandle $handle): MutationSnapshot
    {
        $identity = $this->identity($handle);
        $row = $this->row(handle: $handle, entity: 'reactions', identity: $identity);
        if (($row === null && $this->action === 'remove') || ($row !== null && ($row['active'] ?? null) === ($this->action === 'add'))) {
            throw new ReactionException(ProblemCode::RevisionConflict);
        }

        return new MutationSnapshot(resource: $row === null ? null : $this->resource(handle: $handle, row: $row), revision: $row === null ? null : new Revision($this->integer($row, 'revision')),
            lifecycle: TargetLifecycle::Live, generation: 'reactions.v1', safety: AuthorizationDecision::allow('reactions.refs_only'));
    }

    public function persist(OperationHandle $handle, MutationSnapshot $snapshot): MutationEffect
    {
        $identity = $this->identity($handle);
        $resource = $snapshot->resource ?? new ResourceRef(panel: $handle->surface->panel, kind: ResourceKind::Reaction, id: Ids::uuid7());
        $revision = $snapshot->revision?->next() ?? new Revision(1);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $query = $this->table(handle: $handle, entity: 'reactions');
        if ($snapshot->revision === null) {
            $affected = $query->insertOrIgnore([...$identity->columns(), 'identity_digest' => $identity->digest(), 'id' => $resource->id,
                'active' => true, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $affected = $query->where('identity_digest', $identity->digest())->where('id', $resource->id)->where('revision', $snapshot->revision->value)
                ->where('active', $this->action === 'remove')->update(['active' => $this->action === 'add', 'revision' => $revision->value, 'updated_at' => $now]);
        }
        if ($affected !== 1) {
            throw new ReactionException(ProblemCode::RevisionConflict);
        }
        // Membership then counter. Surface/host locks already serialize the same target; no blind CAS retry.
        $counter = $this->identity(handle: $handle, count: true);
        $row = $this->row(handle: $handle, entity: 'reaction_counts', identity: $counter);
        if ($row === null) {
            if ($this->action !== 'add') {
                throw new ReactionException(ProblemCode::PayloadInvalid);
            }
            $changed = $this->table(handle: $handle, entity: 'reaction_counts')->insertOrIgnore([...$counter->columns(), 'identity_digest' => $counter->digest(),
                'id' => Ids::uuid7(), 'count' => 1, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $count = $this->integer($row, 'count') + ($this->action === 'add' ? 1 : -1);
            if ($count < 0) {
                throw new ReactionException(ProblemCode::PayloadInvalid);
            }
            $oldRevision = $this->integer($row, 'revision');
            $changed = $this->table(handle: $handle, entity: 'reaction_counts')->where('identity_digest', $counter->digest())->where('revision', $oldRevision)
                ->update(['count' => $count, 'revision' => $oldRevision + 1, 'updated_at' => $now]);
        }
        if ($changed !== 1) {
            throw new ReactionException(ProblemCode::RevisionConflict);
        }

        return new MutationEffect(affectedRows: $affected, resource: $resource, newRevision: $revision,
            events: [new MutationEvent(type: 'reactions.'.($this->action === 'add' ? 'added' : 'removed'), refs: ['reaction' => $resource])]);
    }

    private function identity(OperationHandle $handle, bool $count = false): ReactionIdentity
    {
        return new ReactionIdentity(installation: $handle->actor->installation, surface: $handle->surface, target: $this->reaction->target, kind: $this->reaction->kind,
            actor: $count ? null : ($handle->actor->actor ?? throw new ReactionException(ProblemCode::AuthenticationRequired)));
    }

    /** @return array<string, mixed>|null */
    private function row(OperationHandle $handle, string $entity, ReactionIdentity $identity): ?array
    {
        $row = $this->table(handle: $handle, entity: $entity)->where('identity_digest', $identity->digest())->lockForUpdate()->first();
        if ($row === null) {
            return null;
        }
        $values = [];
        foreach (get_object_vars($row) as $key => $value) {
            if (! is_string($key)) {
                throw new ReactionException(ProblemCode::PayloadInvalid);
            }
            $values[$key] = $value;
        }
        $identity->verify($values);

        return $values;
    }

    private function table(OperationHandle $handle, string $entity): Builder
    {
        $storage = $handle->panel->storage;
        $connection = $this->app->make(ConnectionResolverInterface::class)->connection($storage->connection);
        if ($connection->transactionLevel() !== 1 || ! isset($storage->tables[$entity])) {
            throw new ReactionException(ProblemCode::ConfigurationInvalid);
        }

        return $connection->table(($storage->schema === null ? '' : $storage->schema.'.').$storage->tables[$entity]);
    }

    /** @param array<string, mixed> $row */
    private function resource(OperationHandle $handle, array $row): ResourceRef
    {
        if (! is_string($row['id'] ?? null)) {
            throw new ReactionException(ProblemCode::PayloadInvalid);
        }

        return new ResourceRef(panel: $handle->surface->panel, kind: ResourceKind::Reaction, id: $row['id']);
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        if (! is_int($row[$key] ?? null)) {
            throw new ReactionException(ProblemCode::PayloadInvalid);
        }

        return $row[$key];
    }
}
