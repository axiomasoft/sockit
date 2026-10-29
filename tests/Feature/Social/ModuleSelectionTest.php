<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Modules\Exceptions\InvalidModuleException;
use SocKit\Modules\Modules;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\InvalidPanelException;
use SocKit\Panels\PanelDefinitions;
use SocKit\Posts\Internal\PostTarget;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\Surfaces\Exceptions\InvalidSurfaceDefinitionException;
use SocKit\Tests\Fixtures\Social\Selection;
use SocKit\Tests\Fixtures\Social\SocialPanel;
use SocKit\Tests\Fixtures\Surfaces\ConfiguredSurface;
use SocKit\Tests\Unit\Audience\Support\Scenario;
use Symfony\Component\Process\Process;

it('selects only explicitly enabled and panel selected modules without boot SQL in every provider order', function (array $enabled, array $selected, array $entities): void {
    $enabled = array_map(Scenario::text(...), array_values($enabled));
    $selected = array_map(Scenario::text(...), array_values($selected));
    $providers = Selection::PROVIDERS;
    foreach ([$providers, [$providers[0], $providers[2], $providers[1]], [$providers[1], $providers[0], $providers[2]], [$providers[1], $providers[2], $providers[0]], [$providers[2], $providers[0], $providers[1]], array_reverse($providers)] as $order) {
        $app = Selection::app(enabled: $enabled, selected: $selected, providers: $order);
        $app->boot();
        $modules = $app->make(Modules::class);
        $panel = $app->make(PanelDefinitions::class)->get(new PanelId('host.social'));
        $schema = new PanelSchemaCompiler()->compile($panel, $modules->schemaComponents());
        expect(array_keys($modules->manifests()))->toEqualCanonicalizing($enabled)
            ->and($panel->modules)->toEqualCanonicalizing($selected)
            ->and(array_keys($schema->storage->tables))->toEqualCanonicalizing(['surface_instances', 'receipts', 'outbox', 'tombstones', ...$entities])
            ->and($app->make('db')->getConnections())->toBe([]);
        $features = [];
        foreach ($selected as $id) {
            $features = [...$features, ...$modules->declarations()[$id]['features']];
            expect($modules->declarations()[$id]['targets'])->toBe($id === 'sockit.posts' ? ['post' => PostTarget::class] : []);
        }
        expect(in_array('posts', $features, true))->toBe(in_array('sockit.posts', $selected, true));
    }
})->with([
    'empty' => [[], [], []],
    'reactions only' => [['sockit.reactions'], ['sockit.reactions'], ['reactions', 'reaction_counts']],
    'posts only' => [['sockit.posts'], ['sockit.posts'], ['posts', 'post_revisions']],
    'both' => [['sockit.reactions', 'sockit.posts'], ['sockit.reactions', 'sockit.posts'], ['reactions', 'reaction_counts', 'posts', 'post_revisions']],
    'enabled unselected' => [['sockit.reactions', 'sockit.posts'], [], []],
    'posts offered only' => [['sockit.reactions', 'sockit.posts'], ['sockit.reactions'], ['reactions', 'reaction_counts']],
]);

it('requires explicit provider offering and canonical module IDs and rejects disabled selections', function (): void {
    expect(fn () => Selection::app(enabled: ['sockit.posts'], providers: [Selection::PROVIDERS[0]])->boot())->toThrow(InvalidModuleException::class);
    foreach (['posts', 'reactions', 'unknown.module'] as $id) {
        expect(fn () => Selection::app(enabled: [$id])->boot())->toThrow(str_contains($id, '.') ? InvalidModuleException::class : InvalidConfigurationException::class);
    }
    expect(fn () => Selection::app(selected: ['sockit.posts'])->boot())->toThrow(InvalidPanelException::class);
});

it('normalizes reaction kinds through defaults global settings and panel settings and rejects unknown settings', function (): void {
    $app = Selection::app(enabled: ['sockit.reactions'], selected: ['sockit.reactions'], extra: [
        'sockit' => ['modules' => ['settings' => ['sockit.reactions' => ['kinds' => ['like', 'thanks']]]]],
        'social_fixture' => ['settings' => ['sockit.reactions' => ['kinds' => ['thanks']]]],
    ]);
    $app->boot();
    expect($app->make(Modules::class)->settings()['sockit.reactions'])->toBe(['kinds' => ['like', 'thanks']])
        ->and($app->make(PanelDefinitions::class)->get(new PanelId('host.social'))->moduleSettings['sockit.reactions'])->toBe(['kinds' => ['thanks']]);
    foreach ([['kinds' => []], ['kinds' => ['like', 'like']], ['kinds' => ['bad.kind']], ['kinds' => [1]], ['unknown' => true]] as $settings) {
        expect(fn () => Selection::app(enabled: ['sockit.reactions'], extra: ['sockit' => ['modules' => ['settings' => ['sockit.reactions' => $settings]]]])->boot())->toThrow(InvalidModuleException::class);
    }
    expect(fn () => Selection::app(enabled: ['sockit.posts'], extra: ['sockit' => ['modules' => ['settings' => ['sockit.posts' => ['unknown' => true]]]]])->boot())->toThrow(InvalidModuleException::class);
});

it('runs real config cache and fresh process bootstrap for every selection without persistence discovery', function (array $selected): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/sockit-p41-cache-'.bin2hex(random_bytes(12));
    foreach (['bootstrap/cache', 'config', 'storage/framework', 'storage/logs'] as $directory) {
        $files->makeDirectory($root.'/'.$directory, 0700, true);
    }
    $files->put($root.'/bootstrap/app.php', '<?php return \\Illuminate\\Foundation\\Application::configure(basePath: __DIR__."/..")->withProviders('.var_export(Selection::PROVIDERS, true).')->create();');
    $files->put($root.'/config/sockit.php', '<?php return '.var_export(['panels' => [SocialPanel::class], 'modules' => ['enabled' => $selected]], true).';');
    $files->put($root.'/config/social_fixture.php', '<?php return '.var_export(['selected' => $selected], true).';');
    $files->put($root.'/config/database.php', '<?php return '.var_export(['default' => 'owner', 'connections' => ['owner' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'missing_test']]], true).';');
    $run = static function (string $command) use ($root): array {
        $p = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/PanelConsumer/boot.php', $root, $command], env: ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $root.'/bootstrap/cache/config.php']);
        $p->mustRun();

        return Scenario::object(json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    };
    try {
        $before = $run('snapshot');
        expect($before['queries'])->toBe(0)->and($before['connections'])->toBe(0)
            ->and($before['models_loaded'])->toHaveCount(4 + count($selected) * 2);
        expect($run('config:cache'))->toBe($before)->and(is_file($root.'/bootstrap/cache/config.php'))->toBeTrue();
        expect($run('snapshot'))->toBe($before);
    } finally {
        $files->deleteDirectory($root);
    }
})->with(['empty' => [[]], 'reactions' => [['sockit.reactions']], 'posts' => [['sockit.posts']], 'both' => [['sockit.reactions', 'sockit.posts']]]);

it('rejects posts feature on a reactions-only panel even when posts is globally enabled', function (): void {
    foreach ([['sockit.reactions'], ['sockit.reactions', 'sockit.posts']] as $enabled) {
        $app = Selection::app(enabled: $enabled, selected: ['sockit.reactions'], extra: ['sockit' => ['surfaces' => [ConfiguredSurface::class]]]);
        $app->instance(ConfiguredSurface::class, new ConfiguredSurface(['panel' => 'host.social', 'features' => ['posts']]));
        expect(fn () => $app->boot())->toThrow(InvalidSurfaceDefinitionException::class);
    }
});
