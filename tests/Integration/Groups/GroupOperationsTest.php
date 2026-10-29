<?php

declare(strict_types=1);

use SocKit\Audience\Exceptions\AuthorizationDeniedException;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Groups\Data\GroupCommand;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Tests\Fixtures\Groups\GroupScenario;

it('guards creation, reads, policy CAS and receipt disclosure on an owned PostgreSQL target', function (): void {
    $f = new GroupScenario;
    try {
        $command = function (GroupCommand $input, string $key, ?Revision $revision = null) use ($f): CommandEnvelope {
            return new CommandEnvelope(key: new CommandKey($key), namespace: new CommandNamespace(installation: $f->handle->actor->installation,
                panel: $f->handle->surface->panel, integration: 'host.client', commandType: $input->resource === null ? 'groups.create' : 'groups.policy'),
                digest: $f->groups->digest($f->handle, $input, $revision), expectedRevision: $revision);
        };
        $create = new GroupCommand(JoinPolicy::Approval, 'Chess');
        $c = $command($create, 'group.create.0001');
        $result = $f->groups->create($f->handle, $create, $c);
        expect($result->isSuccess())->toBeTrue();
        $resource = $result->resource;
        expect($resource)->not->toBeNull();
        assert($resource !== null);
        expect($f->groups->read($f->handle, $resource)->name)->toBe('Chess')
            ->and($f->connection()->table('panel_a.a_group_members')->count())->toBe(0)
            ->and($f->groups->create($f->handle, $create, $c)->resource?->id)->toBe($resource->id)
            ->and($f->groups->lookup($f->handle, $c->namespace, $c->key)?->resource?->id)->toBe($resource->id);
        $changedCreate = new GroupCommand(JoinPolicy::Invite, 'Other');
        $conflict = $command($changedCreate, 'group.create.0001');
        expect($f->groups->create($f->handle, $changedCreate, $conflict)->problem)->toBe(ProblemCode::IdempotencyConflict);
        $foreign = new ResourceRef(new PanelId('other.panel'), ResourceKind::Group, $resource->id);
        expect(fn () => $f->groups->read($f->handle, $foreign))->toThrow(InvalidPayloadException::class);
        $policy = new GroupCommand(JoinPolicy::Closed, resource: $resource);
        $pc = $command($policy, 'group.policy.0001', new Revision(1));
        expect($f->groups->setJoinPolicy($f->handle, $policy, $pc)->revision?->value)->toBe(2)
            ->and($f->groups->read($f->handle, $resource)->joinPolicy)->toBe(JoinPolicy::Closed)
            ->and($f->groups->setJoinPolicy($f->handle, $policy, $pc)->revision?->value)->toBe(2);
        $same = $command($policy, 'group.policy.0002', new Revision(2));
        expect($f->groups->setJoinPolicy($f->handle, $policy, $same)->isSuccess())->toBeFalse();
        $stale = $command(new GroupCommand(JoinPolicy::Invite, resource: $resource), 'group.policy.0003', new Revision(1));
        expect($f->groups->setJoinPolicy($f->handle, new GroupCommand(JoinPolicy::Invite, resource: $resource), $stale)->problem)->toBe(ProblemCode::RevisionConflict);
        expect($f->connection()->table('panel_a.a_groups')->value('revision'))->toBe(2)
            ->and($f->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' LIKE 'groups.%'")->count())->toBe(2);
        $f->connection()->table('panel_a.envelope_targets')->update(['visible' => false]);
        expect($f->groups->lookup($f->handle, $c->namespace, $c->key)?->isSuccess())->toBeFalse();
        $f->connection()->table('panel_a.envelope_targets')->update(['visible' => true]);
        $f->connection()->table('panel_a.a_surface_instances')->update(['lifecycle' => 'archived']);
        $archived = $command(new GroupCommand(JoinPolicy::Invite, resource: $resource), 'group.policy.0004', new Revision(2));
        expect($f->groups->setJoinPolicy($f->handle, new GroupCommand(JoinPolicy::Invite, resource: $resource), $archived)->isSuccess())->toBeFalse();
        $f->connection()->table('panel_a.a_surface_instances')->update(['lifecycle' => 'live']);
        $f->connection()->table('panel_a.envelope_authority')->update(['allowed' => false]);
        expect($f->groups->lookup($f->handle, $c->namespace, $c->key)?->isSuccess())->toBeFalse();
        expect(fn () => $f->groups->read($f->handle, $resource))->toThrow(AuthorizationDeniedException::class);
    } finally {
        $f->cleanup();
    }
});
