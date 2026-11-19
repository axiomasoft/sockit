<?php

declare(strict_types=1);

use SocKit\Audience\Data\AudienceSpec;
use SocKit\Posts\Data\FeedQuery;
use SocKit\Support\Data\Revision;
use SocKit\Tests\Fixtures\Groups\ContentScenario;
use SocKit\Tests\Fixtures\Groups\ContentWait;
use SocKit\Tests\Fixtures\Groups\InstalledConsumer;

it('revokes post and reaction access after the author leaves', function (): void {
    $s = new ContentScenario;
    try {
        $post = $s->post();
        $command = $s->command($post);
        $created = $s->posts->create($s->handle, $post, $command);
        expect($created->isPublished())->toBeTrue();
        $resource = $created->operation->resource ?? throw new RuntimeException('Missing post');
        $reaction = $s->reaction($resource);
        expect($s->reactions->add($s->handle, $s->reactionInput($resource), $reaction)->isSuccess())->toBeTrue();
        expect($s->posts->feed($s->handle, new FeedQuery)->items)->toHaveCount(1)
            ->and($s->posts->revision($s->handle, $resource, new Revision(1))->body)->toBe('Group post')
            ->and($s->reactions->summary($s->handle, $s->reactionInput($resource)->target)->counts)->toBe(['like' => 1]);
        $sample = $s->gate->read($s->handle->actor, $s->subject());
        expect($sample->isCurrent())->toBeTrue();
        expect($s->membership->run($s->group, 'leave')->isSuccess())->toBeTrue();
        expect($sample->isCurrent())->toBeFalse();
        expect(fn () => $s->posts->read($s->handle, $resource))->toThrow(Exception::class);
        expect(fn () => $s->posts->revision($s->handle, $resource, new Revision(1)))->toThrow(Exception::class);
        expect($s->posts->feed($s->handle, new FeedQuery)->items)->toBeEmpty()
            ->and($s->reactions->summary($s->handle, $s->reactionInput($resource)->target)->counts)->toBeNull();
        expect($s->posts->lookup($s->handle, $command->namespace, $command->key)?->operation->resource)->toBeNull()
            ->and($s->posts->create($s->handle, $post, $command)->operation->isSuccess())->toBeFalse()
            ->and($s->reactions->lookup($s->handle, $reaction->namespace, $reaction->key)?->isSuccess())->toBeFalse();
    } finally {
        $s->cleanup();
    }
});

it('serializes leave against group publication and reaction', function (string $mode, bool $leaveFirst): void {
    $s = new ContentScenario;
    try {
        $post = $s->post();
        $created = $s->posts->create($s->handle, $post, $s->command($post));
        expect($created->isPublished())->toBeTrue();
        $resource = $created->operation->resource ?? throw new RuntimeException('Missing post');
        $race = ContentWait::race($s, $resource, $mode, $leaveFirst);
        expect($race['contender']->isSuccess())->toBe(! $leaveFirst)
            ->and($s->membership->state())->toBe('left')
            ->and($s->f->connection()->table('panel_a.a_posts')->count())->toBe($mode === 'post' && ! $leaveFirst ? 2 : 1)
            ->and($s->f->connection()->table('panel_a.a_reactions')->count())->toBe($mode === 'reaction' && ! $leaveFirst ? 1 : 0);
    } finally {
        $s->cleanup();
    }
})->with([['post', true], ['reaction', true], ['post', false], ['reaction', false]]);

it('enforces group audience on installed generated posts and reactions', function (): void {
    $consumer = new InstalledConsumer;
    try {
        $consumer->run();
    } finally {
        $consumer->cleanup();
    }
});

it('invalidates a composite exclusion after a previously absent member joins', function (): void {
    $s = new ContentScenario(active: false);
    try {
        $spec = AudienceSpec::exclude(AudienceSpec::platform(), $s->audience());
        $subject = $s->subject(spec: $spec);
        $sample = $s->gate->read($s->handle->actor, $subject);
        expect(AudienceSpec::fromWire($spec->toWire())->toWire())->toBe($spec->toWire())
            ->and($sample->isCurrent())->toBeTrue();
        expect($s->membership->run($s->group, 'join')->isSuccess())->toBeTrue();
        expect($sample->isCurrent())->toBeFalse()
            ->and($s->gate->read($s->handle->actor, $subject)->canRead())->toBeFalse();
    } finally {
        $s->cleanup();
    }
});
