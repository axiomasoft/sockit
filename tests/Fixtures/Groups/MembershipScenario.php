<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Groups;

use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Groups\Contracts\MembershipConsent;
use SocKit\Groups\Data\GroupCommand;
use SocKit\Groups\Data\MembershipCommand;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\OperationHandles;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;

final class MembershipScenario
{
    public GroupScenario $f;

    public Consent $consent;

    public MembershipAuthority $authority;

    private int $sequence = 0;

    public function __construct(bool $content = false, bool $install = true, string $contentKind = 'acme.club')
    {
        $this->f = new GroupScenario(install: $install, content: $content, contentKind: $contentKind);
        $connection = $this->f->connection();
        if ($install) {
            $connection->statement('CREATE TABLE panel_a.driver_membership_consent (installation text NOT NULL, subject text NOT NULL, allowed boolean NOT NULL, generation bigint NOT NULL, PRIMARY KEY (installation, subject))');
            foreach (['7', '8'] as $id) {
                $connection->table('panel_a.membership_consent')->insert(['installation' => 'runtime-test', 'subject' => new ActorRef('user', $id)->toWire(), 'allowed' => true, 'generation' => 1]);
            }
        }
        $this->consent = new Consent($connection);
        $this->f->app->instance(MembershipConsent::class, $this->consent);
        $this->authority = new MembershipAuthority($this->f->authority);
        $this->f->app->instance(ActionAuthorizer::class, $this->authority);
    }

    public function actAs(string $id): void
    {
        $actor = RuntimeScenario::actor($id);
        $this->f->fake->actors->actingAs($actor);
        $this->f->fake->surfaces->allow($actor, $this->f->handle->surface);
        $this->f->fake->surfaces->containment($actor, $this->f->handle->surface, AudienceSpec::direct($actor->actor ?? throw new \RuntimeException), AuthorizationDecision::allow('fixture.direct'));
        $this->f->handle = $this->f->app->make(OperationHandles::class)->forSurface($this->f->handle->surface);
    }

    public function create(JoinPolicy $policy): ResourceRef
    {
        $input = new GroupCommand($policy, 'Chess');
        $result = $this->f->groups->create($this->f->handle, $input, $this->command($input, null));
        expect($result->isSuccess())->toBeTrue();

        return $result->resource ?? throw new \RuntimeException('No group');
    }

    public function revision(ResourceRef $group): Revision
    {
        return $this->f->groups->read($this->f->handle, $group)->revision;
    }

    public function policy(ResourceRef $group, JoinPolicy $policy): void
    {
        $input = new GroupCommand($policy, resource: $group);
        expect($this->f->groups->setJoinPolicy($this->f->handle, $input, $this->command($input, $this->revision($group)))->isSuccess())->toBeTrue();
    }

    public function command(GroupCommand|MembershipCommand $input, ?Revision $revision, ?string $key = null): CommandEnvelope
    {
        $action = $input instanceof MembershipCommand ? $input->action : ($input->resource === null ? 'create' : 'policy');

        return new CommandEnvelope(key: new CommandKey($key ?? 'membership.command.'.++$this->sequence), namespace: new CommandNamespace(
            installation: $this->f->handle->actor->installation, panel: $this->f->handle->surface->panel, integration: 'host.client', commandType: 'groups.'.$action),
            digest: $this->f->groups->digest($this->f->handle, $input, $revision), expectedRevision: $revision);
    }

    public function run(ResourceRef $group, string $action, ?ActorRef $subject = null): OperationResult
    {
        $input = new MembershipCommand($group, $action, $subject);

        return $this->execute($input, $this->command($input, $this->revision($group)));
    }

    public function execute(MembershipCommand $input, CommandEnvelope $command): OperationResult
    {
        return match ($input->action) {
            'join' => $this->f->groups->join($this->f->handle, $input, $command),
            'leave' => $this->f->groups->leave($this->f->handle, $input, $command),
            'approve' => $this->f->groups->approve($this->f->handle, $input, $command),
            'invite' => $this->f->groups->invite($this->f->handle, $input, $command),
            default => throw new \RuntimeException('Invalid fixture action'),
        };
    }

    public function state(string $id = '7'): ?string
    {
        $value = $this->f->connection()->table('panel_a.a_group_members')->where('actor_ref', new ActorRef('user', $id)->toWire())->value('state');

        return is_string($value) ? $value : null;
    }

    public function cleanup(): void
    {
        $this->f->cleanup();
    }
}
