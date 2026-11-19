<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Groups\Contracts\MembershipAdministration;
use SocKit\Groups\Contracts\MembershipConsent;
use SocKit\Groups\Data\ConsentDecision;
use SocKit\Groups\Data\MembershipActivation;
use SocKit\Groups\Data\MembershipCommand;
use SocKit\Groups\Enums\GroupVisibility;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Groups\Enums\MembershipRole;
use SocKit\Groups\Enums\MembershipState;
use SocKit\Groups\Exceptions\GroupException;
use SocKit\Operations\Contracts\OwnerMutation;
use SocKit\Operations\Data\MutationEffect;
use SocKit\Operations\Data\MutationEvent;
use SocKit\Operations\Data\MutationRequest;
use SocKit\Operations\Data\MutationSnapshot;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Support\Data\Revision;
use SocKit\Support\Ids;
use SocKit\Surfaces\Enums\TargetLifecycle;

final class MembershipMutation implements OwnerMutation
{
    private ?Revision $preparedRevision = null;

    private ?MembershipConsent $consent = null;

    private ?MembershipActivation $activation = null;

    private ?ConsentDecision $captured = null;

    /** @var array<string, mixed>|null */
    private ?array $member = null;

    private ?MembershipState $next = null;

    private int $generation = 0;

    private ?MembershipAdministration $administration = null;

    private ?string $adminGeneration = null;

    public function __construct(private readonly Application $app, private readonly MembershipCommand $command) {}

    public function commandType(): string
    {
        return 'groups.'.$this->command->action;
    }

    public function payload(): array
    {
        return [...($this->command->role === null ? [] : ['role' => $this->command->role->value]), 'group' => $this->command->group->toWire(), 'member' => $this->command->subject?->toWire()];
    }

    public function request(OperationHandle $handle): MutationRequest
    {
        $store = new GroupStore($this->app);
        $row = $store->admitted($handle, $this->command->group);
        $this->preparedRevision = new Revision($store->integer($row, 'revision'));
        $executor = $handle->actor->actor ?? throw new GroupException(ProblemCode::AuthenticationRequired);
        $subject = $this->command->subject ?? $executor;
        if ($this->isAdministrative() && $this->command->subject?->equals($executor)) {
            throw new GroupException(ProblemCode::AuthorizationDenied);
        }
        $this->adminGeneration = null;
        $this->administration = null;
        $this->consent = null;
        $this->activation = null;
        $this->captured = null;
        // Invalid/no-change state is checked only under locks. It cannot prevent guarded replay.
        // Assessment failures are deferred too: a durable historical receipt needs current READ
        // guards, not a second activation or the historical write/consent prerequisites.
        try {
            $member = new MembershipStore($this->app)->row($handle, new MemberIdentity($this->command->group->id, $subject));
            if ($this->isAdministrative() && $this->app->bound(MembershipAdministration::class)) {
                $this->administration = $this->app->make(MembershipAdministration::class);
                $this->adminGeneration = $this->administration->capture($handle, $this->command);
            }
            $next = MembershipTransitions::next(JoinPolicy::from($store->text($row, 'join_policy')),
                $member === null ? null : MembershipState::from($store->text($member, 'state')), $this->command->action);
            if ($next === MembershipState::Active && $this->app->bound(MembershipConsent::class)) {
                $this->activation = new MembershipActivation($this->command->group, $subject, $this->command->action, $this->role($store, $member));
                $this->consent = $this->app->make(MembershipConsent::class);
                $this->captured = $this->consent->assess($handle, $this->activation);
            }
        } catch (\Throwable) {
            $this->captured = null;
        }

        return new MutationRequest(action: $this->commandType(), subject: new AudienceSubject(key: 'groups.membership',
            spec: AudienceSpec::direct($executor), author: null, surface: $handle->surface, installation: $handle->actor->installation),
            intent: 'write', safety: AuthorizationDecision::allow('groups.owner'), generation: 'groups.membership.v1', target: $store->host($handle));
    }

    public function lock(OperationHandle $handle): MutationSnapshot
    {
        $this->transaction($handle);
        $store = new GroupStore($this->app);
        $row = $store->row($handle, $this->command->group, lock: true);
        $revision = new Revision($store->integer($row, 'revision'));
        if (! $revision->equals($this->preparedRevision ?? throw new GroupException(ProblemCode::RevisionConflict))) {
            throw new GroupException(ProblemCode::RevisionConflict);
        }
        $subject = $this->command->subject ?? $handle->actor->actor ?? throw new GroupException(ProblemCode::AuthenticationRequired);
        $this->member = new MembershipStore($this->app)->row($handle, new MemberIdentity($this->command->group->id, $subject), lock: true);
        if ($this->command->action === 'join' && $store->text($row, 'visibility') !== GroupVisibility::Public->value
            && ($this->member === null || $store->text($this->member, 'state') !== MembershipState::Invited->value)) {
            throw new GroupException(ProblemCode::ContentHidden);
        }
        $this->next = MembershipTransitions::next(JoinPolicy::from($store->text($row, 'join_policy')),
            $this->member === null ? null : MembershipState::from($store->text($this->member, 'state')), $this->command->action);
        $this->generation = $store->integer($row, 'membership_generation');

        return new MutationSnapshot(resource: $this->command->group, revision: $revision, lifecycle: TargetLifecycle::Live,
            generation: 'groups.membership.v1', safety: AuthorizationDecision::allow('groups.owner'));
    }

    public function persist(OperationHandle $handle, MutationSnapshot $snapshot): MutationEffect
    {
        $this->transaction($handle);
        $next = $this->next ?? throw new GroupException(ProblemCode::PayloadInvalid);
        if ($this->isAdministrative()) {
            $generation = $this->adminGeneration;
            if ($this->administration === null || $generation === null || $generation === '' || strlen($generation) > 128) {
                throw new GroupException(ProblemCode::AuthorizationUnknown);
            }
            $this->administration->recheck($handle, $this->command, $generation)->requireAllowed();
        }
        $store = new GroupStore($this->app);
        $role = $this->role($store, $this->member);
        $token = null;
        // All owner locks AND kernel authority recheck have completed before this local fence.
        if ($next === MembershipState::Active) {
            $captured = $this->captured ?? throw new GroupException(ProblemCode::ProviderUnavailable);
            if ($this->activation?->role !== $role) {
                throw new GroupException(ProblemCode::RevisionConflict);
            }
            $captured->decision->requireAllowed();
            ($this->consent ?? throw new GroupException(ProblemCode::ProviderUnavailable))->recheck($handle,
                $this->activation ?? throw new GroupException(ProblemCode::ProviderUnavailable), $captured)->requireAllowed();
            $token = $captured->generation;
        }
        $members = new MembershipStore($this->app);
        $subject = $this->command->subject ?? $handle->actor->actor ?? throw new GroupException(ProblemCode::AuthenticationRequired);
        $identity = new MemberIdentity($this->command->group->id, $subject);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $values = ['state' => $next->value, 'role' => $role->value, 'consent_generation' => null, 'consent_token' => $token, 'updated_at' => $now];
        if ($this->member === null) {
            $affected = $members->table($handle)->insertOrIgnore([...$identity->columns(), 'actor_digest' => $identity->digest(),
                'id' => Ids::uuid7(), 'revision' => 1, 'created_at' => $now, ...$values]);
        } else {
            $memberRevision = new Revision($store->integer($this->member, 'revision'));
            $affected = $members->table($handle)->where('id', $store->text($this->member, 'id'))->where('revision', $memberRevision->value)
                ->update([...$values, 'revision' => $memberRevision->next()->value]);
        }
        $before = $snapshot->revision ?? throw new GroupException(ProblemCode::RevisionConflict);
        $revision = $before->next();
        $generation = new Revision($this->generation)->next();
        if ($affected !== 1 || $store->table($handle)->where('id', $this->command->group->id)->where('revision', $before->value)
            ->where('membership_generation', $this->generation)->update(['revision' => $revision->value, 'membership_generation' => $generation->value, 'updated_at' => $now]) !== 1) {
            throw new GroupException(ProblemCode::RevisionConflict);
        }
        $event = match ($next) {
            MembershipState::Pending => 'groups.membership_requested',
            MembershipState::Invited => 'groups.membership_invited',
            MembershipState::Active => 'groups.membership_activated',
            MembershipState::Left => $this->command->action === 'remove_member' ? 'groups.membership_removed' : 'groups.membership_left',
        };

        return new MutationEffect(affectedRows: 1, resource: $this->command->group, newRevision: $revision,
            events: [new MutationEvent(type: $event, refs: ['group' => $this->command->group])]);
    }

    private function isAdministrative(): bool
    {
        return in_array($this->command->action, ['add_member', 'remove_member'], true)
            || ($this->command->role !== null && $this->command->role !== MembershipRole::Member);
    }

    /** @param array<string, mixed>|null $member */
    private function role(GroupStore $store, ?array $member): MembershipRole
    {
        if ($this->command->action === 'join' && $member !== null && $store->text($member, 'state') === MembershipState::Invited->value) {
            return MembershipRole::from($store->text($member, 'role'));
        }

        return $this->command->role ?? MembershipRole::Member;
    }

    private function transaction(OperationHandle $handle): void
    {
        if ($this->app->make(ConnectionResolverInterface::class)->connection($handle->panel->storage->connection)->transactionLevel() !== 1) {
            throw new GroupException(ProblemCode::ConfigurationInvalid);
        }
    }
}
