<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\GroupMembershipResolver;
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
final readonly class PanelGroupMembership implements GroupMembershipResolver
{
    public function __construct(private Application $app, private SurfaceRef $surface) {}

    public function isMember(ActorContext $actor, GroupRef $group): bool
    {
        return $this->access($actor, $group)->verdict->canRead();
    }

    public function access(ActorContext $actor, GroupRef $group): GroupAccess
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
        $row = $store->readable($management, $resource, $host);
        $instance = $this->app->make(Operations::class)->surface($management);
        $member = new MembershipStore($this->app)->row($management, new MemberIdentity($group->value, $actor->actor));
        if ($member === null || $store->text($member, 'state') !== MembershipState::Active->value || $store->text($member, 'consent_token') === '') {
            throw new GroupException(ProblemCode::AudienceDenied);
        }
        $readOnly = ! $host->read->canWrite() || ! $host->write->canWrite() || $instance->lifecycle === SurfaceLifecycle::Archived;
        $verdict = new AudienceVerdict(decision: $readOnly ? VerdictDecision::AllowReadOnly : VerdictDecision::Allow,
            reason: 'audience.allowed.group', expiresAt: $host->read->expiresAt);

        return new GroupAccess($management, $resource, $row, $member, $host, $instance, $verdict);
    }
}
