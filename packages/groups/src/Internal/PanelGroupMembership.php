<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\ResolvesGroupVerdicts;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\GroupRef;
use SocKit\Audience\Enums\VerdictDecision;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Groups\Enums\MembershipState;
use SocKit\Groups\Exceptions\GroupException;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\Operations;
use SocKit\Panels\PanelDefinitions;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Enums\SurfaceLifecycle;

/** One subject's immutable panel binding; never an ambient current-panel service or allow cache. */
final readonly class PanelGroupMembership implements ResolvesGroupVerdicts
{
    public function __construct(private Application $app, private SurfaceRef $surface) {}

    public function isMember(ActorContext $actor, GroupRef $group): bool
    {
        return $this->decideMembership($actor, $group)->canRead();
    }

    public function access(ActorContext $actor, GroupRef $group): GroupAccess
    {
        $result = $this->sample($actor, $group);

        return $result instanceof GroupAccess ? $result : throw new GroupException(ProblemCode::AudienceDenied);
    }

    public function decideMembership(ActorContext $actor, GroupRef $group): AudienceVerdict
    {
        $result = $this->sample($actor, $group);

        return $result instanceof GroupAccess ? $result->verdict : $result;
    }

    private function sample(ActorContext $actor, GroupRef $group): GroupAccess|AudienceVerdict
    {
        $panel = $this->app->make(PanelDefinitions::class)->get($this->surface->panel);
        if (! in_array('sockit.groups', $panel->modules, true) || $actor->actor === null) {
            throw new GroupException(ProblemCode::ModuleInvalid);
        }
        $handles = $this->app->make(OperationHandles::class);
        $subject = $handles->forSurface($this->surface);
        $handles->assertCurrent($subject);
        if ($actor != $subject->actor) {
            throw new GroupException(ProblemCode::ActorContextInvalid);
        }
        $connection = $this->app->make(ConnectionResolverInterface::class)->connection($panel->storage->connection);
        if ($connection->transactionLevel() !== 0) {
            throw new GroupException(ProblemCode::ConfigurationInvalid);
        }
        $store = new GroupStore($this->app);
        // This first read discovers the management binding ONLY within the selected panel.
        // It grants nothing; GroupIdentity and the current management surface are verified below.
        $raw = $store->table($subject)->where('id', $group->value)->where('installation', $actor->installation->id)
            ->where('panel', $panel->id->value)->first();
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
        $managementSurface = new SurfaceRef(panel: $panel->id, kind: new SurfaceKindId($store->text($row, 'surface_kind')),
            host: new HostEntityRef($store->text($row, 'host_type'), $store->text($row, 'host_key')),
            incarnation: new Incarnation($store->integer($row, 'surface_incarnation')));
        if (in_array($managementSurface->host->type, ['group', 'sockit.group', 'post', 'sockit.post'], true)) {
            throw new GroupException(ProblemCode::PanelBindingMismatch);
        }
        $management = $handles->forSurface($managementSurface);
        $store->outside($management);
        $resource = new ResourceRef($panel->id, ResourceKind::Group, $group->value);
        $host = $handles->resolveTarget($management, $store->host($management));
        $row = $store->admitted($management, $resource, $host);
        $instance = $this->app->make(Operations::class)->surface($management);
        $members = new MembershipStore($this->app);
        $identity = new MemberIdentity($group->value, $actor->actor);
        $member = $members->row($management, $identity);
        $query = $store->table($management)->where(array_intersect_key($row, array_flip([
            'id', 'installation', 'panel', 'surface_kind', 'host_type', 'host_key', 'surface_incarnation', 'revision', 'membership_generation', 'visibility',
        ])));
        $membership = $members->table($management)->where('group_id', $group->value)->where('actor_digest', $identity->digest());
        if ($member === null) {
            $query->whereNotExists($membership);
        } else {
            $membership->where(array_intersect_key($member, array_flip(['id', 'actor_ref', 'revision', 'state', 'role', 'consent_token', 'consent_generation'])));
            $query->whereExists($membership);
        }
        $checkpoint = new GroupMembershipCheckpoint($query);
        if ($member === null || $store->text($member, 'state') !== MembershipState::Active->value) {
            return new AudienceVerdict(VerdictDecision::Deny, 'audience.denied.not_in_group', expiresAt: $host->read->expiresAt,
                checkpoints: [...$host->read->checkpoints, $checkpoint]);
        }
        if ($store->text($member, 'consent_token') === '') {
            throw new GroupException(ProblemCode::AudienceDenied);
        }
        $readOnly = ! $host->read->canWrite() || ! $host->write->canWrite() || $instance->lifecycle === SurfaceLifecycle::Archived;
        $verdict = new AudienceVerdict(decision: $readOnly ? VerdictDecision::AllowReadOnly : VerdictDecision::Allow,
            reason: 'audience.allowed.group', expiresAt: $host->read->expiresAt, checkpoints: [...$host->read->checkpoints, $checkpoint]);

        return new GroupAccess($management, $resource, $row, $member, $host, $instance, $verdict);
    }
}
