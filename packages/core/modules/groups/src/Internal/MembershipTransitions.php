<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use SocKit\Errors\Enums\ProblemCode;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Groups\Enums\MembershipState;
use SocKit\Groups\Exceptions\GroupException;

final class MembershipTransitions
{
    public static function next(JoinPolicy $policy, ?MembershipState $state, string $action): MembershipState
    {
        if ($action === 'leave' && in_array($state, [MembershipState::Active, MembershipState::Pending, MembershipState::Invited], true)) {
            return MembershipState::Left;
        }
        if ($action === 'join' && in_array($state, [null, MembershipState::Left], true)) {
            return match ($policy) {
                JoinPolicy::Auto => MembershipState::Active,
                JoinPolicy::Approval => MembershipState::Pending,
                default => throw new GroupException(ProblemCode::RevisionConflict),
            };
        }
        if ($action === 'join' && $policy === JoinPolicy::Invite && $state === MembershipState::Invited) {
            return MembershipState::Active;
        }
        if ($action === 'approve' && $policy === JoinPolicy::Approval && $state === MembershipState::Pending) {
            return MembershipState::Active;
        }
        if ($action === 'invite' && $policy === JoinPolicy::Invite && in_array($state, [null, MembershipState::Left], true)) {
            return MembershipState::Invited;
        }

        throw new GroupException(ProblemCode::RevisionConflict);
    }
}
