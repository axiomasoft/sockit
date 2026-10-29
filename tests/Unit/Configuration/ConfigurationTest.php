<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use PHPUnit\Framework\Assert;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Configuration\SocKitConfig;

it('normalizes nested defaults without widening the default floor', function (): void {
    $config = SocKitConfig::fromRepository(new Repository(['sockit' => ['ids' => ['strategy' => 'uuid7']]]));
    expect($config->modules->enabled)->toBe([])
        ->and($config->privacy->floor->defaultScope->value)->toBe('direct')
        ->and($config->resolvers->tenant)->toBeNull();
});

it('rejects unknown keys, objects, invalid shapes, classes and floors with a path and rule', function (array $input): void {
    try {
        SocKitConfig::fromRepository(new Repository(['sockit' => $input]));
        Assert::fail('Invalid configuration accepted');
    } catch (InvalidConfigurationException $error) {
        expect($error->detail()['key'])->toBeString()->not()->toBeEmpty()
            ->and($error->detail()['rule'])->toBeString()->not()->toBeEmpty();
    }
})->with([
    [['unexpected' => true]],
    [['ids' => null]],
    [['privacy' => ['floor' => null]]],
    [['modules' => null]],
    [['modules' => ['settings' => ['demo.module' => [1, 2]]]]],
    [['ids' => ['strategy' => 'uuid4']]],
    [['ids' => new stdClass]],
    [['modules' => ['enabled' => 'demo.module']]],
    [['modules' => ['settings' => ['bad' => []]]]],
    [['modules' => ['settings' => ['demo.module' => ['secret' => fn () => true]]]]],
    [['resolvers' => ['actor' => stdClass::class]]],
    [['panels' => ['MissingProvider']]],
    [['privacy' => ['floor' => ['default_scope' => 'public']]]],
    [['privacy' => ['floor' => ['allowed_scopes' => ['unknown']]]]],
]);

it('retains defaults for explicit empty configuration sections', function (): void {
    $config = SocKitConfig::fromRepository(new Repository(['sockit' => ['ids' => [], 'privacy' => ['floor' => []], 'modules' => [], 'resolvers' => []]]));
    expect($config->ids->strategy)->toBe('uuid7')->and($config->privacy->floor->defaultScope->value)->toBe('direct');
});
