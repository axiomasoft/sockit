<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Groups\Contracts\MembershipConsent;
use SocKit\Groups\Data\ConsentDecision;
use SocKit\Groups\Data\MembershipActivation;
use SocKit\Groups\Data\MembershipCommand;
use SocKit\Groups\Enums\JoinPolicy;
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

    public function __construct(private readonly Application $app, private readonly MembershipCommand $command) {}

    public function commandType(): string
    {
        return 'groups.'.$this->command->action;
    }

    public function payload(): array
    {
        return ['group' => $this->command->group->toWire(), 'member' => $this->command->subject?->toWire()];
    }

    public function request(OperationHandle $handle): MutationRequest
    {
        $store = new GroupStore($this->app);
        $row = $store->readable($handle, $this->command->group);
        $this->preparedRevision = new Revision($store->integer($row, 'revision'));
        $executor = $handle->actor->actor ?? throw new GroupException(ProblemCode::AuthenticationRequired);
        $subject = $this->command->subject ?? $executor;
        $this->consent = null;
        $this->activation = null;
        $this->captured = null;
        // Invalid/no-change state is checked only under locks. It cannot prevent guarded replay.
        // Assessment failures are deferred too: a durable historical receipt needs current READ
        // guards, not a second activation or the historical write/consent prerequisites.
        try {
            $member = new MembershipStore($this->app)->row($handle, new MemberIdentity($this->command->group->id, $subject));
            $next = MembershipTransitions::next(JoinPolicy::from($store->text($row, 'join_policy')),
                $member === null ? null : MembershipState::from($store->text($member, 'state')), $this->command->action);
            if ($next === MembershipState::Active && $this->app->bound(MembershipConsent::class)) {
                $this->activation = new MembershipActivation($this->command->group, $subject, $this->command->action);
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
        $token = null;
        // All owner locks AND kernel authority recheck have completed before this local fence.
        if ($next === MembershipState::Active) {
            $captured = $this->captured ?? throw new GroupException(ProblemCode::ProviderUnavailable);
            $captured->decision->requireAllowed();
            ($this->consent ?? throw new GroupException(ProblemCode::ProviderUnavailable))->recheck($handle,
                $this->activation ?? throw new GroupException(ProblemCode::ProviderUnavailable), $captured)->requireAllowed();
            $token = $captured->generation;
        }
        $store = new GroupStore($this->app);
        $members = new MembershipStore($this->app);
        $subject = $this->command->subject ?? $handle->actor->actor ?? throw new GroupException(ProblemCode::AuthenticationRequired);
        $identity = new MemberIdentity($this->command->group->id, $subject);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $values = ['state' => $next->value, 'consent_generation' => null, 'consent_token' => $token, 'updated_at' => $now];
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
            MembershipState::Left => 'groups.membership_left',
        };

        return new MutationEffect(affectedRows: 1, resource: $this->command->group, newRevision: $revision,
            events: [new MutationEvent(type: $event, refs: ['group' => $this->command->group])]);
    }

    private function transaction(OperationHandle $handle): void
    {
        if ($this->app->make(ConnectionResolverInterface::class)->connection($handle->panel->storage->connection)->transactionLevel() !== 1) {
            throw new GroupException(ProblemCode::ConfigurationInvalid);
        }
    }
}
