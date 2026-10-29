<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Assert;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Actors\SingleInstallationTenantResolver;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Configuration\Exceptions\ProviderUnavailableException;
use SocKit\Configuration\SocKitConfig;
use SocKit\SocKitServiceProvider;
use SocKit\Testing\SocKitFake;
use SocKit\Tests\Fixtures\Modules\StatefulTenantResolver;

it('boots defaults without SQL and fails missing resolvers explicitly at resolution', function (): void {
    expect(app('db')->getConnections())->toBe([]);
    foreach ([TenantResolver::class, ActorContextResolver::class, ContourResolver::class, AudienceResolver::class, ActionAuthorizer::class, GroupMembershipResolver::class, SurfaceAudiencePolicy::class] as $contract) {
        try {
            app()->make($contract);
            Assert::fail('Missing resolver accepted');
        } catch (ProviderUnavailableException $error) {
            expect($error->problem()->value)->toBe('sockit.provider_unavailable')->and($error->detail()['key'])->toStartWith('sockit.resolvers.');
        }
    }
});

it('uses scoped host resolvers and immutable singleton config without capturing the preceding job', function (): void {
    $app = new Application;
    $app->instance('config', new Repository(['sockit' => ['resolvers' => ['tenant' => StatefulTenantResolver::class]]]));
    $app->register(SocKitServiceProvider::class);
    $app->boot();
    $config = $app->make(SocKitConfig::class);
    $first = $app->make(TenantResolver::class);
    expect($first)->toBeInstanceOf(StatefulTenantResolver::class);
    assert($first instanceof StatefulTenantResolver);
    $first->installation = 'job-one';
    expect($app->make(TenantResolver::class))->toBe($first);
    $app->forgetScopedInstances();
    $second = $app->make(TenantResolver::class);
    expect($second)->not->toBe($first)->and($second->current()->id)->toBe('one')
        ->and($app->make(SocKitConfig::class))->toBe($config);
});

it('requires explicit single-installation opt in', function (): void {
    $app = new Application;
    $app->instance('config', new Repository(['sockit' => ['resolvers' => ['tenant' => SingleInstallationTenantResolver::class]]]));
    $app->register(SocKitServiceProvider::class);
    $app->boot();
    expect($app->make(TenantResolver::class))->toBeInstanceOf(SingleInstallationTenantResolver::class);
});

it('installs all existing fakes over the provider bindings', function (): void {
    $fake = SocKitFake::install(app());
    foreach ([TenantResolver::class => $fake->tenants, ActorContextResolver::class => $fake->actors, ContourResolver::class => $fake->contours,
        GroupMembershipResolver::class => $fake->groups, ActionAuthorizer::class => $fake->authorizer, AudienceResolver::class => $fake->audience, SurfaceAudiencePolicy::class => $fake->surfaces] as $contract => $instance) {
        expect(app()->make($contract))->toBe($instance);
    }
});
