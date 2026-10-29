<?php

declare(strict_types=1);

use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Data\GroupRef;
use SocKit\Audience\Exceptions\AuthorizationDeniedException;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\OperationHandles;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\SurfaceInstances;
use SocKit\Tests\Fixtures\Groups\ContentScenario;
use SocKit\Tests\Fixtures\Groups\ContentWait;
use SocKit\Tests\Fixtures\Groups\ManagementWait;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;

it('composes public group content posts and reactions with an early group fence and guarded replay lookup', function (): void {
    $s = new ContentScenario;
    try {
        $post = $s->post();
        $command = $s->command($post);
        $s->f->db->queries = [];
        $created = $s->posts->create($s->handle, $post, $command);
        expect($created->isPublished())->toBeTrue();
        $resource = $created->operation->resource ?? throw new RuntimeException(json_encode($created, JSON_THROW_ON_ERROR));
        expect($s->posts->read($s->handle, $resource)->body)->toBe('Group post')
            ->and($s->posts->create($s->handle, $post, $command)->operation->receiptId)->toBe($created->operation->receiptId)
            ->and($s->posts->lookup($s->handle, $command->namespace, $command->key)?->isPublished())->toBeTrue();
        $s->f->db->queries = [];
        $reaction = $s->reaction($resource);
        expect($s->reactions->add($s->handle, $s->reactionInput($resource), $reaction)->isSuccess())->toBeTrue();
        $locks = $s->locks();
        $groupIndex = $postIndex = null;
        foreach ($locks as $i => $sql) {
            if (str_contains($sql, 'a_groups')) {
                $groupIndex ??= $i;
            }
            if (str_contains($sql, 'a_posts')) {
                $postIndex ??= $i;
            }
        }
        expect($groupIndex)->toBeInt()->and($postIndex)->toBeInt()->and($groupIndex < $postIndex)->toBeTrue();
        expect($s->membership->run($s->group, 'leave')->isSuccess())->toBeTrue();
        expect($s->posts->lookup($s->handle, $command->namespace, $command->key)?->operation->resource)->toBeNull()
            ->and($s->posts->create($s->handle, $post, $command)->operation->isSuccess())->toBeFalse()
            ->and($s->reactions->lookup($s->handle, $reaction->namespace, $reaction->key)?->isSuccess())->toBeFalse();
        expect(fn () => $s->posts->read($s->handle, $resource))->toThrow(Exception::class);
        expect(array_unique($s->f->target->levels))->toBe([0])->and(array_unique($s->f->authority->levels))->toBe([0]);
    } finally {
        $s->cleanup();
    }
});

it('keeps nonmember management admission separate and rejects recursive or unrelated group targets', function (): void {
    $s = new ContentScenario(active: false);
    try {
        $handles = $s->f->app->make(OperationHandles::class);
        $managementTarget = new TargetRef(new HostEntityRef('group', $s->group->id), new Incarnation(1));
        expect($handles->resolveTarget($s->f->handle, $managementTarget)->read->canRead())->toBeTrue();
        expect(fn () => $handles->resolveTarget($s->handle, new TargetRef($s->handle->surface->host, new Incarnation(1))))->toThrow(Exception::class);
        expect($s->membership->run($s->group, 'join')->isSuccess())->toBeTrue();
        $target = new TargetRef(new HostEntityRef('sockit.group', $s->group->id), new Incarnation(1));
        expect(fn () => $handles->resolveTarget($s->f->handle, $target))->toThrow(Exception::class);
        $bad = new TargetRef(new HostEntityRef('sockit.group', '019a1ba0-0000-7000-8000-000000000099'), new Incarnation(1));
        expect(fn () => $handles->resolveTarget($s->handle, $bad))->toThrow(Exception::class);
        $s->f->fake->surfaces->deny($s->handle->actor, $s->f->handle->surface);
        expect(fn () => $handles->resolveTarget($s->f->handle, $managementTarget))->toThrow(Exception::class);
    } finally {
        $s->cleanup();
    }
});

it('rejects an unrelated proposed group even if the host claims containment and membership exists in both', function (): void {
    $s = new ContentScenario;
    try {
        $other = $s->anotherGroup();
        $spec = AudienceSpec::group(new GroupRef($other->id));
        $s->f->fake->surfaces->containment($s->handle->actor, $s->handle->surface, $spec, AuthorizationDecision::allow('fixture.wrong_group'));
        $post = $s->post($spec);
        expect($s->posts->create($s->handle, $post, $s->command($post))->operation->isSuccess())->toBeFalse()
            ->and($s->f->connection()->table('panel_a.a_posts')->count())->toBe(0);
        $s->f->target->unproven = true;
        $post = $s->post();
        expect($s->posts->create($s->handle, $post, $s->command($post, 'unproven.target.0001'))->operation->isSuccess())->toBeFalse()
            ->and($s->f->connection()->table('panel_a.a_posts')->count())->toBe(0);
    } finally {
        $s->cleanup();
    }
});

it('serializes leave against publication and post reactions at the group before content locks', function (string $mode, bool $leaveFirst): void {
    $s = new ContentScenario;
    try {
        $input = $s->post();
        $created = $s->posts->create($s->handle, $input, $s->command($input));
        expect($created->isPublished())->toBeTrue();
        $post = $created->operation->resource ?? throw new RuntimeException('No initial post');
        $race = ContentWait::race($s, $post, $mode, $leaveFirst);
        expect($race['contender']->isSuccess())->toBe(! $leaveFirst)
            ->and($s->membership->state())->toBe('left')
            ->and($s->f->connection()->table('panel_a.a_posts')->count())->toBe($mode === 'post' && ! $leaveFirst ? 2 : 1)
            ->and($s->f->connection()->table('panel_a.a_reactions')->count())->toBe($mode === 'reaction' && ! $leaveFirst ? 1 : 0)
            ->and($s->f->connection()->transactionLevel())->toBe(0);
    } finally {
        $s->cleanup();
    }
})->with([['post', true], ['reaction', true], ['post', false], ['reaction', false]]);

it('F1 rejects changes to the management surface committed after outside guards before the local fence', function (string $mode, string $change): void {
    $s = new ContentScenario;
    try {
        $input = $s->post();
        $created = $s->posts->create($s->handle, $input, $s->command($input));
        expect($created->isPublished())->toBeTrue();
        $post = $created->operation->resource ?? throw new RuntimeException('No initial post');
        $before = $s->contentEffects();
        $result = ManagementWait::afterGuards($s, $post, $mode, $change);
        expect($result->isSuccess())->toBeFalse()->and($s->contentEffects())->toBe($before)
            ->and($s->f->connection()->transactionLevel())->toBe(0);
    } finally {
        $s->cleanup();
    }
})->with(['post', 'reaction'])->with(['archive', 'delete', 'revision', 'privacy_generation', 'source_generation', 'leave']);

it('F1 holds the management lifecycle fence until content commit for both surface identity orders', function (string $mode, string $action, string $kind): void {
    $s = new ContentScenario(kind: $kind);
    try {
        $input = $s->post();
        $created = $s->posts->create($s->handle, $input, $s->command($input));
        expect($created->isPublished())->toBeTrue();
        $post = $created->operation->resource ?? throw new RuntimeException('No initial post');
        $race = ManagementWait::duringContent($s, $post, $mode, $action);
        expect($race['management']->isSuccess())->toBeTrue()
            ->and($s->f->connection()->table('panel_a.a_posts')->count())->toBe($mode === 'post' ? 2 : 1)
            ->and($s->f->connection()->table('panel_a.a_reactions')->count())->toBe($mode === 'reaction' ? 1 : 0)
            ->and($s->f->connection()->table('panel_a.a_surface_instances')->where('host_key', '42')->value('lifecycle'))->toBe($action === 'archive' ? 'archived' : 'deleted')
            ->and($s->f->connection()->transactionLevel())->toBe(0);
    } finally {
        $s->cleanup();
    }
})->with(['post', 'reaction'])->with(['archive', 'delete'])->with(['acme.club', 'acme.zzclub']);

it('F1 proves the local surface binding and transaction without consulting ambient actors', function (): void {
    $s = new ContentScenario;
    try {
        $surfaces = $s->f->app->make(SurfaceInstances::class);
        $parent = $s->f->handle;
        $sample = $surfaces->get($parent);
        $neighbor = $s->f->app->make(OperationHandles::class)->forSurface(RuntimeScenario::surface('acme.b'));
        $otherActor = new OperationHandle($parent->surface,
            RuntimeScenario::actor('8'), $parent->panel, $parent->definition);
        expect($surfaces->recheck($s->handle, $parent, $sample)->isAllowed())->toBeFalse();
        // Any ambient current() call would fail the trusted installation check. Local
        // fencing must use the already captured handles, without contacting that resolver.
        $s->f->fake->actors->actingAs(RuntimeScenario::actor(installation: 'unresolved-ambient-installation'));
        $s->f->connection()->transaction(function () use ($s, $surfaces, $parent, $sample, $neighbor, $otherActor): void {
            expect($surfaces->recheck($s->handle, $parent, $sample)->isAllowed())->toBeTrue()
                ->and($surfaces->recheck($s->handle, $neighbor, $sample)->isAllowed())->toBeFalse()
                ->and($surfaces->recheck($s->handle, $otherActor, $sample)->isAllowed())->toBeFalse();
        });
        expect($s->f->connection()->transactionLevel())->toBe(0);
    } finally {
        $s->cleanup();
    }
});

it('F1 rejects reverse-order surface contention with NOWAIT and rolls back before any retry', function (): void {
    $s = new ContentScenario(kind: 'acme.zzclub');
    try {
        $surfaces = $s->f->app->make(SurfaceInstances::class);
        $parent = $s->f->handle;
        $sample = $surfaces->get($parent);
        $pdo = $s->f->db->owner;
        $pdo->beginTransaction();
        $pdo->query("SELECT id FROM panel_a.driver_a_surface_instances WHERE kind='acme.runtime' AND host_key='42' FOR UPDATE");
        $sawNowait = false;
        $s->f->connection()->beforeExecuting(static function (string $query) use (&$sawNowait): void {
            if (str_contains($query, 'a_surface_instances') && str_contains($query, 'for update nowait')) {
                $sawNowait = true;
            }
        });
        $s->f->connection()->statement("SET statement_timeout='10s'");
        $started = microtime(true);
        expect(fn () => $s->f->connection()->transaction(fn () => $surfaces->recheck($s->handle, $parent, $sample)->requireAllowed()))
            ->toThrow(AuthorizationDeniedException::class);
        expect($sawNowait)->toBeTrue()->and(microtime(true) - $started)->toBeLessThan(5.0)
            ->and($s->f->connection()->transactionLevel())->toBe(0);
        $pdo->rollBack();
        expect($s->f->connection()->transaction(fn (): bool => $surfaces->recheck($s->handle, $parent, $sample)->isAllowed()))->toBeTrue();
    } finally {
        if ($s->f->db->owner->inTransaction()) {
            $s->f->db->owner->rollBack();
        }
        $s->cleanup();
    }
});
