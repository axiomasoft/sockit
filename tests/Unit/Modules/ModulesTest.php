<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Assert;
use SocKit\Configuration\Data\ModulesConfig;
use SocKit\Modules\Contracts\Module;
use SocKit\Modules\Contracts\ModuleSettingsSchema;
use SocKit\Modules\Exceptions\InvalidModuleException;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;
use SocKit\Modules\Modules;
use SocKit\SocKitServiceProvider;
use SocKit\Tests\Fixtures\Modules\DemoModule;
use SocKit\Tests\Fixtures\Modules\DemoSettingsSchema;
use SocKit\Tests\Fixtures\Modules\OtherModule;

/** @param array<string, mixed> $config */
function moduleApp(array $config = []): Application
{
    $app = new Application;
    $app->instance('config', new Repository(['sockit' => $config]));
    $app->register(SocKitServiceProvider::class);

    return $app;
}

it('rejects early resolution without freezing collection and closes registration at boot', function (): void {
    $app = moduleApp(['modules' => ['enabled' => ['demo.module']]]);
    expect(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class);
    Modules::add(app: $app, modules: DemoModule::class);
    Modules::add(app: $app, modules: DemoModule::class);
    $app->boot();
    expect($app->make(Modules::class)->settings())->toBe(['demo.module' => ['limit' => 10]])
        ->and($app->make(Modules::class)->declarations()['demo.module']['features'])->toBe(['demo'])
        ->and(fn () => Modules::add(app: $app, modules: DemoModule::class))->toThrow(InvalidModuleException::class);
});

it('offers distribution modules and leaves disabled declarations inactive', function (): void {
    $app = moduleApp();
    expect(Modules::module(app: $app, module: DemoModule::class))->toBeFalse();
    $app->boot();
    expect($app->make(Modules::class)->manifests())->toBe([])
        ->and($app->make(Modules::class)->declarations())->toBe([]);
});

it('produces identical graphs for provider registration order permutations', function (): void {
    $snapshots = [];
    foreach ([[DemoModule::class, OtherModule::class], [OtherModule::class, DemoModule::class]] as $order) {
        $app = moduleApp(['modules' => ['enabled' => ['other.module', 'demo.module'], 'settings' => ['demo.module' => ['limit' => 20]]]]);
        foreach ($order as $module) {
            Modules::add(app: $app, modules: $module);
        }
        $app->boot();
        $graph = $app->make(Modules::class);
        $snapshots[] = [$graph->declarations(), $graph->settings()];
    }
    expect($snapshots[0])->toBe($snapshots[1])->and($snapshots[0][1]['demo.module'])->toBe(['limit' => 20]);
});

it('fails listed-not-offered and never publishes a partial graph', function (): void {
    $app = moduleApp(['modules' => ['enabled' => ['demo.module', 'missing.module']]]);
    Modules::add(app: $app, modules: DemoModule::class);
    try {
        $app->boot();
        Assert::fail('Accepted missing module');
    } catch (InvalidModuleException $error) {
        expect(array_column($error->errors, 'rule'))->toContain('MODULE_LISTED_NOT_OFFERED');
    }
    expect(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class)
        ->and(fn () => Modules::add(app: $app, modules: OtherModule::class))->toThrow(InvalidModuleException::class);
});

it('collects manifest errors before running any draft registration', function (): void {
    $app = moduleApp();
    $module = new class implements Module
    {
        public bool $called = false;

        public function manifest(): ModuleManifest
        {
            return new ModuleManifest(id: 'BAD', resourceRoot: '/a/../b', databases: ['oracle']);
        }

        public function register(ModuleRegistrar $registrar): void
        {
            $this->called = true;
        }
    };
    $app->instance($module::class, $module);
    Modules::add(app: $app, modules: $module::class);
    try {
        $app->boot();
        Assert::fail('Accepted invalid manifest');
    } catch (InvalidModuleException $error) {
        expect(array_column($error->errors, 'rule'))->toBe(['MODULE_INVALID_ID', 'MODULE_INVALID_RESOURCE_ROOT', 'MODULE_INVALID_DATABASE']);
    }
    expect($module->called)->toBeFalse();
});

it('rejects duplicate module identity', function (): void {
    $app = moduleApp();
    $module = new class implements Module
    {
        public function manifest(): ModuleManifest
        {
            return new ModuleManifest('demo.module');
        }

        public function register(ModuleRegistrar $registrar): void {}
    };
    $app->instance($module::class, $module);
    Modules::add(app: $app, modules: $module::class);
    Modules::add(app: $app, modules: DemoModule::class);
    try {
        $app->boot();
        Assert::fail('Accepted duplicate module');
    } catch (InvalidModuleException $error) {
        expect(array_column($error->errors, 'rule'))->toContain('MODULE_DUPLICATE_ID');
    }
});

it('keeps duplicate feature drafts private', function (): void {
    $app = moduleApp(['modules' => ['enabled' => ['demo.module', 'other.module']]]);
    $module = new class implements Module
    {
        public function manifest(): ModuleManifest
        {
            return new ModuleManifest('other.module');
        }

        public function register(ModuleRegistrar $registrar): void
        {
            $registrar->feature('demo');
        }
    };
    $app->instance($module::class, $module);
    Modules::add(app: $app, modules: $module::class);
    Modules::add(app: $app, modules: DemoModule::class);
    try {
        $app->boot();
        Assert::fail('Accepted duplicate feature');
    } catch (InvalidModuleException $error) {
        expect(array_column($error->errors, 'rule'))->toContain('MODULE_FEATURE_DUPLICATE');
    }
    expect(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class);
});

it('rejects unknown and invalid module settings without disclosing values', function (array $settings): void {
    $app = moduleApp(['modules' => ['enabled' => ['demo.module'], 'settings' => ['demo.module' => $settings]]]);
    Modules::add(app: $app, modules: DemoModule::class);
    try {
        $app->boot();
        Assert::fail('Accepted invalid settings');
    } catch (InvalidModuleException $error) {
        expect($error->errors[0]['module'])->toBe('demo.module')
            ->and(json_encode($error->errors))->not->toContain('secret-value');
    }
})->with([[['unknown' => 'secret-value']], [['limit' => 'secret-value']], [['limit' => 0]]]);

it('rejects supplied settings without schema and invalid schema slots', function (): void {
    $app = moduleApp(['modules' => ['enabled' => ['other.module'], 'settings' => ['other.module' => ['limit' => 1]]]]);
    Modules::add(app: $app, modules: OtherModule::class);
    try {
        $app->boot();
        Assert::fail('Accepted schema-less settings');
    } catch (InvalidModuleException $error) {
        expect($error->errors[0]['rule'])->toBe('MODULE_SETTINGS_INVALID');
    }
    $draft = new ModuleRegistrar('demo.module');
    expect(fn () => $draft->settings(stdClass::class))->toThrow(InvalidModuleException::class);
    $draft->settings(DemoSettingsSchema::class);
    expect(fn () => $draft->settings(DemoSettingsSchema::class))->toThrow(InvalidModuleException::class)
        ->and(fn () => $draft->feature('BAD_FEATURE'))->toThrow(InvalidModuleException::class);
});

it('merges maps and replaces lists including an explicit empty list', function (): void {
    expect(ModulesConfig::merge(defaults: ['map' => ['one' => 1, 'two' => 2], 'list' => [1, 2]], overrides: ['map' => ['one' => 3], 'list' => [3]]))
        ->toBe(['map' => ['one' => 3, 'two' => 2], 'list' => [3]])
        ->and(ModulesConfig::merge(defaults: ['list' => [1]], overrides: ['list' => []]))->toBe(['list' => []]);
});

it('never calls registration on a disabled module', function (): void {
    $app = moduleApp();
    $module = new class implements Module
    {
        public function manifest(): ModuleManifest
        {
            return new ModuleManifest('disabled.module');
        }

        public function register(ModuleRegistrar $registrar): void
        {
            throw new LogicException('Disabled module registered');
        }
    };
    $app->instance($module::class, $module);
    Modules::add(app: $app, modules: $module::class);
    $app->boot();
    expect($app->make(Modules::class)->declarations())->toBe([]);
});

it('rejects invalid module classes and unknown schema interfaces', function (): void {
    $app = moduleApp();
    Modules::add(app: $app, modules: stdClass::class);
    try {
        $app->boot();
        Assert::fail('Accepted non-module implementation');
    } catch (InvalidModuleException $error) {
        expect($error->errors[0]['rule'])->toBe('MODULE_IMPLEMENTATION_INVALID');
    }
    $draft = new ModuleRegistrar('demo.module');
    expect(fn () => $draft->settings('MissingSchema'))->toThrow(InvalidModuleException::class)
        ->and(fn () => $draft->settings(ModuleSettingsSchema::class))->toThrow(InvalidModuleException::class);
});

it('publishes value snapshots that retained draft registrars cannot mutate', function (): void {
    $app = moduleApp(['modules' => ['enabled' => ['draft.module']]]);
    $module = new class implements Module
    {
        public ?ModuleRegistrar $draft = null;

        public function manifest(): ModuleManifest
        {
            return new ModuleManifest('draft.module');
        }

        public function register(ModuleRegistrar $registrar): void
        {
            $this->draft = $registrar;
            $registrar->feature('original');
        }
    };
    $app->instance($module::class, $module);
    Modules::add(app: $app, modules: $module::class);
    $app->boot();
    $module->draft?->feature('late');
    expect($app->make(Modules::class)->declarations()['draft.module']['features'])->toBe(['original']);
});
