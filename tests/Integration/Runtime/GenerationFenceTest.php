<?php

declare(strict_types=1);

use SocKit\Errors\Enums\ProblemCode;
use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\OperationHandles;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\SurfaceInstances;
use SocKit\Tests\Fixtures\Runtime\EnvelopeScenario;
use SocKit\Tests\Integration\Support\PgTarget;

it('requires provable local fences and rolls back when sampled authority changes before owner lock', function (string $case): void {
    $s = new EnvelopeScenario;
    try {
        $s->target->changeAfterResolve = $case === 'changed';
        $s->target->changeSurfaceAfterResolve = $case === 'surface_changed';
        $s->target->unproven = $case === 'unproven';
        expect($s->execute()->problem)->toBe(ProblemCode::AuthorizationUnknown)->and($s->target->fences)->toBe($case === 'surface_changed' ? 0 : 1)
            ->and(array_unique($s->target->levels))->toBe([0])->and($s->counts())->toBe(['effects' => 0, 'revision' => 1, 'receipts' => 0, 'outbox' => 0]);
    } finally {
        $s->cleanup();
    }
})->with(['changed', 'unproven', 'surface_changed']);

it('routes public lifecycle through the same receipt CAS tombstone and outbox envelope', function (): void {
    $s = new EnvelopeScenario;
    try {
        $api = $s->base->app->make(SurfaceInstances::class);
        expect($api->get($s->handle)->revision->value)->toBe(1);
        $command = function (string $action, int $expected) use ($s, $api): CommandEnvelope {
            return new CommandEnvelope(key: new CommandKey('req.2026-10-28.'.$action), namespace: new CommandNamespace(installation: $s->handle->actor->installation, panel: $s->handle->surface->panel, integration: 'acme.client', commandType: 'sockit.surfaces.'.$action),
                digest: $api->digest(handle: $s->handle, action: $action, expectedRevision: new Revision($expected)), expectedRevision: new Revision($expected));
        };
        $archive = $api->archive(handle: $s->handle, command: $command('archive', 1));
        expect($archive->isSuccess())->toBeTrue()->and($api->get($s->handle)->lifecycle->value)->toBe('archived');
        $resume = $api->resume(handle: $s->handle, command: $command('resume', 2));
        expect($resume->isSuccess())->toBeTrue()->and($api->get($s->handle)->revision->value)->toBe(3);
        $delete = $api->delete(handle: $s->handle, command: $command('delete', 3));
        expect($delete->isSuccess())->toBeTrue();
        $pdo = $s->base->db->owner;
        expect((int) PgTarget::query($pdo, 'SELECT count(*) FROM panel_a.driver_a_tombstones')->fetchColumn())->toBe(1)
            ->and((int) PgTarget::query($pdo, 'SELECT count(*) FROM panel_a.driver_a_outbox')->fetchColumn())->toBe(3);
        expect(fn () => $api->get($s->handle))->toThrow(SocKitException::class);
    } finally {
        $s->cleanup();
    }
});

it('creates only a missing surface with explicit incarnation and cannot resurrect a tombstone', function (): void {
    $s = new EnvelopeScenario;
    try {
        $old = $s->handle->surface;
        $ref = new SurfaceRef(panel: $old->panel, kind: $old->kind, host: new HostEntityRef(type: 'article', value: 'new'), incarnation: new Incarnation(1));
        $s->base->fake->surfaces->allow(viewer: $s->handle->actor, surface: $ref);
        $handle = $s->base->app->make(OperationHandles::class)->forSurface($ref);
        $api = $s->base->app->make(SurfaceInstances::class);
        $command = new CommandEnvelope(key: new CommandKey('req.2026-10-28.create'), namespace: new CommandNamespace(installation: $handle->actor->installation, panel: $ref->panel, integration: 'acme.client', commandType: 'sockit.surfaces.create'), digest: $api->digest(handle: $handle, action: 'create'));
        expect($api->create(handle: $handle, command: $command)->isSuccess())->toBeTrue()->and($api->create(handle: $handle, command: $command)->isSuccess())->toBeTrue();
        $delete = new CommandEnvelope(key: new CommandKey('req.2026-10-28.delete'), namespace: new CommandNamespace(installation: $handle->actor->installation, panel: $ref->panel, integration: 'acme.client', commandType: 'sockit.surfaces.delete'), digest: $api->digest(handle: $handle, action: 'delete', expectedRevision: new Revision(1)), expectedRevision: new Revision(1));
        expect($api->delete(handle: $handle, command: $delete)->isSuccess())->toBeTrue()->and($api->create(handle: $handle, command: $command)->isSuccess())->toBeFalse();
        $pdo = $s->base->db->owner;
        expect((int) PgTarget::query($pdo, "SELECT count(*) FROM panel_a.driver_a_surface_instances WHERE host_key='new' AND lifecycle='deleted'")->fetchColumn())->toBe(1)
            ->and((int) PgTarget::query($pdo, "SELECT count(*) FROM panel_a.driver_a_tombstones WHERE host_key='new'")->fetchColumn())->toBe(1);
    } finally {
        $s->cleanup();
    }
});

it('fails closed before mutation without a provable local composite authority fence', function (string $case): void {
    $s = new EnvelopeScenario;
    try {
        if ($case === 'missing') {
            $s->base->app->offsetUnset(MutationAuthorityFence::class);
        } else {
            $s->fence->unproven = true;
        }
        $result = $s->execute();
        expect($result->problem)->toBe($case === 'missing' ? ProblemCode::ProviderUnavailable : ProblemCode::AuthorizationUnknown)
            ->and($s->mutation->writes)->toBe(0)->and($s->counts())->toBe(['effects' => 0, 'revision' => 1, 'receipts' => 0, 'outbox' => 0]);
    } finally {
        $s->cleanup();
    }
})->with(['missing', 'unproven']);
