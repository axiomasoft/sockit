<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use SocKit\Modules\Exceptions\InvalidModuleException;
use SocKit\Modules\ModuleRegistrar;
use SocKit\Modules\Modules;
use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Tests\Fixtures\Runtime\DuplicateOutboxModule;
use SocKit\Tests\Fixtures\Runtime\OutboxModule;
use SocKit\Tests\Fixtures\Runtime\OutboxScenario;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Runtime\SqlInboxConsumer;

it('freezes additive consumer declarations without instantiating consumers or issuing SQL', function (): void {
    $app = OutboxScenario::app();
    $declarations = $app->make(Modules::class)->declarations();
    expect($declarations['acme.runtime']['consumers'])->toBe(['acme.inbox' => SqlInboxConsumer::class])
        ->and($app->resolved(SqlInboxConsumer::class))->toBeFalse();
    $registrar = new ModuleRegistrar('acme.test');
    $registrar->outboxConsumer(id: 'acme.inbox', consumer: SqlInboxConsumer::class)->freeze();
    expect(fn () => $registrar->outboxConsumer(id: 'acme.other', consumer: SqlInboxConsumer::class))->toThrow(InvalidModuleException::class);
});

it('rejects invalid, duplicate and non-concrete consumer declarations', function (string $id, string $class): void {
    $registrar = new ModuleRegistrar('acme.test');
    $registrar->outboxConsumer(id: 'acme.inbox', consumer: SqlInboxConsumer::class);
    expect(fn () => $registrar->outboxConsumer(id: $id, consumer: $class))->toThrow(InvalidModuleException::class);
})->with([
    ['acme.inbox', SqlInboxConsumer::class], ['', SqlInboxConsumer::class], ['bad/id', SqlInboxConsumer::class],
    ['acme.other', stdClass::class], ['acme.other', OutboxConsumer::class],
]);

it('rejects duplicate consumer IDs across modules before graph publication', function (): void {
    $app = RuntimeScenario::app(boot: false);
    $app->make('config')->set(key: 'sockit.modules.enabled', value: ['acme.runtime', 'acme.other']);
    Modules::add(app: $app, modules: OutboxModule::class);
    Modules::add(app: $app, modules: DuplicateOutboxModule::class);
    try {
        $app->boot();
        Assert::fail('Duplicate consumer ID accepted');
    } catch (InvalidModuleException $error) {
        expect(array_column($error->errors, 'rule'))->toContain('MODULE_CONSUMER_DUPLICATE')
            ->and($app->resolved(SqlInboxConsumer::class))->toBeFalse();
    }
    expect(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class);
});

it('globally disabled offered consumers have no published declaration or resolution cost', function (): void {
    $app = RuntimeScenario::app(boot: false);
    Modules::add(app: $app, modules: OutboxModule::class);
    $app->bind(abstract: SqlInboxConsumer::class, concrete: static fn () => throw new RuntimeException('Disabled consumer resolved'));
    $app->boot();
    expect($app->make(Modules::class)->declarations())->toBe([])->and($app->resolved(SqlInboxConsumer::class))->toBeFalse();
});
