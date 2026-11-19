<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Groups\Data\GroupCommand;
use SocKit\Groups\Enums\GroupVisibility;
use SocKit\Groups\Exceptions\GroupException;
use SocKit\Operations\Contracts\OwnerMutation;
use SocKit\Operations\Data\MutationEffect;
use SocKit\Operations\Data\MutationEvent;
use SocKit\Operations\Data\MutationRequest;
use SocKit\Operations\Data\MutationSnapshot;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Ids;
use SocKit\Surfaces\Enums\TargetLifecycle;

#[Internal]
final class GroupMutation implements OwnerMutation
{
    private ?Revision $preparedRevision = null;

    public function __construct(private readonly Application $app, private readonly GroupCommand $group) {}

    public function commandType(): string
    {
        return $this->group->resource === null ? 'groups.create' : 'groups.policy';
    }

    public function payload(): array
    {
        return [...($this->group->visibility === null ? [] : ['visibility' => $this->group->visibility->value]), 'name' => $this->group->name, 'join_policy' => $this->group->joinPolicy->value, 'resource' => $this->group->resource?->toWire()];
    }

    public function request(OperationHandle $handle): MutationRequest
    {
        $store = new GroupStore($this->app);
        $store->outside($handle);
        $actor = $handle->actor->actor ?? throw new GroupException(ProblemCode::AuthenticationRequired);
        if ($this->group->resource !== null) {
            $row = $store->readable($handle, $this->group->resource);
            $this->preparedRevision = new Revision($store->integer($row, 'revision'));
        }

        return new MutationRequest(action: $this->commandType(), subject: new AudienceSubject(key: 'groups.owner', spec: AudienceSpec::direct($actor), author: null,
            surface: $handle->surface, installation: $handle->actor->installation), intent: 'write', safety: AuthorizationDecision::allow('groups.owner'),
            generation: 'groups.v1', target: $store->host($handle));
    }

    public function lock(OperationHandle $handle): MutationSnapshot
    {
        $this->transaction($handle);
        $store = new GroupStore($this->app);
        $row = $this->group->resource === null ? null : $store->row($handle, $this->group->resource, lock: true);
        $revision = $row === null ? null : new Revision($store->integer($row, 'revision'));
        if ($revision !== null && ! $revision->equals($this->preparedRevision ?? throw new GroupException(ProblemCode::RevisionConflict))) {
            throw new GroupException(ProblemCode::RevisionConflict);
        }
        if ($row !== null && $store->text($row, 'join_policy') === $this->group->joinPolicy->value
            && ($this->group->visibility === null || $store->text($row, 'visibility') === $this->group->visibility->value)) {
            throw new GroupException(ProblemCode::RevisionConflict);
        }

        return new MutationSnapshot(resource: $this->group->resource, revision: $revision, lifecycle: TargetLifecycle::Live,
            generation: 'groups.v1', safety: AuthorizationDecision::allow('groups.owner'));
    }

    public function persist(OperationHandle $handle, MutationSnapshot $snapshot): MutationEffect
    {
        $this->transaction($handle);
        $store = new GroupStore($this->app);
        $settings = $handle->panel->moduleSettings['sockit.groups'] ?? [];
        $limit = $settings['max_title_length'] ?? 512;
        if (! is_int($limit) || ($this->group->name !== null && mb_strlen($this->group->name) > $limit)
            || ($this->group->visibility === GroupVisibility::Secret && ($settings['allow_secret_groups'] ?? true) !== true)) {
            throw new GroupException(ProblemCode::PayloadInvalid);
        }
        $resource = $snapshot->resource ?? new ResourceRef($handle->surface->panel, ResourceKind::Group, Ids::uuid7());
        $revision = $snapshot->revision?->next() ?? new Revision(1);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($snapshot->revision === null) {
            $identity = new GroupIdentity($handle->actor->installation, $handle->surface);
            $affected = $store->table($handle)->insertOrIgnore([...$identity->columns(), 'surface_digest' => $identity->digest(), 'id' => $resource->id,
                'name' => $this->group->name, 'visibility' => ($this->group->visibility ?? GroupVisibility::Public)->value, 'join_policy' => $this->group->joinPolicy->value, 'revision' => 1, 'membership_generation' => 1, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $affected = $store->table($handle)->where('id', $resource->id)->where('revision', $snapshot->revision->value)->update([
                ...($this->group->visibility === null ? [] : ['visibility' => $this->group->visibility->value]), 'join_policy' => $this->group->joinPolicy->value, 'revision' => $revision->value, 'updated_at' => $now]);
        }
        if ($affected !== 1) {
            throw new GroupException(ProblemCode::RevisionConflict);
        }

        return new MutationEffect(affectedRows: $affected, resource: $resource, newRevision: $revision,
            events: [new MutationEvent(type: $snapshot->resource === null ? 'groups.created' : 'groups.policy_changed', refs: ['group' => $resource])]);
    }

    private function transaction(OperationHandle $handle): void
    {
        if ($this->app->make(ConnectionResolverInterface::class)->connection($handle->panel->storage->connection)->transactionLevel() !== 1) {
            throw new GroupException(ProblemCode::ConfigurationInvalid);
        }
    }
}
