<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\GroupRef;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Groups\Data\GroupView;
use SocKit\Groups\Enums\GroupVisibility;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Groups\Enums\MembershipRole;
use SocKit\Groups\Enums\MembershipState;
use SocKit\Groups\Exceptions\GroupException;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\Operations;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;

final readonly class GroupStore
{
    public function __construct(private Application $app) {}

    public function outside(OperationHandle $handle): void
    {
        $this->app->make(OperationHandles::class)->assertCurrent($handle);
        if (! in_array('sockit.groups', $handle->panel->modules, true) || ! in_array('groups', $handle->definition->features, true)) {
            throw new GroupException(ProblemCode::ModuleInvalid);
        }
        if ($this->app->make(ConnectionResolverInterface::class)->connection($handle->panel->storage->connection)->transactionLevel() !== 0) {
            throw new GroupException(ProblemCode::ConfigurationInvalid);
        }
    }

    public function table(OperationHandle $handle): Builder
    {
        $storage = $handle->panel->storage;
        $table = ($storage->schema === null ? '' : $storage->schema.'.').($storage->tables['groups'] ?? throw new GroupException(ProblemCode::ConfigurationInvalid));

        return $this->app->make(ConnectionResolverInterface::class)->connection($storage->connection)->table($table);
    }

    public function host(OperationHandle $handle): TargetRef
    {
        return new TargetRef(host: $handle->surface->host, incarnation: $handle->surface->incarnation);
    }

    /** @return array<string, mixed> */
    public function row(OperationHandle $handle, ResourceRef $resource, bool $lock = false): array
    {
        if ($resource->kind !== ResourceKind::Group || ! $resource->panel->equals($handle->surface->panel)) {
            throw new GroupException(ProblemCode::PanelBindingMismatch);
        }
        $query = $this->table($handle)->where('id', $resource->id);
        $raw = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($raw === null) {
            throw new GroupException(ProblemCode::ContentHidden);
        }
        $row = [];
        foreach (get_object_vars($raw) as $key => $value) {
            if (! is_string($key)) {
                throw new GroupException(ProblemCode::PayloadInvalid);
            }
            $row[$key] = $value;
        }
        new GroupIdentity($handle->actor->installation, $handle->surface)->verify($row);

        return $row;
    }

    /** @return array<string, mixed> */
    public function readable(OperationHandle $handle, ResourceRef $resource, ?TargetResolution $host = null): array
    {
        $row = $this->admitted($handle, $resource, $host);
        if ($this->text($row, 'visibility') !== GroupVisibility::Public->value && $this->viewerRole($handle, $resource) === null) {
            throw new GroupException(ProblemCode::ContentHidden);
        }

        return $row;
    }

    public function viewerRole(OperationHandle $handle, ResourceRef $resource): ?MembershipRole
    {
        $actor = $handle->actor->actor;
        $member = $actor === null ? null : new MembershipStore($this->app)->row($handle, new MemberIdentity($resource->id, $actor));

        return $member !== null && $this->text($member, 'state') === MembershipState::Active->value && $this->text($member, 'consent_token') !== ''
            ? MembershipRole::from($this->text($member, 'role')) : null;
    }

    /** Host admission for management commands that do not disclose the group contents.
     * @return array<string, mixed> */
    public function admitted(OperationHandle $handle, ResourceRef $resource, ?TargetResolution $host = null): array
    {
        $this->outside($handle);
        $this->app->make(Operations::class)->surface($handle);
        $host ??= $this->app->make(OperationHandles::class)->resolveTarget($handle, $this->host($handle));
        if (! $host->read->canRead() || ($host->read->expiresAt !== null && $host->read->expiresAt <= new \DateTimeImmutable('now'))) {
            throw new GroupException(ProblemCode::ContentHidden);
        }
        $this->app->make(ActionAuthorizer::class)->decide(new ActionRequest(actor: $handle->actor, action: 'groups.read', surface: $handle->surface, resource: $resource))->requireAllowed();

        return $this->row($handle, $resource);
    }

    public function view(OperationHandle $handle, ResourceRef $resource): GroupView
    {
        $row = $this->admitted($handle, $resource);
        $role = $this->viewerRole($handle, $resource);
        if ($this->text($row, 'visibility') !== GroupVisibility::Public->value && $role === null) {
            throw new GroupException(ProblemCode::ContentHidden);
        }

        return new GroupView(resource: $resource, group: new GroupRef($resource->id), name: $this->text($row, 'name'),
            joinPolicy: JoinPolicy::from($this->text($row, 'join_policy')), revision: new Revision($this->integer($row, 'revision')), visibility: GroupVisibility::from($this->text($row, 'visibility')), viewerRole: $role);
    }

    /** @param array<string, mixed> $row */
    public function text(array $row, string $key): string
    {
        return is_string($row[$key] ?? null) ? $row[$key] : throw new GroupException(ProblemCode::PayloadInvalid);
    }

    /** @param array<string, mixed> $row */
    public function integer(array $row, string $key): int
    {
        return is_int($row[$key] ?? null) && $row[$key] > 0 ? $row[$key] : throw new GroupException(ProblemCode::PayloadInvalid);
    }
}
