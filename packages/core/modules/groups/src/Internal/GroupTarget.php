<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Data\GroupRef;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Groups\Enums\MembershipState;
use SocKit\Groups\Exceptions\GroupException;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\MutationRequest;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\OperationHandles;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Contracts\TargetWriteFence;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Enums\TargetLifecycle;

/** `group` retains nonmember management/receipt access; `sockit.group` requires membership.
 * Scoped preparation is replaced on every resolve and consumed once by the local fence.
 * The host's existing authority fence must also prove the management boundary on this
 * owner connection (including lifecycle/admission changes); remote/unproven facts deny.
 */
final class GroupTarget implements TargetResolver, TargetWriteFence
{
    private ?OperationHandle $prepared = null;

    private ?GroupAccess $access = null;

    private ?TargetResolution $sample = null;

    private ?MutationRequest $request = null;

    private ?MutationAuthorityFence $authority = null;

    private ?string $token = null;

    public function __construct(private readonly Application $app) {}

    public function resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution
    {
        $this->prepared = $this->access = $this->sample = $this->request = $this->authority = $this->token = null;
        $handles = $this->app->make(OperationHandles::class);
        $handle = $handles->forSurface($surface);
        if ($actor != $handle->actor || $target->incarnation->value !== 1 || ! in_array($target->host->type, ['group', 'sockit.group'], true)) {
            throw new GroupException(ProblemCode::PanelBindingMismatch);
        }
        if ($target->host->type === 'group' && ! in_array($surface->host->type, ['group', 'sockit.group'], true)) {
            $store = new GroupStore($this->app);
            $store->outside($handle);
            $host = $handles->resolveTarget($handle, $store->host($handle));
            $resource = new ResourceRef($surface->panel, ResourceKind::Group, $target->host->value);
            $row = $store->readable($handle, $resource, $host);
            $revision = new Revision($store->integer($row, 'revision'));

            return new TargetResolution(surface: $surface, target: $target, installation: $actor->installation, read: $host->read,
                write: AudienceVerdict::deny('audience.unknown'), lifecycle: TargetLifecycle::ReadOnly, privacyRevision: $revision,
                generation: hash('sha256', CanonicalJson::encode(['group' => $resource->toWire(), 'revision' => $revision->value, 'host' => $host->generation])));
        }
        if ($surface->host->type !== 'sockit.group' || $surface->host->value !== $target->host->value || $surface->incarnation->value !== 1) {
            throw new GroupException(ProblemCode::PanelBindingMismatch);
        }
        $access = new PanelGroupMembership($this->app, $surface)->access($actor, new GroupRef($target->host->value));
        if ($access->management->panel->storage->connection !== $handle->panel->storage->connection) {
            throw new GroupException(ProblemCode::PanelBindingMismatch);
        }
        $write = $target->host->type === 'sockit.group' && $access->verdict->canWrite()
            ? $access->verdict : AudienceVerdict::deny('audience.unknown');
        $resolved = new TargetResolution(surface: $surface, target: $target, installation: $actor->installation, read: $access->verdict,
            write: $write, lifecycle: $write->canWrite() ? TargetLifecycle::Live : TargetLifecycle::ReadOnly,
            privacyRevision: new Revision(new GroupStore($this->app)->integer($access->group, 'revision')), generation: $this->generation($access));
        $this->prepared = $handle;
        $this->access = $access;
        $this->sample = $resolved;
        if ($write->canWrite() && $this->app->bound(MutationAuthorityFence::class)) {
            $this->request = new MutationRequest(action: 'groups.read', subject: new AudienceSubject(key: 'groups.content_boundary',
                spec: AudienceSpec::direct($actor->actor ?? throw new GroupException(ProblemCode::AuthenticationRequired)), author: null,
                surface: $access->management->surface, installation: $actor->installation), intent: 'write',
                safety: AuthorizationDecision::allow('groups.content_boundary'), generation: $resolved->generation, target: $access->host->target);
            $this->authority = $this->app->make(MutationAuthorityFence::class);
            $this->token = $this->authority->capture($access->management, $this->request);
        }

        return $resolved;
    }

    public function recheck(OperationHandle $handle, TargetResolution $resolved): AuthorizationDecision
    {
        $prepared = $this->prepared;
        $access = $this->access;
        $sample = $this->sample;
        $request = $this->request;
        $authority = $this->authority;
        $token = $this->token;
        $this->prepared = $this->access = $this->sample = $this->request = $this->authority = $this->token = null;
        $connection = $this->app->make(ConnectionResolverInterface::class)->connection($handle->panel->storage->connection);
        if ($prepared != $handle || $access === null || $sample === null || $request === null || $authority === null || $token === null
            || $token === '' || strlen($token) > 128 || $connection->transactionLevel() !== 1 || ! $sample->write->canWrite()
            || ! $sample->surface->equals($resolved->surface) || ! $sample->target->equals($resolved->target)
            || ! $sample->installation->equals($resolved->installation) || $sample->generation !== $resolved->generation || $sample->privacyRevision != $resolved->privacyRevision) {
            return AuthorizationDecision::unknown('groups.target_preparation_unproven');
        }
        // The parent host fence, then group, then content owner. No ambient resolution or
        // network here. The group lock serializes every membership/policy writer until commit.
        $this->app->make(OperationHandles::class)->recheckTarget($access->management, $access->host)->requireAllowed();
        $store = new GroupStore($this->app);
        $row = $store->row($access->management, $access->resource, lock: true);
        $member = new MembershipStore($this->app)->row($access->management,
            new MemberIdentity($access->resource->id, $handle->actor->actor ?? throw new GroupException(ProblemCode::AuthenticationRequired)));
        if ($member === null || $row !== $access->group || $member !== $access->member || $store->text($member, 'state') !== MembershipState::Active->value) {
            return AuthorizationDecision::unknown('groups.target_changed');
        }
        // Existing host consistency protocol, before any post/reaction lock. It must prove
        // current parent admission/lifecycle locally; a sampled remote allow is insufficient.
        $authority->recheck($access->management, $request, $token)->requireAllowed();

        return AuthorizationDecision::allow('groups.target_fenced');
    }

    private function generation(GroupAccess $access): string
    {
        return hash('sha256', CanonicalJson::encode(['group' => $access->resource->toWire(), 'binding' => $access->management->surface->toWire(),
            'installation' => $access->management->actor->installation->id, 'actor' => $access->management->actor->actor?->toWire(),
            'actor_generation' => $access->management->actor->generation, 'revision' => $access->group['revision'],
            'membership_generation' => $access->group['membership_generation'], 'member_revision' => $access->member['revision'],
            'surface_revision' => $access->instance->revision->value, 'surface_privacy' => $access->instance->privacyGeneration->value,
            'surface_source' => $access->instance->sourceGeneration->value, 'host' => $access->host->generation, 'host_privacy' => $access->host->privacyRevision->value]));
    }
}
