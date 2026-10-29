<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Audience\Exceptions\AuthorizationDeniedException;
use SocKit\Operations\OperationHandles;
use SocKit\Posts\Contracts\ContentSafety;
use SocKit\Posts\Contracts\MediaResolver;
use SocKit\Posts\Data\MediaReference;
use SocKit\Posts\Data\PostCommand;
use SocKit\Posts\Enums\PublicationState;
use SocKit\Posts\Enums\SafetyDecision;
use SocKit\Posts\Internal\PostException;
use SocKit\Posts\Internal\PostPolicies;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Social\MediaAdapter;
use SocKit\Tests\Fixtures\Social\SafetyAdapter;

it('executes safety and media adapters outside transactions and swaps owners without copying files', function (): void {
    $app = RuntimeScenario::app();
    RuntimeScenario::trust($app);
    $handle = $app->make(OperationHandles::class)->forSurface(RuntimeScenario::surface());
    $connection = new Connection(static fn (): PDO => throw new RuntimeException('Policy test must never open SQL'));
    $app->instance(ConnectionResolverInterface::class, new ConnectionResolver(['owner_a' => $connection]));
    $policies = new PostPolicies($app);
    $ref = new MediaReference(owner: 'host.media', id: '0007:asset/雪');
    $post = new PostCommand(surface: $handle->surface, body: '', publish: true, media: [$ref]);
    $safety = new SafetyAdapter($connection);
    $app->instance(ContentSafety::class, $safety);
    foreach ([SafetyDecision::Allow, SafetyDecision::Hold, SafetyDecision::Reject] as $decision) {
        $safety->decision = $decision;
        foreach (['host.media', 'alternate.media'] as $owner) {
            $media = new MediaAdapter($connection);
            $app->instance(MediaResolver::class, $media);
            $candidate = new PostCommand(surface: $handle->surface, body: $post->body, publish: true, media: [new MediaReference(owner: $owner, id: $ref->id)]);
            expect($policies->assess($handle, $candidate))->toBe(match ($decision) {
                SafetyDecision::Allow => PublicationState::Published, SafetyDecision::Hold => PublicationState::Held, SafetyDecision::Reject => PublicationState::Rejected,
            });
            expect($media->levels)->toBe([0])->and($media->references)->toEqual($candidate->media);
        }
    }
    expect($safety->levels)->toBe(array_fill(0, 6, 0));
    $safety->decision = SafetyDecision::Unknown;
    expect(fn () => $policies->assess($handle, $post))->toThrow(PostException::class);
    $safety->fail = true;
    expect(fn () => $policies->assess($handle, $post))->toThrow(RuntimeException::class);
    $app->flush();
});

it('denies configured unavailable unknown media and missing association providers without fallback', function (string $mode): void {
    $app = RuntimeScenario::app();
    RuntimeScenario::trust($app);
    $handle = $app->make(OperationHandles::class)->forSurface(RuntimeScenario::surface());
    $connection = new Connection(static fn (): PDO => throw new RuntimeException('Policy test must never open SQL'));
    $app->instance(ConnectionResolverInterface::class, new ConnectionResolver(['owner_a' => $connection]));
    $policies = new PostPolicies($app);
    $post = new PostCommand(surface: $handle->surface, body: 'Post', publish: true, media: [new MediaReference('host.media', '007')]);
    if ($mode !== 'missing') {
        $app->instance(MediaResolver::class, new MediaAdapter($connection, $mode));
    }
    expect(fn () => $policies->assess($handle, $post))->toThrow(match ($mode) {
        'missing' => PostException::class, 'error' => RuntimeException::class, default => AuthorizationDeniedException::class,
    });
    $app->flush();
})->with(['missing', 'deny', 'unknown', 'error']);

it('uses explicit no prescreen and pending defaults and validates bodies and lossless refs before adapters', function (): void {
    $app = RuntimeScenario::app();
    RuntimeScenario::trust($app);
    $handle = $app->make(OperationHandles::class)->forSurface(RuntimeScenario::surface());
    $app->instance(ConnectionResolverInterface::class, new ConnectionResolver(['owner_a' => new Connection(static fn (): PDO => throw new RuntimeException('Policy test must never open SQL'))]));
    expect(new PostPolicies($app)->assess($handle, new PostCommand(surface: $handle->surface, body: 'Pending')))->toBe(PublicationState::Pending);
    expect(fn () => new PostCommand(surface: $handle->surface, body: ''))->toThrow(InvalidPayloadException::class);
    $ref = new MediaReference('host.media', '0007:opaque');
    expect(fn () => new PostCommand(surface: $handle->surface, body: 'Post', media: [$ref, $ref]))->toThrow(InvalidPayloadException::class);
    expect(fn () => new PostCommand(surface: $handle->surface, body: 'Post', media: ['spoof']))->toThrow(InvalidPayloadException::class);
    $app->flush();
});
