<?php

declare(strict_types=1);

use SocKit\Attributes\Api;
use SocKit\Attributes\Spi;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Groups\Contracts\MembershipConsent;
use SocKit\Groups\Data\ConsentDecision;
use SocKit\Groups\Data\MembershipActivation;
use SocKit\Groups\Data\MembershipCommand;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Groups\Enums\MembershipState;
use SocKit\Groups\GroupAudienceResolver;
use SocKit\Groups\Internal\GroupTarget;
use SocKit\SocKitServiceProvider;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Contracts\TargetWriteFence;
use SocKit\Tests\Arch\Support\Gates;
use SocKit\Tests\Arch\Support\SourceScanner;
use SocKit\Tests\Fixtures\Groups\Selection;

it('keeps Groups on public kernel contracts with explicit owned state and no sibling or host persistence', function (): void {
    $files = SourceScanner::php('packages/core/modules/groups/src');
    foreach (['markers', 'dependencies', 'internals', 'modules', 'naming', 'tenancy', 'editions', 'ownership', 'signatures'] as $gate) {
        expect(Gates::$gate($files))->toBe([]);
    }
    foreach ($files as $path) {
        expect((string) file_get_contents($path))->not->toContain('SocKit\\Posts', 'SocKit\\Reactions', 'SocKit\\Operations\\Internal', 'tenant_id', 'host_targets', 'beginTransaction(', 'setDefaultConnection(', 'setTable(', 'setConnection(', 'users', 'contours');
    }
    foreach (SourceScanner::php('packages/core/src') as $path) {
        expect((string) file_get_contents($path))->not->toContain('SocKit\\Groups');
    }
    $manifest = json_decode((string) file_get_contents('packages/core/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    expect(data_get($manifest, 'extra.sockit.owners.groups.tables'))->toBe(['groups', 'group_members'])
        ->and(data_get($manifest, 'extra.sockit.owners.groups.module-ids'))->toBe(['sockit.groups'])
        ->and(data_get($manifest, 'extra.laravel.providers'))->toBe([SocKitServiceProvider::class]);
    expect(array_column(JoinPolicy::cases(), 'value'))->toBe(['auto', 'approval', 'invite', 'closed'])
        ->and(array_column(MembershipState::cases(), 'value'))->toBe(['pending', 'invited', 'active', 'left']);
});

it('rejects group imports in the kernel and both sibling owners without weakening their own imports', function (): void {
    foreach (['packages/core/src/Internal' => 'SocKit\\Internal',
        'packages/core/modules/posts/src/Internal' => 'SocKit\\Posts\\Internal',
        'packages/core/modules/reactions/src/Internal' => 'SocKit\\Reactions\\Internal'] as $directory => $namespace) {
        $path = $directory.'/GroupsControl'.bin2hex(random_bytes(8)).'.php';
        $file = fopen($path, 'x');
        if ($file === false) {
            throw new RuntimeException('Cannot exclusively create architecture control');
        }
        fclose($file);
        try {
            file_put_contents($path, '<?php namespace '.$namespace.'; use SocKit\\Groups\\Enums\\JoinPolicy; final class GroupsControl {}');
            expect(Gates::dependencies([$path]))->toHaveCount(1);
        } finally {
            unlink($path);
        }
    }
});

it('requires explicit module-local consent without a permissive production default', function (): void {
    $contract = new ReflectionClass(MembershipConsent::class);
    expect($contract->getAttributes(Spi::class))->toHaveCount(1);
    foreach ([MembershipActivation::class, ConsentDecision::class, MembershipCommand::class] as $dto) {
        expect(new ReflectionClass($dto)->isReadOnly())->toBeTrue();
    }
    foreach (['packages/core/src/SocKitServiceProvider.php', 'packages/core/modules/groups/src/Internal/GroupsServiceProvider.php'] as $provider) {
        expect(file_get_contents($provider))->not->toContain('MembershipConsent');
    }
});

it('composes group audiences and targets only through existing contracts with scoped target preparation', function (): void {
    $resolver = new ReflectionClass(GroupAudienceResolver::class);
    expect($resolver->implementsInterface(AudienceResolver::class))->toBeTrue()
        ->and($resolver->isReadOnly())->toBeTrue()
        ->and($resolver->getAttributes(Api::class))->toHaveCount(1);
    $target = new ReflectionClass(GroupTarget::class);
    expect($target->implementsInterface(TargetResolver::class))->toBeTrue()
        ->and($target->implementsInterface(TargetWriteFence::class))->toBeTrue();
    $app = Selection::app();
    $app->boot();
    $first = $app->make(GroupTarget::class);
    expect($app->make(GroupTarget::class))->toBe($first);
    $app->forgetScopedInstances();
    expect($app->make(GroupTarget::class))->not->toBe($first);
    $app->flush();
});
