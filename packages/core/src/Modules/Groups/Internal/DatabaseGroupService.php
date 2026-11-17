<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Override;
use Packman\Attributes\Internal;
use SocKit\Actors\ActorContexts;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\GroupRef;
use SocKit\Database\Internal\SockitRows;
use SocKit\Database\Internal\SockitTables;
use SocKit\Modules\Groups\Configuration\GroupsOptions;
use SocKit\Modules\Groups\Contracts\GroupService;
use SocKit\Modules\Groups\Data\AddGroupMemberCommand;
use SocKit\Modules\Groups\Data\CreateGroupCommand;
use SocKit\Modules\Groups\Data\GroupView;
use SocKit\Modules\Groups\Data\RemoveGroupMemberCommand;
use SocKit\Modules\Groups\Enums\GroupMemberRole;
use SocKit\Modules\Groups\Enums\GroupMemberStatus;
use SocKit\Modules\Groups\Enums\GroupVisibility;
use SocKit\Operations\Contracts\OperationEnvelopeRunner;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\PanelException;
use SocKit\Support\Data\ActorRef;
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
use SocKit\Surfaces\Data\SurfaceDefinition;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Panel-isolated Groups command and query service operating via {@see OperationEnvelopeRunner}.
 */
#[Internal]
final readonly class DatabaseGroupService implements GroupService
{
    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
        private SurfaceDefinitions $surfaces,
        private ActorContexts $actors,
        private OperationEnvelopeRunner $envelope,
        private ActionAuthorizer $authorizer,
        private AudienceGate $audienceGate,
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

        $actor = $this->actors->current();
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'groups.create');
        $digest = PayloadDigest::of('groups.create', $command->toDigestPayload());

        $envelope = new CommandEnvelope(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
        );

        return $this->envelope->run(
            envelope: $envelope,
            surface: $command->surface,
            action: 'sockit.groups.create',
            preTransactionChecks: function (ActorContext $verifiedActor) use ($command): void {
                $this->authorizer->decide(new ActionRequest(
                    actor: $verifiedActor,
                    action: 'sockit.groups.create',
                    surface: $command->surface,
                ))->requireAllowed();
            },
            mutation: function (ActorContext $verifiedActor, Connection $connection) use ($command, $namespace): array {
                $creator = $verifiedActor->actor ?? throw OperationException::audienceDenied('actor.anonymous');
                $groupsTable = $this->tables->table('groups', $command->surface->panel);
                $membersTable = $this->tables->table('group_members', $command->surface->panel);
                $now = $this->nowIso();
                $groupId = Ids::uuid7();

                try {
                    $connection->transaction(static function () use ($connection, $groupsTable, $groupId, $command, $creator, $now): void {
                        $connection->table($groupsTable)->insert([
                            'id' => $groupId,
                            'panel_id' => $command->surface->panel->value,
                            'slug' => $command->slug,
                            'title' => $command->title,
                            'visibility' => $command->visibility->value,
                            'state' => 'active',
                            'revision' => 1,
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

                $connection->table($membersTable)->insert([
                    'group_id' => $groupId,
                    'actor_type' => $creator->type,
                    'actor_id' => $creator->id,
                    'role' => GroupMemberRole::Owner->value,
                    'status' => GroupMemberStatus::Active->value,
                    'joined_at' => $now,
                    'updated_at' => $now,
                ]);

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

        $actor = $this->actors->current();
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'groups.add_member');
        $digest = PayloadDigest::of('groups.add_member', $command->toDigestPayload());

        $envelope = new CommandEnvelope(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
            expectedRevision: $command->expectedRevision,
        );

        return $this->envelope->run(
            envelope: $envelope,
            surface: $command->surface,
            action: 'sockit.groups.manage',
            preTransactionChecks: function (ActorContext $verifiedActor) use ($command): void {
                $this->authorizer->decide(new ActionRequest(
                    actor: $verifiedActor,
                    action: 'sockit.groups.manage',
                    surface: $command->surface,
                    resource: new ResourceRef($command->surface->panel, ResourceKind::Group, $command->groupId),
                ))->requireAllowed();
            },
            mutation: function (ActorContext $verifiedActor, Connection $connection) use ($command, $namespace): array {
                $groupsTable = $this->tables->table('groups', $command->surface->panel);
                $membersTable = $this->tables->table('group_members', $command->surface->panel);

                $groupRow = $this->lockGroup($connection, $groupsTable, $command->surface->panel->value, $command->groupId);
                $actualRevision = SockitRows::int($groupRow, 'revision');

                if ($actualRevision !== $command->expectedRevision->value) {
                    throw OperationException::revisionConflict($command->expectedRevision->value, $actualRevision);
                }

                $now = $this->nowIso();
                $nextRevision = $actualRevision + 1;

                $existingMember = $connection->table($membersTable)
                    ->where('group_id', $command->groupId)
                    ->where('actor_type', $command->member->type)
                    ->where('actor_id', $command->member->id)
                    ->first();

                if (is_object($existingMember)) {
                    $connection->table($membersTable)
                        ->where('group_id', $command->groupId)
                        ->where('actor_type', $command->member->type)
                        ->where('actor_id', $command->member->id)
                        ->update([
                            'role' => $command->role->value,
                            'status' => GroupMemberStatus::Active->value,
                            'updated_at' => $now,
                        ]);
                } else {
                    $connection->table($membersTable)->insert([
                        'group_id' => $command->groupId,
                        'actor_type' => $command->member->type,
                        'actor_id' => $command->member->id,
                        'role' => $command->role->value,
                        'status' => GroupMemberStatus::Active->value,
                        'joined_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $connection->table($groupsTable)
                    ->where('id', $command->groupId)
                    ->where('panel_id', $command->surface->panel->value)
                    ->where('revision', $command->expectedRevision->value)
                    ->update(['revision' => $nextRevision, 'updated_at' => $now]);

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

        $actor = $this->actors->current();
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'groups.remove_member');
        $digest = PayloadDigest::of('groups.remove_member', $command->toDigestPayload());

        $envelope = new CommandEnvelope(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
            expectedRevision: $command->expectedRevision,
        );

        return $this->envelope->run(
            envelope: $envelope,
            surface: $command->surface,
            action: 'sockit.groups.manage',
            preTransactionChecks: function (ActorContext $verifiedActor) use ($command): void {
                $this->authorizer->decide(new ActionRequest(
                    actor: $verifiedActor,
                    action: 'sockit.groups.manage',
                    surface: $command->surface,
                    resource: new ResourceRef($command->surface->panel, ResourceKind::Group, $command->groupId),
                ))->requireAllowed();
            },
            mutation: function (ActorContext $verifiedActor, Connection $connection) use ($command, $namespace): array {
                $groupsTable = $this->tables->table('groups', $command->surface->panel);
                $membersTable = $this->tables->table('group_members', $command->surface->panel);

                $groupRow = $this->lockGroup($connection, $groupsTable, $command->surface->panel->value, $command->groupId);
                $actualRevision = SockitRows::int($groupRow, 'revision');

                if ($actualRevision !== $command->expectedRevision->value) {
                    throw OperationException::revisionConflict($command->expectedRevision->value, $actualRevision);
                }

                $now = $this->nowIso();
                $nextRevision = $actualRevision + 1;

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
                    ->update(['revision' => $nextRevision, 'updated_at' => $now]);

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

        $viewer = $this->actors->current();
        $row = $this->tables->connection($panel)
            ->table($this->tables->table('groups', $panel))
            ->where('panel_id', $panel->value)
            ->where('id', $groupId)
            ->first();

        if (! is_object($row)) {
            throw OperationException::contentHidden('group.not_found');
        }

        $visibility = GroupVisibility::from(SockitRows::str($row, 'visibility'));
        $creator = new ActorRef(SockitRows::str($row, 'created_by_type'), SockitRows::str($row, 'created_by_id'));

        if ($visibility !== GroupVisibility::Public) {
            $surfacesForPanel = array_values(array_filter(
                $this->surfaces->all(),
                static fn (SurfaceDefinition $surface): bool => $surface->panel->equals($panel),
            ));

            if ($surfacesForPanel !== []) {
                $firstSurface = new SurfaceRef(
                    panel: $panel,
                    kind: $surfacesForPanel[0]->kind,
                    host: new HostEntityRef($surfacesForPanel[0]->hostType, $groupId),
                    incarnation: Incarnation::initial(),
                );
                $verdict = $this->audienceGate->read($viewer, new AudienceSubject(
                    key: $groupId,
                    spec: AudienceSpec::group(new GroupRef($groupId)),
                    author: $creator,
                    surface: $firstSurface,
                    installation: $viewer->installation,
                ));

                if (! $verdict->canRead()) {
                    throw OperationException::audienceDenied($verdict->reason);
                }
            }
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
        );
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

    private function lockGroup(Connection $connection, string $groupsTable, string $panelId, string $groupId): object
    {
        $query = $connection->table($groupsTable)
            ->where('panel_id', $panelId)
            ->where('id', $groupId);

        if ($connection->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if (! is_object($row)) {
            throw OperationException::contentHidden('group.not_found');
        }

        return $row;
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
