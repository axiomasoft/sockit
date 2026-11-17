<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Modules\Groups\Data\AddGroupMemberCommand;
use SocKit\Modules\Groups\Data\ApproveGroupMemberCommand;
use SocKit\Modules\Groups\Data\CreateGroupCommand;
use SocKit\Modules\Groups\Data\GroupView;
use SocKit\Modules\Groups\Data\InviteGroupMemberCommand;
use SocKit\Modules\Groups\Data\JoinGroupCommand;
use SocKit\Modules\Groups\Data\LeaveGroupCommand;
use SocKit\Modules\Groups\Data\RemoveGroupMemberCommand;
use SocKit\Modules\Groups\Data\SetGroupJoinPolicyCommand;
use SocKit\Operations\Data\OperationResult;
use SocKit\Panels\Data\PanelId;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface GroupService
{
    public function create(CreateGroupCommand $command): OperationResult;

    public function setJoinPolicy(SetGroupJoinPolicyCommand $command): OperationResult;

    public function join(JoinGroupCommand $command): OperationResult;

    public function approve(ApproveGroupMemberCommand $command): OperationResult;

    public function invite(InviteGroupMemberCommand $command): OperationResult;

    public function addMember(AddGroupMemberCommand $command): OperationResult;

    public function leave(LeaveGroupCommand $command): OperationResult;

    public function removeMember(RemoveGroupMemberCommand $command): OperationResult;

    public function get(PanelId $panel, string $groupId): GroupView;
}
