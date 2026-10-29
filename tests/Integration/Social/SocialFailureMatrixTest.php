<?php

declare(strict_types=1);

use SocKit\Audience\Data\AudienceSpec;
use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Posts\Data\FeedQuery;
use SocKit\Support\Data\Revision;
use SocKit\Tests\Fixtures\PanelConsumer\CommunityConsumer;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Social\PostLifecycle;
use SocKit\Tests\Fixtures\Social\PostReactionScenario;

it('rechecks the composed social path across recipient removal, hiding, restoration and deletion', function (): void {
    $f = new PostReactionScenario(feed: ['feed_keys' => ['current' => base64_encode(random_bytes(32))], 'feed_key' => 'current']);
    $s = $f->s;
    try {
        $ref = $f->create();
        $target = $f->target($ref);
        $reaction = $f->command($target);
        expect($f->add($target, $reaction)->isSuccess())->toBeTrue();
        expect($f->reactions->summary($s->handle, $target)->counts)->toBe(['like' => 1]);
        $viewer = RuntimeScenario::actor('8');
        $f->actor($viewer);
        expect($s->posts->feed($s->handle, new FeedQuery(10))->items)->toHaveCount(1);
        $f->actor(RuntimeScenario::actor());
        expect($f->life->execute($ref, 'audience', 1, AudienceSpec::direct($s->handle->actor->actor ?? throw new RuntimeException('owner')))->isPublished())->toBeTrue();
        $f->actor($viewer);
        expect($s->posts->feed($s->handle, new FeedQuery(10))->items)->toBeEmpty();
        expect(fn () => $s->posts->read($s->handle, $ref))->toThrow(SocKitException::class);
        expect(fn () => $s->posts->revision($s->handle, $ref, new Revision(1)))->toThrow(SocKitException::class);
        expect($f->reactions->summary($s->handle, $target)->counts)->toBeNull();
        $f->actor(RuntimeScenario::actor());
        expect($f->life->execute($ref, 'hide', 2)->operation->isSuccess())->toBeTrue();
        expect($s->posts->feed($s->handle, new FeedQuery(10))->items)->toBeEmpty();
        PostLifecycle::denied($f->add($target, $reaction));
        expect($f->life->execute($ref, 'restore', 3)->isPublished())->toBeTrue();
        expect($f->reactions->summary($s->handle, $target)->counts)->toBe(['like' => 1]);
        expect($f->life->execute($ref, 'delete', 4)->operation->isSuccess())->toBeTrue();
        expect($s->posts->feed($s->handle, new FeedQuery(10))->items)->toBeEmpty();
        PostLifecycle::denied($f->add($target, $reaction));
        expect($f->reactions->summary($s->handle, $target)->counts)->toBeNull();
        expect($f->counts()['active'])->toBe(1);
    } finally {
        $s->cleanup();
    }
});

it('runs the lifecycle and privacy failure matrix in the generated standalone consumer', function (): void {
    $consumer = new CommunityConsumer;
    try {
        $consumer->run();
    } finally {
        $consumer->cleanup();
    }
});
