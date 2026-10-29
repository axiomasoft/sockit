<?php

declare(strict_types=1);

use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Data\GroupRef;
use SocKit\Operations\OperationHandles;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Tests\Fixtures\Groups\ContentScenario;
use SocKit\Tests\Fixtures\Groups\ContentWait;

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
