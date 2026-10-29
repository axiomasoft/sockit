<?php

declare(strict_types=1);

use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Groups\Data\ConsentDecision;
use SocKit\Groups\Data\MembershipActivation;
use SocKit\Groups\Data\MembershipCommand;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Groups\Enums\MembershipState;
use SocKit\Groups\Exceptions\GroupException;
use SocKit\Groups\Internal\MembershipTransitions;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Support\Ids;

it('defines every policy state action transition including obsolete requests', function (): void {
    foreach (JoinPolicy::cases() as $policy) {
        foreach ([null, ...MembershipState::cases()] as $state) {
            foreach (['join', 'leave', 'approve', 'invite'] as $action) {
                $expected = null;
                if ($action === 'leave' && in_array($state, [MembershipState::Active, MembershipState::Pending, MembershipState::Invited], true)) {
                    $expected = MembershipState::Left;
                } elseif ($action === 'join' && in_array($state, [null, MembershipState::Left], true)) {
                    $expected = match ($policy) {
                        JoinPolicy::Auto => MembershipState::Active,
                        JoinPolicy::Approval => MembershipState::Pending,
                        default => null,
                    };
                } elseif ($action === 'join' && $policy === JoinPolicy::Invite && $state === MembershipState::Invited) {
                    $expected = MembershipState::Active;
                } elseif ($action === 'approve' && $policy === JoinPolicy::Approval && $state === MembershipState::Pending) {
                    $expected = MembershipState::Active;
                } elseif ($action === 'invite' && $policy === JoinPolicy::Invite && in_array($state, [null, MembershipState::Left], true)) {
                    $expected = MembershipState::Invited;
                }
                if ($expected === null) {
                    expect(fn () => MembershipTransitions::next($policy, $state, $action))->toThrow(GroupException::class);
                } else {
                    expect(MembershipTransitions::next($policy, $state, $action))->toBe($expected);
                }
            }
        }
    }
});

it('keeps target recipients separate from self commands and the trusted executor', function (): void {
    $group = new ResourceRef(new PanelId('acme.a'), ResourceKind::Group, Ids::uuid7());
    $subject = new ActorRef('person', 'recipient');
    foreach (['approve', 'invite'] as $action) {
        expect(new MembershipCommand($group, $action, $subject)->subject)->toBe($subject);
        expect(fn () => new MembershipCommand($group, $action))->toThrow(InvalidPayloadException::class);
    }
    foreach (['join', 'leave'] as $action) {
        expect(new MembershipCommand($group, $action)->subject)->toBeNull();
        expect(fn () => new MembershipCommand($group, $action, $subject))->toThrow(InvalidPayloadException::class);
    }
    expect(fn () => new MembershipCommand($group, 'accept'))->toThrow(InvalidPayloadException::class);
});

it('requires bounded nonempty opaque consent generations and only activation transitions', function (): void {
    $group = new ResourceRef(new PanelId('acme.a'), ResourceKind::Group, Ids::uuid7());
    foreach (['', str_repeat('x', 129), "bad\nvalue"] as $generation) {
        expect(fn () => new ConsentDecision(AuthorizationDecision::allow('host.explicit'), $generation))->toThrow(InvalidPayloadException::class);
    }
    expect(fn () => new MembershipActivation($group, new ActorRef('user', '7'), 'invite'))->toThrow(InvalidPayloadException::class);
    expect(new ConsentDecision(AuthorizationDecision::unknown('host.unproven'), 'generation')->decision->isAllowed())->toBeFalse();
});
