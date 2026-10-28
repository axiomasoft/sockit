<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use SocKit\Internal\Generation\ExclusiveWriter;
use SocKit\Internal\Generation\GenerationFailure;
use SocKit\Internal\Generation\PanelGenerator;
use SocKit\Modules\Modules;
use SocKit\Panels\Panel;
use SocKit\Panels\PanelDefinitions;
use SocKit\Panels\PanelProvider;
use SocKit\Schema\Exceptions\InvalidSchemaException;
use SocKit\SocKitServiceProvider;
use SocKit\Tests\Fixtures\PanelConsumer\GenerationFixture;
use SocKit\Tests\Fixtures\Schema\SchemaProvider;
use SocKit\Tests\Unit\Audience\Support\Scenario;
use Symfony\Component\Console\Exception\InvalidOptionException;

beforeEach(function (): void {
    GenerationFixture::current();
});

afterEach(function (): void {
    GenerationFixture::reset();
});

it('prints all provider/models/physical tables and exact registration without any dry-run writes', function (): void {
    $before = GenerationFixture::current()->tree();
    $result = GenerationFixture::current()->command(['--dry-run' => true]);
    expect($result['exit'])->toBe(0);
    $manifest = Scenario::object(json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR));
    expect($manifest['status'])->toBe('dry_run')->and($manifest['panel'])->toBe('acme.article')
        ->and($manifest['provider'])->toBe('Acme\\Panels\\ArticlePanelProvider')
        ->and($manifest['files'])->toHaveCount(7)->and($manifest['models'])->toHaveCount(6)
        ->and(Scenario::object($manifest['tables'])['demo_targets'])->toBe('driver_article_demo_targets')
        ->and(array_keys(Scenario::object($manifest['components'])))->toBe(['acme.demo-targets', 'sockit.kernel'])
        ->and($manifest['registration'])->toContain("'panels' => [\\Acme\\Panels\\ArticlePanelProvider::class]")
        ->and(GenerationFixture::current()->tree())->toBe($before);
});

it('installs concrete sources and a repeat fails with zero modifications', function (): void {
    $plan = GenerationFixture::current()->plan();
    $result = GenerationFixture::current()->command();
    expect($result['exit'])->toBe(0);
    foreach ($plan->sources as $path => $source) {
        expect(file_get_contents($plan->root.'/'.$path))->toBe($source);
    }
    expect(file_get_contents($plan->root.'/app/Models/SocKit/Article/DemoTarget.php'))->toContain("'panel' => 'acme.article'", "'connection' => 'owner_a'", "'table' => 'article_demo_targets'");
    $before = GenerationFixture::current()->tree();
    expect(GenerationFixture::current()->command()['exit'])->toBe(1)->and(GenerationFixture::current()->tree())->toBe($before);
    expect(glob($plan->root.'/.sockit-stage-*'))->toBe([]);
});

/** @param array<string, string|bool> $options */
it('rejects invalid input and unavailable modules before filesystem changes', function (array $options): void {
    $options = array_map(static fn (mixed $value): string|bool => is_string($value) || is_bool($value) ? $value : throw new InvalidArgumentException('Invalid fixture option'), Scenario::object($options));
    $before = GenerationFixture::current()->tree();
    expect(GenerationFixture::current()->command($options)['exit'])->toBe(1)->and(GenerationFixture::current()->tree())->toBe($before);
})->with([
    'missing explicit id' => [['--id' => '']],
    'bad id' => [['--id' => 'Article']],
    'host traversal' => [['--host' => '../article']],
    'name traversal' => [['name' => '../Article']],
    'unknown module' => [['--modules' => 'reactions']],
    'duplicate module' => [['--modules' => 'acme.demo-targets,acme.demo-targets']],
    'overlong final table' => [['--prefix' => str_repeat('x', 45)]],
    'unsafe prefix' => [['--prefix' => 'article;drop']],
    'unknown connection' => [['--connection' => 'missing']],
    'unsafe schema' => [['--schema' => 'public.bad']],
]);

it('offers no force overwrite escape', function (): void {
    $before = GenerationFixture::current()->tree();
    expect(fn () => GenerationFixture::current()->command(['--force' => true]))->toThrow(InvalidOptionException::class);
    expect(GenerationFixture::current()->tree())->toBe($before);
});

it('selects only the kernel baseline when modules are omitted', function (): void {
    $before = GenerationFixture::current()->tree();
    $result = GenerationFixture::current()->command(['--modules' => '']);
    $manifest = Scenario::object(json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR));
    expect($result['exit'])->toBe(0)->and($manifest['models'])->toHaveCount(4)
        ->and(array_keys(Scenario::object($manifest['components'])))->toBe(['sockit.kernel']);
    expect(count(GenerationFixture::current()->tree()))->toBeGreaterThan(count($before));
});

it('rejects ambiguous or escaping composer paths without creating any directories', function (mixed $mapping): void {
    file_put_contents(GenerationFixture::current()->root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['Acme\\' => $mapping]]], JSON_THROW_ON_ERROR));
    $before = GenerationFixture::current()->tree();
    expect(GenerationFixture::current()->command()['exit'])->toBe(1)->and(GenerationFixture::current()->tree())->toBe($before);
})->with(['../outside', '/tmp/absolute', 'app/../outside', [['app/', 'src/']]]);

it('derives paths and namespaces from the actual consumer mapping and rejects ambiguous mappings', function (): void {
    file_put_contents(GenerationFixture::current()->root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['Company\\Domain\\' => 'src/']]], JSON_THROW_ON_ERROR));
    $plan = GenerationFixture::current()->plan();
    expect(array_key_first($plan->sources))->toBe('src/Panels/ArticlePanelProvider.php')->and($plan->manifest['provider'])->toBe('Company\\Domain\\Panels\\ArticlePanelProvider');
    new ExclusiveWriter()->install($plan);
    file_put_contents(GenerationFixture::current()->root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['Acme\\' => 'app/', 'Other\\' => 'other/']]], JSON_THROW_ON_ERROR));
    $before = GenerationFixture::current()->tree();
    expect(GenerationFixture::current()->command()['exit'])->toBe(1)->and(GenerationFixture::current()->tree())->toBe($before);
});

it('rejects registered panel identity and physical aliases before output', function (): void {
    $config = GenerationFixture::current()->app->make('config');
    $config->set('database.connections.alias', $config->get('database.connections.owner_a'));
    $config->set('sockit.storage.deployments.alias', $config->get('sockit.storage.deployments.owner_a'));
    // Publish a separate draft registry through the real provider compiler.
    $provider = new class extends PanelProvider
    {
        public function panel(Panel $panel): Panel
        {
            return $panel->id('acme.existing')->hostTypes('article')->connection('alias')->schema('public')->prefix('article_');
        }
    };
    $class = $provider::class;
    $app = new Application;
    $app->instance('config', $config);
    $config->set('sockit.panels', [$class]);
    $app->instance($class, $provider);
    $app->register(SocKitServiceProvider::class);
    $app->register(SchemaProvider::class);
    $app->boot();
    $generator = new PanelGenerator($app->make(Modules::class), $app->make(PanelDefinitions::class), $config);
    expect(fn () => $generator->plan(root: GenerationFixture::current()->root, name: 'NewPanel', id: 'acme.new', host: 'article', modules: [], prefix: 'article_', connection: 'owner_a', schema: 'public'))
        ->toThrow(InvalidSchemaException::class);
    expect(fn () => $generator->plan(root: GenerationFixture::current()->root, name: 'NewPanel', id: 'acme.existing', host: 'article', modules: [], prefix: 'different_', connection: 'owner_a', schema: 'public'))
        ->toThrow(GenerationFailure::class, 'GENERATION_PANEL_EXISTS');
});
