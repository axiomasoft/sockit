<?php

declare(strict_types=1);

use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Groups\Contracts\MembershipAdministration;
use SocKit\Groups\Data\GroupCommand;
use SocKit\Groups\Data\MembershipCommand;
use SocKit\Groups\Enums\GroupVisibility;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Groups\Enums\MembershipRole;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\Revision;
use SocKit\Tests\Fixtures\Groups\MembershipScenario;

it('fences host administration, binds consent to the recipient role and guards private group reads', function (GroupVisibility $visibility): void {
    $s = new MembershipScenario;
    try {
        $input = new GroupCommand(JoinPolicy::Auto, 'Chess', visibility: $visibility);
        $created = $s->f->groups->create($s->f->handle, $input, $s->command($input, null));
        expect($created->isSuccess())->toBeTrue();
        $group = $created->resource ?? throw new RuntimeException;
        $recipient = new ActorRef('user', '8');
        $add = new MembershipCommand($group, 'add_member', $recipient, MembershipRole::Moderator);
        $command = $s->command($add, new Revision(1));
        expect($s->execute($add, $command)->isSuccess())->toBeFalse()
            ->and($s->state('8'))->toBeNull();
        $policy = new class implements MembershipAdministration
        {
            public bool $allow = false;

            public function capture(OperationHandle $handle, MembershipCommand $command): string
            {
                return 'authorization-1';
            }

            public function recheck(OperationHandle $handle, MembershipCommand $command, string $generation): AuthorizationDecision
            {
                return $this->allow && $handle->actor->actor?->id === '7' && $command->subject?->id === '8' && $generation === 'authorization-1'
                    ? AuthorizationDecision::allow('host.membership_admin') : AuthorizationDecision::deny('host.membership_admin');
            }
        };
        $s->f->app->instance(MembershipAdministration::class, $policy);
        expect($s->execute($add, $s->command($add, new Revision(1)))->isSuccess())->toBeFalse()
            ->and($s->state('8'))->toBeNull();
        $policy->allow = true;
        $s->consent->mode = 'unknown';
        expect($s->execute($add, $s->command($add, new Revision(1)))->isSuccess())->toBeFalse();
        $s->consent->mode = 'normal';
        $added = $s->execute($add, $s->command($add, new Revision(1)));
        expect($added->isSuccess())->toBeTrue()->and($s->state('8'))->toBe('active');
        if ($visibility !== GroupVisibility::Public) {
            expect(fn () => $s->f->groups->read($s->f->handle, $group))->toThrow(Exception::class);
        }
        $s->actAs('8');
        $view = $s->f->groups->read($s->f->handle, $group);
        expect($view->visibility)->toBe($visibility)->and($view->viewerRole)->toBe(MembershipRole::Moderator);
        $s->actAs('7');
        $remove = new MembershipCommand($group, 'remove_member', $recipient);
        expect($s->execute($remove, $s->command($remove, new Revision(1)))->isSuccess())->toBeFalse()
            ->and($s->state('8'))->toBe('active');
        $removed = $s->execute($remove, $s->command($remove, new Revision(2)));
        expect($removed->isSuccess())->toBeTrue()->and($s->state('8'))->toBe('left');
        $s->actAs('8');
        if ($visibility !== GroupVisibility::Public) {
            expect(fn () => $s->f->groups->read($s->f->handle, $group))->toThrow(Exception::class);
        } else {
            expect($s->f->groups->read($s->f->handle, $group)->viewerRole)->toBeNull();
        }
    } finally {
        $s->cleanup();
    }
})->with(GroupVisibility::cases());

it('rejects group names and secret creation outside configured panel limits', function (): void {
    $s = new MembershipScenario(allowSecret: false, maxTitle: 4);
    try {
        foreach ([new GroupCommand(JoinPolicy::Auto, 'Chess'), new GroupCommand(JoinPolicy::Auto, 'Club', visibility: GroupVisibility::Secret)] as $input) {
            expect($s->f->groups->create($s->f->handle, $input, $s->command($input, null))->isSuccess())->toBeFalse();
        }
        $valid = new GroupCommand(JoinPolicy::Auto, 'Club');
        expect($s->f->groups->create($s->f->handle, $valid, $s->command($valid, null))->isSuccess())->toBeTrue();
    } finally {
        $s->cleanup();
    }
});
