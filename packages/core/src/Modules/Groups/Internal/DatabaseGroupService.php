<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Internal;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Override;
use Packman\Attributes\Internal;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\GroupRef;
use SocKit\Audience\Exceptions\AuthorizationDeniedException;
use SocKit\Database\Internal\SockitRows;
use SocKit\Database\Internal\SockitTables;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Modules\Groups\Configuration\GroupsOptions;
use SocKit\Modules\Groups\Contracts\GroupService;
use SocKit\Modules\Groups\Contracts\MembershipConsent;
use SocKit\Modules\Groups\Data\AddGroupMemberCommand;
use SocKit\Modules\Groups\Data\ApproveGroupMemberCommand;
use SocKit\Modules\Groups\Data\ConsentDecision;
use SocKit\Modules\Groups\Data\CreateGroupCommand;
use SocKit\Modules\Groups\Data\GroupView;
use SocKit\Modules\Groups\Data\InviteGroupMemberCommand;
use SocKit\Modules\Groups\Data\JoinGroupCommand;
use SocKit\Modules\Groups\Data\LeaveGroupCommand;
use SocKit\Modules\Groups\Data\MembershipActivation;
use SocKit\Modules\Groups\Data\RemoveGroupMemberCommand;
use SocKit\Modules\Groups\Data\SetGroupJoinPolicyCommand;
use SocKit\Modules\Groups\Enums\GroupJoinPolicy;
use SocKit\Modules\Groups\Enums\GroupMemberRole;
use SocKit\Modules\Groups\Enums\GroupMemberStatus;
use SocKit\Modules\Groups\Enums\GroupVisibility;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\PanelException;
use SocKit\Registrars\Internal\SockitRuntime;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Support\Ids;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Enums\SurfaceLifecycleState;

/**
 * Panel-isolated Groups command and query service operating via {@see OperationEnvelopeRunner},
 * enforcing management-surface binding on locks, no implicit creator enrollment, join policy workflows,
 * host MembershipConsent verification before/under lock, and fail-closed surface-scoped reads.
 */
#[Internal]
final readonly class DatabaseGroupService implements GroupService
{
    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
        private SurfaceDefinitions $surfaces,
        private SockitRuntime $runtime,
    ) {}

    #[Override]
    public function create(CreateGroupCommand $command): OperationResult
    {
        $options = $this->assertGroupsEnabled($command->surface);

        if (strlen($command->title) > $options->maxTitleLength) {
            throw InvalidPayloadException::invalid(
                "Group title exceeds max_title_length [{$options->maxTitleLength}].",
                ['max_title_length' => $options->maxTitleLength],
                '/title',
            );
        }

        if ($command->visibility === GroupVisibility::Secret && ! $options->allowSecretGroups) {
            throw InvalidPayloadException::invalid('Secret groups are disabled on this panel.', [], '/visibility');
        }

        $actor = $this->runtime->actors()->current();
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'groups.create');
        $digest = PayloadDigest::of('groups.create', $command->toDigestPayload());

        $envelope = new CommandEnvelope(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
        );

        return $this->runtime->envelope()->run(
            envelope: $envelope,
            surface: $command->surface,
            action: 'sockit.groups.create',
            preTransactionChecks: static function (ActorContext $verifiedActor): void {},
            mutation: function (ActorContext $verifiedActor, Connection $connection) use ($command, $namespace): array {
                $creator = $verifiedActor->actor ?? throw OperationException::audienceDenied('actor.anonymous');
                $groupsTable = $this->tables->table('groups', $command->surface->panel);
                $now = $this->nowIso();
                $groupId = Ids::uuid7();

                try {
                    $connection->transaction(static function () use ($connection, $groupsTable, $groupId, $command, $creator, $now): void {
                        $connection->table($groupsTable)->insert([
                            'id' => $groupId,
                            'panel_id' => $command->surface->panel->value,
                            'surface_kind' => $command->surface->kind->value,
                            'surface_host_type' => $command->surface->host->type,
                            'surface_host_value' => $command->surface->host->value,
                            'surface_incarnation' => $command->surface->incarnation->value,
                            'slug' => $command->slug,
                            'title' => $command->title,
                            'visibility' => $command->visibility->value,
                            'join_policy' => $command->joinPolicy->value,
                            'state' => 'active',
                            'revision' => 1,
                            'membership_generation' => 1,
                            'created_by_type' => $creator->type,
                            'created_by_id' => $creator->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    });
                } catch (QueryException $exception) {
                    $sqlState = $exception->errorInfo[0] ?? null;
                    if (in_array($sqlState, ['23505', '23000'], true)) {
                        throw InvalidPayloadException::invalid("Group slug [{$command->slug}] already exists on panel [{$command->surface->panel->value}].", ['slug' => $command->slug], '/slug');
                    }

                    throw $exception;
                }

                $receiptId = $this->findReceiptId($connection, $command->surface->panel, $namespace->toWire(), $command->key->value);

                return [
                    'result' => OperationResult::confirmed(
                        $receiptId,
                        new ResourceRef($command->surface->panel, ResourceKind::Group, $groupId),
                        new Revision(1),
                    ),
                    'event' => [
                        'aggregate_type' => 'group',
                        'aggregate_id' => $groupId,
                        'aggregate_revision' => 1,
                        'event_type' => 'sockit.groups.created',
                        'payload' => [
                            'panel' => $command->surface->panel->value,
                            'group_id' => $groupId,
                            'slug' => $command->slug,
                            'join_policy' => $command->joinPolicy->value,
                        ],
                    ],
                ];
            },
            replayChecks: function (ActorContext $verifiedActor, Connection $connection, OperationResult $replay) use ($command): void {
                if (! $replay->resource instanceof ResourceRef) {
                    return;
                }

                $groupRow = $this->lockGroup(
                    $connection,
                    $this->tables->table('groups', $command->surface->panel),
                    $command->surface,
                    $replay->resource->id,
                );
                if (SockitRows::str($groupRow, 'state') !== 'active') {
                    throw OperationException::contentHidden('group.inactive');
                }
            },
        );
    }

    #[Override]
    public function setJoinPolicy(SetGroupJoinPolicyCommand $command): OperationResult
    {
        $this->assertGroupsEnabled($command->surface);

        $actor = $this->runtime->actors()->current();
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'groups.set_join_policy');
        $digest = PayloadDigest::of('groups.set_join_policy', $command->toDigestPayload());

        return $this->runGroupMutation(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
            surface: $command->surface,
            groupId: $command->groupId,
            expectedRevision: $command->expectedRevision,
            action: 'sockit.groups.manage',
            preTransactionChecks: function (ActorContext $verifiedActor) use ($command): void {
                $this->authorizeGroupResource($verifiedActor, 'sockit.groups.manage', $command->surface, $command->groupId);
            },
            mutation: function (ActorContext $verifiedActor, Connection $connection, object $groupRow) use ($command, $namespace): array {
                if (SockitRows::str($groupRow, 'join_policy') === $command->joinPolicy->value) {
                    return $this->noChangeResult($connection, $namespace, $command->key, $command->surface->panel, $command->groupId);
                }

                $groupsTable = $this->tables->table('groups', $command->surface->panel);
                $actualRevision = SockitRows::int($groupRow, 'revision');
                $nextRevision = $actualRevision + 1;
                $now = $this->nowIso();

                $connection->table($groupsTable)
                    ->where('id', $command->groupId)
                    ->where('panel_id', $command->surface->panel->value)
                    ->where('revision', $command->expectedRevision->value)
                    ->update([
                        'join_policy' => $command->joinPolicy->value,
                        'revision' => $nextRevision,
                        'updated_at' => $now,
                    ]);

                $receiptId = $this->findReceiptId($connection, $command->surface->panel, $namespace->toWire(), $command->key->value);

                return [
                    'result' => OperationResult::confirmed(
                        $receiptId,
                        new ResourceRef($command->surface->panel, ResourceKind::Group, $command->groupId),
                        new Revision($nextRevision),
                    ),
                    'event' => [
                        'aggregate_type' => 'group',
                        'aggregate_id' => $command->groupId,
                        'aggregate_revision' => $nextRevision,
                        'event_type' => 'sockit.groups.join_policy_updated',
                        'payload' => [
                            'panel' => $command->surface->panel->value,
                            'group_id' => $command->groupId,
                            'join_policy' => $command->joinPolicy->value,
                        ],
                    ],
                ];
            },
        );
    }

    #[Override]
    public function join(JoinGroupCommand $command): OperationResult
    {
        $this->assertGroupsEnabled($command->surface);

        $actor = $this->runtime->actors()->current();
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'groups.join');
        $digest = PayloadDigest::of('groups.join', $command->toDigestPayload());

        /** @var ?MembershipConsent $consent */
        $consent = null;
        /** @var ?MembershipActivation $activation */
        $activation = null;
        /** @var ?ConsentDecision $consentDecision */
        $consentDecision = null;

        return $this->runGroupMutation(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
            surface: $command->surface,
            groupId: $command->groupId,
            expectedRevision: $command->expectedRevision,
            action: 'sockit.groups.join',
            preTransactionChecks: function (ActorContext $verifiedActor) use ($command, &$consent, &$activation, &$consentDecision): void {
                $subject = $verifiedActor->actor ?? throw OperationException::audienceDenied('actor.anonymous');
                $this->authorizeGroupResource($verifiedActor, 'sockit.groups.join', $command->surface, $command->groupId);

                $consent = $this->runtime->membershipConsent();
                $connection = $this->tables->connection($command->surface->panel);
                $previewGroup = $connection->table($this->tables->table('groups', $command->surface->panel))
                    ->where('panel_id', $command->surface->panel->value)
                    ->where('id', $command->groupId)
                    ->first();

                if (! is_object($previewGroup)) {
                    return;
                }

                $existingMember = $connection->table($this->tables->table('group_members', $command->surface->panel))
                    ->where('group_id', $command->groupId)
                    ->where('actor_type', $subject->type)
                    ->where('actor_id', $subject->id)
                    ->first();

                $previewStatus = is_object($existingMember) ? SockitRows::str($existingMember, 'status') : null;
                if ($previewStatus === GroupMemberStatus::Active->value) {
                    return;
                }

                $previewPolicy = GroupJoinPolicy::from(SockitRows::str($previewGroup, 'join_policy'));

                if ($previewPolicy === GroupJoinPolicy::Auto && ! in_array($previewStatus, [
                    GroupMemberStatus::Pending->value,
                    GroupMemberStatus::Invited->value,
                    GroupMemberStatus::Banned->value,
                ], true)) {
                    $activation = new MembershipActivation(
                        panel: $command->surface->panel,
                        surface: $command->surface,
                        groupId: $command->groupId,
                        subject: $subject,
                        role: GroupMemberRole::Member,
                        transition: 'join',
                    );
                    $consentDecision = $consent->assess($activation);
                    $consentDecision->decision->requireAllowed();
                } elseif (
                    is_object($existingMember)
                    && $previewPolicy === GroupJoinPolicy::Invite
                    && $previewStatus === GroupMemberStatus::Invited->value
                ) {
                    $activation = new MembershipActivation(
                        panel: $command->surface->panel,
                        surface: $command->surface,
                        groupId: $command->groupId,
                        subject: $subject,
                        role: GroupMemberRole::from(SockitRows::str($existingMember, 'role')),
                        transition: 'join',
                    );
                    $consentDecision = $consent->assess($activation);
                    $consentDecision->decision->requireAllowed();
                }
            },
            mutation: function (ActorContext $verifiedActor, Connection $connection, object $groupRow) use (
                $command,
                $namespace,
                &$consent,
                &$activation,
                &$consentDecision,
            ): array {
                $subject = $verifiedActor->actor ?? throw OperationException::audienceDenied('actor.anonymous');
                $groupsTable = $this->tables->table('groups', $command->surface->panel);
                $membersTable = $this->tables->table('group_members', $command->surface->panel);

                $policy = GroupJoinPolicy::from(SockitRows::str($groupRow, 'join_policy'));
                $existingMember = $this->lockMemberRow($connection, $membersTable, $command->groupId, $subject);
                $existingStatus = is_object($existingMember) ? SockitRows::str($existingMember, 'status') : null;

                if ($existingStatus === GroupMemberStatus::Banned->value) {
                    throw AuthorizationDeniedException::denied('groups.member_banned');
                }

                if ($policy === GroupJoinPolicy::Closed) {
                    throw AuthorizationDeniedException::denied('groups.join_policy_closed');
                }

                if ($existingStatus === GroupMemberStatus::Active->value) {
                    return $this->noChangeResult($connection, $namespace, $command->key, $command->surface->panel, $command->groupId);
                }

                $actualRevision = SockitRows::int($groupRow, 'revision');
                $membershipGen = SockitRows::int($groupRow, 'membership_generation', 1);
                $nextRevision = $actualRevision + 1;
                $nextGeneration = $membershipGen + 1;
                $now = $this->nowIso();

                if ($policy === GroupJoinPolicy::Approval) {
                    if ($existingStatus === GroupMemberStatus::Pending->value) {
                        return $this->noChangeResult($connection, $namespace, $command->key, $command->surface->panel, $command->groupId);
                    }

                    if ($existingStatus === GroupMemberStatus::Invited->value) {
                        throw AuthorizationDeniedException::denied('groups.obsolete_state_must_leave');
                    }

                    $this->upsertMemberRow($connection, $membersTable, $command->groupId, $subject, is_object($existingMember), [
                        'role' => GroupMemberRole::Member->value,
                        'status' => GroupMemberStatus::Pending->value,
                        'consent_generation' => null,
                        'joined_at' => null,
                        'updated_at' => $now,
                    ]);

                    $connection->table($groupsTable)
                        ->where('id', $command->groupId)
                        ->where('panel_id', $command->surface->panel->value)
                        ->where('revision', $command->expectedRevision->value)
                        ->update([
                            'revision' => $nextRevision,
                            'membership_generation' => $nextGeneration,
                            'updated_at' => $now,
                        ]);

                    $receiptId = $this->findReceiptId($connection, $command->surface->panel, $namespace->toWire(), $command->key->value);

                    return [
                        'result' => OperationResult::confirmed(
                            $receiptId,
                            new ResourceRef($command->surface->panel, ResourceKind::Group, $command->groupId),
                            new Revision($nextRevision),
                        ),
                        'event' => [
                            'aggregate_type' => 'group',
                            'aggregate_id' => $command->groupId,
                            'aggregate_revision' => $nextRevision,
                            'event_type' => 'sockit.groups.join_requested',
                            'payload' => [
                                'panel' => $command->surface->panel->value,
                                'group_id' => $command->groupId,
                                'member' => $subject->toWire(),
                                'membership_generation' => $nextGeneration,
                            ],
                        ],
                    ];
                }

                $effectiveRole = GroupMemberRole::Member;

                if ($policy === GroupJoinPolicy::Auto && in_array($existingStatus, [
                    GroupMemberStatus::Pending->value,
                    GroupMemberStatus::Invited->value,
                ], true)) {
                    throw AuthorizationDeniedException::denied('groups.obsolete_state_must_leave');
                }

                if ($policy === GroupJoinPolicy::Invite) {
                    if ($existingStatus === GroupMemberStatus::Pending->value) {
                        throw AuthorizationDeniedException::denied('groups.obsolete_state_must_leave');
                    }

                    if (! is_object($existingMember) || $existingStatus !== GroupMemberStatus::Invited->value) {
                        throw AuthorizationDeniedException::denied('groups.invite_required');
                    }

                    $effectiveRole = GroupMemberRole::from(SockitRows::str($existingMember, 'role'));
                }

                if (! $consent instanceof MembershipConsent || ! $activation instanceof MembershipActivation || ! $consentDecision instanceof ConsentDecision || $activation->role !== $effectiveRole) {
                    throw OperationException::revisionConflict($command->expectedRevision->value, $actualRevision);
                }

                $consent->recheck($activation, $consentDecision)->requireAllowed();

                $this->upsertMemberRow($connection, $membersTable, $command->groupId, $subject, is_object($existingMember), [
                    'role' => $effectiveRole->value,
                    'status' => GroupMemberStatus::Active->value,
                    'consent_generation' => $consentDecision->generation,
                    'joined_at' => $now,
                    'updated_at' => $now,
                ]);

                $connection->table($groupsTable)
                    ->where('id', $command->groupId)
                    ->where('panel_id', $command->surface->panel->value)
                    ->where('revision', $command->expectedRevision->value)
                    ->update([
                        'revision' => $nextRevision,
                        'membership_generation' => $nextGeneration,
                        'updated_at' => $now,
                    ]);

                $receiptId = $this->findReceiptId($connection, $command->surface->panel, $namespace->toWire(), $command->key->value);

                return [
                    'result' => OperationResult::confirmed(
                        $receiptId,
                        new ResourceRef($command->surface->panel, ResourceKind::Group, $command->groupId),
                        new Revision($nextRevision),
                    ),
                    'event' => [
                        'aggregate_type' => 'group',
                        'aggregate_id' => $command->groupId,
                        'aggregate_revision' => $nextRevision,
                        'event_type' => 'sockit.groups.member_joined',
                        'payload' => [
                            'panel' => $command->surface->panel->value,
                            'group_id' => $command->groupId,
                            'member' => $subject->toWire(),
                            'role' => $effectiveRole->value,
                            'membership_generation' => $nextGeneration,
                        ],
                    ],
                ];
            },
        );
    }

    #[Override]
    public function approve(ApproveGroupMemberCommand $command): OperationResult
    {
        $this->assertGroupsEnabled($command->surface);

        $actor = $this->runtime->actors()->current();
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'groups.approve');
        $digest = PayloadDigest::of('groups.approve', $command->toDigestPayload());

        $activation = new MembershipActivation(
            panel: $command->surface->panel,
            surface: $command->surface,
            groupId: $command->groupId,
            subject: $command->member,
            role: $command->role,
            transition: 'approve',
        );

        /** @var ?MembershipConsent $consent */
        $consent = null;
        /** @var ?ConsentDecision $consentDecision */
        $consentDecision = null;

        return $this->runGroupMutation(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
            surface: $command->surface,
            groupId: $command->groupId,
            expectedRevision: $command->expectedRevision,
            action: 'sockit.groups.manage',
            preTransactionChecks: function (ActorContext $verifiedActor) use ($command, $activation, &$consent, &$consentDecision): void {
                $this->authorizeGroupResource($verifiedActor, 'sockit.groups.manage', $command->surface, $command->groupId);
                $consent = $this->runtime->membershipConsent();
                $existingMember = $this->tables->connection($command->surface->panel)
                    ->table($this->tables->table('group_members', $command->surface->panel))
                    ->where('group_id', $command->groupId)
                    ->where('actor_type', $command->member->type)
                    ->where('actor_id', $command->member->id)
                    ->first();

                if (is_object($existingMember) && SockitRows::str($existingMember, 'status') === GroupMemberStatus::Active->value) {
                    return;
                }

                $consentDecision = $consent->assess($activation);
                $consentDecision->decision->requireAllowed();
            },
            mutation: function (ActorContext $verifiedActor, Connection $connection, object $groupRow) use (
                $command,
                $namespace,
                $activation,
                &$consent,
                &$consentDecision,
            ): array {
                $policy = GroupJoinPolicy::from(SockitRows::str($groupRow, 'join_policy'));
                if ($policy === GroupJoinPolicy::Closed) {
                    throw AuthorizationDeniedException::denied('groups.join_policy_closed');
                }

                if ($policy !== GroupJoinPolicy::Approval) {
                    throw AuthorizationDeniedException::denied('groups.approval_policy_required');
                }

                $groupsTable = $this->tables->table('groups', $command->surface->panel);
                $membersTable = $this->tables->table('group_members', $command->surface->panel);

                $existingMember = $this->lockMemberRow($connection, $membersTable, $command->groupId, $command->member);

                if (! is_object($existingMember) || SockitRows::str($existingMember, 'status') !== GroupMemberStatus::Pending->value) {
                    throw AuthorizationDeniedException::denied('groups.not_pending');
                }

                if (! $consent instanceof MembershipConsent || ! $consentDecision instanceof ConsentDecision) {
                    throw OperationException::providerUnavailable(MembershipConsent::class);
                }

                $consent->recheck($activation, $consentDecision)->requireAllowed();

                $actualRevision = SockitRows::int($groupRow, 'revision');
                $nextRevision = $actualRevision + 1;
                $nextGeneration = SockitRows::int($groupRow, 'membership_generation', 1) + 1;
                $now = $this->nowIso();

                $connection->table($membersTable)
                    ->where('group_id', $command->groupId)
                    ->where('actor_type', $command->member->type)
                    ->where('actor_id', $command->member->id)
                    ->update([
                        'role' => $command->role->value,
                        'status' => GroupMemberStatus::Active->value,
                        'consent_generation' => $consentDecision->generation,
                        'joined_at' => $now,
                        'updated_at' => $now,
                    ]);

                $connection->table($groupsTable)
                    ->where('id', $command->groupId)
                    ->where('panel_id', $command->surface->panel->value)
                    ->where('revision', $command->expectedRevision->value)
                    ->update([
                        'revision' => $nextRevision,
                        'membership_generation' => $nextGeneration,
                        'updated_at' => $now,
                    ]);

                $receiptId = $this->findReceiptId($connection, $command->surface->panel, $namespace->toWire(), $command->key->value);

                return [
                    'result' => OperationResult::confirmed(
                        $receiptId,
                        new ResourceRef($command->surface->panel, ResourceKind::Group, $command->groupId),
                        new Revision($nextRevision),
                    ),
                    'event' => [
                        'aggregate_type' => 'group',
                        'aggregate_id' => $command->groupId,
                        'aggregate_revision' => $nextRevision,
                        'event_type' => 'sockit.groups.member_approved',
                        'payload' => [
                            'panel' => $command->surface->panel->value,
                            'group_id' => $command->groupId,
                            'member' => $command->member->toWire(),
                            'role' => $command->role->value,
                            'membership_generation' => $nextGeneration,
                        ],
                    ],
                ];
            },
        );
    }

    #[Override]
    public function invite(InviteGroupMemberCommand $command): OperationResult
    {
        $this->assertGroupsEnabled($command->surface);

        $actor = $this->runtime->actors()->current();
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'groups.invite');
        $digest = PayloadDigest::of('groups.invite', $command->toDigestPayload());

        return $this->runGroupMutation(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
            surface: $command->surface,
            groupId: $command->groupId,
            expectedRevision: $command->expectedRevision,
            action: 'sockit.groups.manage',
            preTransactionChecks: function (ActorContext $verifiedActor) use ($command): void {
                $this->authorizeGroupResource($verifiedActor, 'sockit.groups.manage', $command->surface, $command->groupId);
            },
            mutation: function (ActorContext $verifiedActor, Connection $connection, object $groupRow) use ($command, $namespace): array {
                $policy = GroupJoinPolicy::from(SockitRows::str($groupRow, 'join_policy'));
                if ($policy === GroupJoinPolicy::Closed) {
                    throw AuthorizationDeniedException::denied('groups.join_policy_closed');
                }

                if ($policy !== GroupJoinPolicy::Invite) {
                    throw AuthorizationDeniedException::denied('groups.invite_policy_required');
                }

                $groupsTable = $this->tables->table('groups', $command->surface->panel);
                $membersTable = $this->tables->table('group_members', $command->surface->panel);

                $existingMember = $this->lockMemberRow($connection, $membersTable, $command->groupId, $command->member);
                $existingStatus = is_object($existingMember) ? SockitRows::str($existingMember, 'status') : null;

                if ($existingStatus === GroupMemberStatus::Banned->value) {
                    throw AuthorizationDeniedException::denied('groups.member_banned');
                }

                if ($existingStatus === GroupMemberStatus::Active->value) {
                    throw AuthorizationDeniedException::denied('groups.already_member');
                }

                if ($existingStatus === GroupMemberStatus::Pending->value) {
                    throw AuthorizationDeniedException::denied('groups.obsolete_state_must_leave');
                }

                if (
                    is_object($existingMember)
                    && $existingStatus === GroupMemberStatus::Invited->value
                    && SockitRows::str($existingMember, 'role') === $command->role->value
                ) {
                    return $this->noChangeResult($connection, $namespace, $command->key, $command->surface->panel, $command->groupId);
                }

                $actualRevision = SockitRows::int($groupRow, 'revision');
                $nextRevision = $actualRevision + 1;
                $nextGeneration = SockitRows::int($groupRow, 'membership_generation', 1) + 1;
                $now = $this->nowIso();

                $this->upsertMemberRow($connection, $membersTable, $command->groupId, $command->member, is_object($existingMember), [
                    'role' => $command->role->value,
                    'status' => GroupMemberStatus::Invited->value,
                    'consent_generation' => null,
                    'joined_at' => null,
                    'updated_at' => $now,
                ]);

                $connection->table($groupsTable)
                    ->where('id', $command->groupId)
                    ->where('panel_id', $command->surface->panel->value)
                    ->where('revision', $command->expectedRevision->value)
                    ->update([
                        'revision' => $nextRevision,
                        'membership_generation' => $nextGeneration,
                        'updated_at' => $now,
                    ]);

                $receiptId = $this->findReceiptId($connection, $command->surface->panel, $namespace->toWire(), $command->key->value);

                return [
                    'result' => OperationResult::confirmed(
                        $receiptId,
                        new ResourceRef($command->surface->panel, ResourceKind::Group, $command->groupId),
                        new Revision($nextRevision),
                    ),
                    'event' => [
                        'aggregate_type' => 'group',
                        'aggregate_id' => $command->groupId,
                        'aggregate_revision' => $nextRevision,
                        'event_type' => 'sockit.groups.member_invited',
                        'payload' => [
                            'panel' => $command->surface->panel->value,
                            'group_id' => $command->groupId,
                            'member' => $command->member->toWire(),
                            'role' => $command->role->value,
                            'membership_generation' => $nextGeneration,
                        ],
                    ],
                ];
            },
        );
    }

    #[Override]
    public function addMember(AddGroupMemberCommand $command): OperationResult
    {
        $this->assertGroupsEnabled($command->surface);

        $actor = $this->runtime->actors()->current();
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'groups.add_member');
        $digest = PayloadDigest::of('groups.add_member', $command->toDigestPayload());

        $activation = new MembershipActivation(
            panel: $command->surface->panel,
            surface: $command->surface,
            groupId: $command->groupId,
            subject: $command->member,
            role: $command->role,
            transition: 'add_member',
        );

        /** @var ?MembershipConsent $consent */
        $consent = null;
        /** @var ?ConsentDecision $consentDecision */
        $consentDecision = null;

        return $this->runGroupMutation(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
            surface: $command->surface,
            groupId: $command->groupId,
            expectedRevision: $command->expectedRevision,
            action: 'sockit.groups.manage',
            preTransactionChecks: function (ActorContext $verifiedActor) use ($command, $activation, &$consent, &$consentDecision): void {
                $this->authorizeGroupResource($verifiedActor, 'sockit.groups.manage', $command->surface, $command->groupId);
                $consent = $this->runtime->membershipConsent();

                $connection = $this->tables->connection($command->surface->panel);
                $previewGroup = $connection->table($this->tables->table('groups', $command->surface->panel))
                    ->where('panel_id', $command->surface->panel->value)
                    ->where('id', $command->groupId)
                    ->first();

                if (! is_object($previewGroup)) {
                    return;
                }

                $existingMember = $connection->table($this->tables->table('group_members', $command->surface->panel))
                    ->where('group_id', $command->groupId)
                    ->where('actor_type', $command->member->type)
                    ->where('actor_id', $command->member->id)
                    ->first();

                if (
                    is_object($existingMember)
                    && SockitRows::str($existingMember, 'status') === GroupMemberStatus::Active->value
                    && SockitRows::str($existingMember, 'role') === $command->role->value
                ) {
                    return;
                }

                $previewPolicy = GroupJoinPolicy::from(SockitRows::str($previewGroup, 'join_policy'));
                $previewStatus = is_object($existingMember) ? SockitRows::str($existingMember, 'status') : null;

                if ($previewPolicy === GroupJoinPolicy::Auto && ! in_array($previewStatus, [
                    GroupMemberStatus::Pending->value,
                    GroupMemberStatus::Invited->value,
                    GroupMemberStatus::Banned->value,
                ], true)) {
                    $consentDecision = $consent->assess($activation);
                    $consentDecision->decision->requireAllowed();
                }
            },
            mutation: function (ActorContext $verifiedActor, Connection $connection, object $groupRow) use (
                $command,
                $namespace,
                $activation,
                &$consent,
                &$consentDecision,
            ): array {
                $policy = GroupJoinPolicy::from(SockitRows::str($groupRow, 'join_policy'));
                if ($policy === GroupJoinPolicy::Closed) {
                    throw AuthorizationDeniedException::denied('groups.join_policy_closed');
                }

                if ($policy !== GroupJoinPolicy::Auto) {
                    throw AuthorizationDeniedException::denied('groups.auto_policy_required');
                }

                $groupsTable = $this->tables->table('groups', $command->surface->panel);
                $membersTable = $this->tables->table('group_members', $command->surface->panel);

                $existingMember = $this->lockMemberRow($connection, $membersTable, $command->groupId, $command->member);
                $existingStatus = is_object($existingMember) ? SockitRows::str($existingMember, 'status') : null;

                if ($existingStatus === GroupMemberStatus::Banned->value) {
                    throw AuthorizationDeniedException::denied('groups.member_banned');
                }

                if (
                    is_object($existingMember)
                    && $existingStatus === GroupMemberStatus::Active->value
                    && SockitRows::str($existingMember, 'role') === $command->role->value
                ) {
                    return $this->noChangeResult($connection, $namespace, $command->key, $command->surface->panel, $command->groupId);
                }

                if (in_array($existingStatus, [
                    GroupMemberStatus::Pending->value,
                    GroupMemberStatus::Invited->value,
                ], true)) {
                    throw AuthorizationDeniedException::denied('groups.obsolete_state_must_leave');
                }

                if (! $consent instanceof MembershipConsent || ! $consentDecision instanceof ConsentDecision) {
                    throw OperationException::revisionConflict($command->expectedRevision->value, SockitRows::int($groupRow, 'revision'));
                }

                $consent->recheck($activation, $consentDecision)->requireAllowed();

                $actualRevision = SockitRows::int($groupRow, 'revision');
                $nextRevision = $actualRevision + 1;
                $nextGeneration = SockitRows::int($groupRow, 'membership_generation', 1) + 1;
                $now = $this->nowIso();

                $this->upsertMemberRow($connection, $membersTable, $command->groupId, $command->member, is_object($existingMember), [
                    'role' => $command->role->value,
                    'status' => GroupMemberStatus::Active->value,
                    'consent_generation' => $consentDecision->generation,
                    'joined_at' => $now,
                    'updated_at' => $now,
                ]);

                $connection->table($groupsTable)
                    ->where('id', $command->groupId)
                    ->where('panel_id', $command->surface->panel->value)
                    ->where('revision', $command->expectedRevision->value)
                    ->update([
                        'revision' => $nextRevision,
                        'membership_generation' => $nextGeneration,
                        'updated_at' => $now,
                    ]);

                $receiptId = $this->findReceiptId($connection, $command->surface->panel, $namespace->toWire(), $command->key->value);

                return [
                    'result' => OperationResult::confirmed(
                        $receiptId,
                        new ResourceRef($command->surface->panel, ResourceKind::Group, $command->groupId),
                        new Revision($nextRevision),
                    ),
                    'event' => [
                        'aggregate_type' => 'group',
                        'aggregate_id' => $command->groupId,
                        'aggregate_revision' => $nextRevision,
                        'event_type' => 'sockit.groups.member_added',
                        'payload' => [
                            'panel' => $command->surface->panel->value,
                            'group_id' => $command->groupId,
                            'member' => $command->member->toWire(),
                            'role' => $command->role->value,
                            'membership_generation' => $nextGeneration,
                        ],
                    ],
                ];
            },
        );
    }

    #[Override]
    public function leave(LeaveGroupCommand $command): OperationResult
    {
        $this->assertGroupsEnabled($command->surface);

        $actor = $this->runtime->actors()->current();
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'groups.leave');
        $digest = PayloadDigest::of('groups.leave', $command->toDigestPayload());

        return $this->runGroupMutation(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
            surface: $command->surface,
            groupId: $command->groupId,
            expectedRevision: $command->expectedRevision,
            action: 'sockit.groups.leave',
            preTransactionChecks: function (ActorContext $verifiedActor) use ($command): void {
                $this->authorizeGroupResource($verifiedActor, 'sockit.groups.leave', $command->surface, $command->groupId);
            },
            mutation: function (ActorContext $verifiedActor, Connection $connection, object $groupRow) use ($command, $namespace): array {
                $subject = $verifiedActor->actor ?? throw OperationException::audienceDenied('actor.anonymous');
                $groupsTable = $this->tables->table('groups', $command->surface->panel);
                $membersTable = $this->tables->table('group_members', $command->surface->panel);

                $existingMember = $this->lockMemberRow($connection, $membersTable, $command->groupId, $subject);
                $existingStatus = is_object($existingMember) ? SockitRows::str($existingMember, 'status') : null;

                if ($existingStatus === GroupMemberStatus::Banned->value) {
                    throw AuthorizationDeniedException::denied('groups.member_banned');
                }

                if (! in_array($existingStatus, [
                    GroupMemberStatus::Active->value,
                    GroupMemberStatus::Pending->value,
                    GroupMemberStatus::Invited->value,
                ], true)) {
                    return $this->noChangeResult($connection, $namespace, $command->key, $command->surface->panel, $command->groupId);
                }

                $actualRevision = SockitRows::int($groupRow, 'revision');
                $nextRevision = $actualRevision + 1;
                $nextGeneration = SockitRows::int($groupRow, 'membership_generation', 1) + 1;
                $now = $this->nowIso();

                $connection->table($membersTable)
                    ->where('group_id', $command->groupId)
                    ->where('actor_type', $subject->type)
                    ->where('actor_id', $subject->id)
                    ->update([
                        'status' => GroupMemberStatus::Left->value,
                        'updated_at' => $now,
                    ]);

                $connection->table($groupsTable)
                    ->where('id', $command->groupId)
                    ->where('panel_id', $command->surface->panel->value)
                    ->where('revision', $command->expectedRevision->value)
                    ->update([
                        'revision' => $nextRevision,
                        'membership_generation' => $nextGeneration,
                        'updated_at' => $now,
                    ]);

                $receiptId = $this->findReceiptId($connection, $command->surface->panel, $namespace->toWire(), $command->key->value);

                return [
                    'result' => OperationResult::confirmed(
                        $receiptId,
                        new ResourceRef($command->surface->panel, ResourceKind::Group, $command->groupId),
                        new Revision($nextRevision),
                    ),
                    'event' => [
                        'aggregate_type' => 'group',
                        'aggregate_id' => $command->groupId,
                        'aggregate_revision' => $nextRevision,
                        'event_type' => 'sockit.groups.member_left',
                        'payload' => [
                            'panel' => $command->surface->panel->value,
                            'group_id' => $command->groupId,
                            'member' => $subject->toWire(),
                            'membership_generation' => $nextGeneration,
                        ],
                    ],
                ];
            },
        );
    }

    #[Override]
    public function removeMember(RemoveGroupMemberCommand $command): OperationResult
    {
        $this->assertGroupsEnabled($command->surface);

        $actor = $this->runtime->actors()->current();
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'groups.remove_member');
        $digest = PayloadDigest::of('groups.remove_member', $command->toDigestPayload());

        return $this->runGroupMutation(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
            surface: $command->surface,
            groupId: $command->groupId,
            expectedRevision: $command->expectedRevision,
            action: 'sockit.groups.manage',
            preTransactionChecks: function (ActorContext $verifiedActor) use ($command): void {
                $this->authorizeGroupResource($verifiedActor, 'sockit.groups.manage', $command->surface, $command->groupId);
            },
            mutation: function (ActorContext $verifiedActor, Connection $connection, object $groupRow) use ($command, $namespace): array {
                $groupsTable = $this->tables->table('groups', $command->surface->panel);
                $membersTable = $this->tables->table('group_members', $command->surface->panel);
                $existingMember = $this->lockMemberRow($connection, $membersTable, $command->groupId, $command->member);
                $existingStatus = is_object($existingMember) ? SockitRows::str($existingMember, 'status') : null;

                if ($existingStatus === GroupMemberStatus::Banned->value) {
                    throw AuthorizationDeniedException::denied('groups.member_banned');
                }

                if (! in_array($existingStatus, [
                    GroupMemberStatus::Active->value,
                    GroupMemberStatus::Pending->value,
                    GroupMemberStatus::Invited->value,
                ], true)) {
                    return $this->noChangeResult($connection, $namespace, $command->key, $command->surface->panel, $command->groupId);
                }

                $actualRevision = SockitRows::int($groupRow, 'revision');
                $nextRevision = $actualRevision + 1;
                $nextGeneration = SockitRows::int($groupRow, 'membership_generation', 1) + 1;
                $now = $this->nowIso();

                $connection->table($membersTable)
                    ->where('group_id', $command->groupId)
                    ->where('actor_type', $command->member->type)
                    ->where('actor_id', $command->member->id)
                    ->update([
                        'status' => GroupMemberStatus::Removed->value,
                        'updated_at' => $now,
                    ]);

                $connection->table($groupsTable)
                    ->where('id', $command->groupId)
                    ->where('panel_id', $command->surface->panel->value)
                    ->where('revision', $command->expectedRevision->value)
                    ->update([
                        'revision' => $nextRevision,
                        'membership_generation' => $nextGeneration,
                        'updated_at' => $now,
                    ]);

                $receiptId = $this->findReceiptId($connection, $command->surface->panel, $namespace->toWire(), $command->key->value);

                return [
                    'result' => OperationResult::confirmed(
                        $receiptId,
                        new ResourceRef($command->surface->panel, ResourceKind::Group, $command->groupId),
                        new Revision($nextRevision),
                    ),
                    'event' => [
                        'aggregate_type' => 'group',
                        'aggregate_id' => $command->groupId,
                        'aggregate_revision' => $nextRevision,
                        'event_type' => 'sockit.groups.member_removed',
                        'payload' => [
                            'panel' => $command->surface->panel->value,
                            'group_id' => $command->groupId,
                            'member' => $command->member->toWire(),
                            'membership_generation' => $nextGeneration,
                        ],
                    ],
                ];
            },
        );
    }

    #[Override]
    public function get(PanelId $panel, string $groupId): GroupView
    {
        $panelDef = $this->panels->get($panel);

        if (! $panelDef->hasModule('groups')) {
            throw PanelException::bindingMismatch($panel->value, "Panel [{$panel->value}] does not enable module [groups].");
        }

        $viewer = $this->runtime->actors()->current();
        $connection = $this->tables->connection($panel);
        $row = $connection
            ->table($this->tables->table('groups', $panel))
            ->where('panel_id', $panel->value)
            ->where('id', $groupId)
            ->first();

        if (! is_object($row)) {
            throw OperationException::contentHidden('group.not_found');
        }

        $visibility = GroupVisibility::from(SockitRows::str($row, 'visibility'));
        $creator = new ActorRef(SockitRows::str($row, 'created_by_type'), SockitRows::str($row, 'created_by_id'));
        $surfaceKind = new SurfaceKindId(SockitRows::str($row, 'surface_kind'));

        if (! $this->surfaces->has($panel, $surfaceKind)) {
            throw OperationException::audienceDenied('audience.unknown');
        }

        $surfaceDef = $this->surfaces->get($panel, $surfaceKind);
        $groupSurface = new SurfaceRef(
            panel: $panel,
            kind: $surfaceKind,
            host: new HostEntityRef(SockitRows::str($row, 'surface_host_type'), SockitRows::str($row, 'surface_host_value')),
            incarnation: new Incarnation(SockitRows::int($row, 'surface_incarnation', 1)),
        );

        $surfaceInstanceRow = $connection->table($this->tables->table('surface_instances', $panel))
            ->where('panel_id', $panel->value)
            ->where('kind', $groupSurface->kind->value)
            ->where('host_entity_type', $groupSurface->host->type)
            ->where('host_entity_value', $groupSurface->host->value)
            ->orderByDesc('incarnation')
            ->first();

        if (
            ! is_object($surfaceInstanceRow)
            || SockitRows::int($surfaceInstanceRow, 'incarnation', 1) !== $groupSurface->incarnation->value
            || SockitRows::str($surfaceInstanceRow, 'lifecycle_state') === SurfaceLifecycleState::Deleted->value
        ) {
            throw OperationException::audienceDenied('audience.denied.surface_inactive');
        }

        $surfaceArchived = SockitRows::str($surfaceInstanceRow, 'lifecycle_state') === SurfaceLifecycleState::Archived->value;

        $spec = $visibility === GroupVisibility::Public
            ? AudienceSpec::platform()
            : AudienceSpec::group(new GroupRef($groupId));

        $verdict = $this->runtime->audienceGate()->read($viewer, new AudienceSubject(
            key: $groupId,
            spec: $spec,
            author: $creator,
            surface: $groupSurface,
            installation: $viewer->installation,
            surfaceArchived: $surfaceArchived,
            archiveReaders: $surfaceDef->archiveReaders,
        ));

        if (! $verdict->canRead()) {
            throw OperationException::audienceDenied($verdict->reason);
        }

        return new GroupView(
            id: SockitRows::str($row, 'id'),
            panel: $panel,
            slug: SockitRows::str($row, 'slug'),
            title: SockitRows::str($row, 'title'),
            visibility: $visibility,
            state: SockitRows::str($row, 'state'),
            revision: new Revision(SockitRows::int($row, 'revision', 1)),
            createdBy: $creator,
            createdAt: SockitRows::date($row, 'created_at'),
            updatedAt: SockitRows::date($row, 'updated_at'),
            joinPolicy: GroupJoinPolicy::from(SockitRows::str($row, 'join_policy')),
            membershipGeneration: SockitRows::int($row, 'membership_generation', 1),
        );
    }

    /**
     * @param  Closure(ActorContext): void  $preTransactionChecks
     * @param  Closure(ActorContext, Connection, object): array{result: OperationResult, event?: array{aggregate_type: string, aggregate_id: string, aggregate_revision: int, event_type: string, payload: array<string, mixed>}|null}  $mutation
     */
    private function runGroupMutation(
        CommandKey $key,
        CommandNamespace $namespace,
        PayloadDigest $digest,
        SurfaceRef $surface,
        string $groupId,
        Revision $expectedRevision,
        string $action,
        Closure $preTransactionChecks,
        Closure $mutation,
    ): OperationResult {
        $envelope = new CommandEnvelope(
            key: $key,
            namespace: $namespace,
            digest: $digest,
            expectedRevision: $expectedRevision,
        );

        return $this->runtime->envelope()->run(
            envelope: $envelope,
            surface: $surface,
            action: $action,
            preTransactionChecks: $preTransactionChecks,
            mutation: function (ActorContext $verifiedActor, Connection $connection) use ($surface, $groupId, $expectedRevision, $mutation): array {
                $groupsTable = $this->tables->table('groups', $surface->panel);
                $groupRow = $this->lockGroup($connection, $groupsTable, $surface, $groupId);

                if (SockitRows::str($groupRow, 'state') !== 'active') {
                    throw OperationException::contentHidden('group.inactive');
                }

                $actualRevision = SockitRows::int($groupRow, 'revision');
                if ($actualRevision !== $expectedRevision->value) {
                    throw OperationException::revisionConflict($expectedRevision->value, $actualRevision);
                }

                return $mutation($verifiedActor, $connection, $groupRow);
            },
            replayChecks: function (ActorContext $verifiedActor, Connection $connection, OperationResult $replay) use ($surface, $groupId): void {
                $groupRow = $this->lockGroup(
                    $connection,
                    $this->tables->table('groups', $surface->panel),
                    $surface,
                    $groupId,
                );

                if (SockitRows::str($groupRow, 'state') !== 'active') {
                    throw OperationException::contentHidden('group.inactive');
                }
            },
        );
    }

    private function authorizeGroupResource(
        ActorContext $actor,
        string $action,
        SurfaceRef $surface,
        string $groupId,
    ): void {
        $this->runtime->authorizer()->decide(new ActionRequest(
            actor: $actor,
            action: $action,
            surface: $surface,
            resource: new ResourceRef($surface->panel, ResourceKind::Group, $groupId),
        ))->requireAllowed();
    }

    private function assertGroupsEnabled(SurfaceRef $surface): GroupsOptions
    {
        $panelDef = $this->panels->get($surface->panel);
        $surfaceDef = $this->surfaces->get($surface->panel, $surface->kind);

        if (! $panelDef->hasModule('groups')) {
            throw PanelException::bindingMismatch($surface->panel->value, "Panel [{$surface->panel->value}] does not enable module [groups].");
        }

        if ($surfaceDef->features !== [] && ! $surfaceDef->supportsFeature('groups')) {
            throw PanelException::bindingMismatch($surface->panel->value, "Surface [{$surfaceDef->key()}] does not enable feature [groups].");
        }

        return GroupsOptions::fromSettings($panelDef->settingsFor('groups'));
    }

    private function lockGroup(Connection $connection, string $groupsTable, SurfaceRef $surface, string $groupId): object
    {
        $query = $connection->table($groupsTable)
            ->where('panel_id', $surface->panel->value)
            ->where('id', $groupId);

        if ($connection->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if (! is_object($row)) {
            throw OperationException::contentHidden('group.not_found');
        }

        if (
            SockitRows::str($row, 'surface_kind') !== $surface->kind->value
            || SockitRows::str($row, 'surface_host_type') !== $surface->host->type
            || SockitRows::str($row, 'surface_host_value') !== $surface->host->value
            || SockitRows::int($row, 'surface_incarnation') !== $surface->incarnation->value
        ) {
            throw OperationException::audienceDenied('group.surface_mismatch');
        }

        return $row;
    }

    private function lockMemberRow(Connection $connection, string $membersTable, string $groupId, ActorRef $member): ?object
    {
        $query = $connection->table($membersTable)
            ->where('group_id', $groupId)
            ->where('actor_type', $member->type)
            ->where('actor_id', $member->id);

        if ($connection->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return is_object($row) ? $row : null;
    }

    /** @param array<string, mixed> $columns */
    private function upsertMemberRow(
        Connection $connection,
        string $membersTable,
        string $groupId,
        ActorRef $member,
        bool $exists,
        array $columns,
    ): void {
        if ($exists) {
            $connection->table($membersTable)
                ->where('group_id', $groupId)
                ->where('actor_type', $member->type)
                ->where('actor_id', $member->id)
                ->update($columns);

            return;
        }

        $connection->table($membersTable)->insert([
            'group_id' => $groupId,
            'actor_type' => $member->type,
            'actor_id' => $member->id,
            ...$columns,
        ]);
    }

    /** @return array{result: OperationResult, event: null} */
    private function noChangeResult(Connection $connection, CommandNamespace $namespace, CommandKey $key, PanelId $panel, string $groupId): array
    {
        $receiptId = $this->findReceiptId($connection, $panel, $namespace->toWire(), $key->value);

        return [
            'result' => OperationResult::rejected(
                SockitErrorCode::RevisionConflict,
                ['group_id' => $groupId, 'reason' => 'groups.no_change'],
                $receiptId,
            ),
            'event' => null,
        ];
    }

    private function findReceiptId(Connection $connection, PanelId $panel, string $namespace, string $commandKey): string
    {
        $row = $connection->table($this->tables->table('command_receipts', $panel))
            ->where('panel_id', $panel->value)
            ->where('command_namespace', $namespace)
            ->where('command_key', $commandKey)
            ->first();

        return is_object($row) ? SockitRows::str($row, 'id') : Ids::uuid7();
    }

    private function nowIso(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
