<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Assert;
use SocKit\Internal\Generation\ExclusiveWriter;
use SocKit\Internal\Generation\GenerationFailure;
use SocKit\Internal\Generation\GenerationPlan;
use SocKit\Tests\Fixtures\PanelConsumer\GenerationFixture;
use SocKit\Tests\Unit\Audience\Support\Scenario;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    GenerationFixture::current();
});

afterEach(function (): void {
    GenerationFixture::reset();
});

it('preflights every path before writing any output', function (string $kind): void {
    $root = GenerationFixture::current()->root;
    mkdir($root.'/app/Models/SocKit/Article', 0755, true);
    $leaf = $root.'/app/Models/SocKit/Article/Tombstone.php';
    match ($kind) {
        'file' => file_put_contents($leaf, '<?php // custom'),
        'directory' => mkdir($leaf),
        'broken symlink' => symlink($root.'/missing', $leaf),
        'case' => file_put_contents(dirname($leaf).'/tombstone.php', '<?php // custom'),
        default => throw new InvalidArgumentException('Unknown conflict fixture'),
    };
    $before = GenerationFixture::current()->tree();
    expect(GenerationFixture::current()->command()['exit'])->toBe(1)->and(GenerationFixture::current()->tree())->toBe($before)
        ->and(is_dir($root.'/app/Panels'))->toBeFalse();
})->with(['file', 'directory', 'broken symlink', 'case']);

it('rejects directory symlink escapes including the PSR-4 root', function (string $directory): void {
    $outside = sys_get_temp_dir().'/sockit-outside-'.bin2hex(random_bytes(16));
    mkdir($outside, 0700);
    try {
        $parent = dirname(GenerationFixture::current()->root.'/'.$directory);
        if (! is_dir($parent)) {
            mkdir($parent, 0755, true);
        }
        symlink($outside, GenerationFixture::current()->root.'/'.$directory);
        $before = GenerationFixture::current()->tree();
        expect(GenerationFixture::current()->command()['exit'])->toBe(1)->and(GenerationFixture::current()->tree())->toBe($before)->and(scandir($outside))->toBe(['.', '..']);
    } finally {
        unlink(GenerationFixture::current()->root.'/'.$directory);
        rmdir($outside);
    }
})->with(['app', 'app/Panels', 'app/Models']);

it('rejects case-insensitive FQCN declarations in nonstandard filenames', function (): void {
    mkdir(GenerationFixture::current()->root.'/app');
    file_put_contents(GenerationFixture::current()->root.'/app/custom.php', '<?php namespace acme\\models\\sockit\\article; class demotarget {}');
    $before = GenerationFixture::current()->tree();
    $result = GenerationFixture::current()->command();
    expect($result['exit'])->toBe(1)->and($result['output'])->toContain('GENERATION_FQCN_EXISTS')->and(GenerationFixture::current()->tree())->toBe($before);
});

it('rejects a case-insensitive Composer classmap collision outside the app mapping without loading it', function (): void {
    $root = GenerationFixture::current()->root;
    $loader = new ClassLoader($root.'/vendor');
    $loader->addClassMap(['acme\\panels\\articlepanelprovider' => $root.'/custom.php']);
    file_put_contents($root.'/custom.php', '<?php throw new RuntimeException("Must not autoload");');
    $loader->register();
    try {
        $before = GenerationFixture::current()->tree();
        $result = GenerationFixture::current()->command();
        expect($result['exit'])->toBe(1)->and($result['output'])->toContain('GENERATION_FQCN_EXISTS')->and(GenerationFixture::current()->tree())->toBe($before);
    } finally {
        $loader->unregister();
    }
});

it('validates all staged PHP before installing any final file', function (): void {
    $plan = GenerationFixture::current()->plan();
    $sources = $plan->sources;
    $sources[array_key_last($sources)] = '<?php invalid php syntax;';
    $before = GenerationFixture::current()->tree();
    expect(fn () => new ExclusiveWriter()->install(new GenerationPlan(root: $plan->root, sources: $sources, manifest: $plan->manifest)))->toThrow(GenerationFailure::class);
    expect(GenerationFixture::current()->tree())->toBe($before);
});

it('cleans only invocation-owned files after staging or partial install failure', function (string $event, int $after): void {
    $plan = GenerationFixture::current()->plan();
    $before = GenerationFixture::current()->tree();
    $calls = 0;
    $writer = new ExclusiveWriter(static function (string $observed) use ($event, $after, &$calls): void {
        if ($event === $observed && ++$calls === $after) {
            throw new RuntimeException('Injected disk or move failure');
        }
    });
    try {
        $writer->install($plan);
        Assert::fail('Injected failure succeeded');
    } catch (GenerationFailure $error) {
        expect($error->recovery)->toBe([]);
    }
    expect(GenerationFixture::current()->tree())->toBe($before);
})->with([['staged', 2], ['installed', 1], ['installed', 4], ['installed', 7]]);

it('preserves a competing final file created after preflight', function (): void {
    $plan = GenerationFixture::current()->plan();
    $first = array_key_first($plan->sources);
    $writer = new ExclusiveWriter(static function (string $event, string $path) use ($plan): void {
        if ($event === 'before_link') {
            file_put_contents($plan->root.'/'.$path, 'racing user file');
        }
    });
    expect(fn () => $writer->install($plan))->toThrow(GenerationFailure::class);
    expect(file_get_contents($plan->root.'/'.$first))->toBe('racing user file');
    expect(glob($plan->root.'/.sockit-stage-*'))->toBe([]);
});

it('reports precise recovery paths and preserves user edits to partially installed output', function (): void {
    $plan = GenerationFixture::current()->plan();
    $first = array_key_first($plan->sources);
    $writer = new ExclusiveWriter(static function (string $event, string $path) use ($plan): void {
        if ($event === 'installed') {
            // Atomic replacement gives the host a different inode from the staged source.
            unlink($plan->root.'/'.$path);
            file_put_contents($plan->root.'/'.$path, 'custom replacement');
            throw new RuntimeException('Injected failure after host edit');
        }
    });
    GenerationFixture::current()->app->instance(ExclusiveWriter::class, $writer);
    $result = GenerationFixture::current()->command();
    expect($result['exit'])->toBe(1);
    $failure = Scenario::object(json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR));
    expect(Scenario::object($failure['manifest'])['panel'])->toBe('acme.article')->and($failure['recovery'])->toContain($first)
        ->and(file_get_contents($plan->root.'/'.$first))->toBe('custom replacement');
    expect(glob($plan->root.'/.sockit-stage-*'))->toBe([]);
});

it('arbitrates real concurrent installs without replacement or partial survivors', function (): void {
    $plan = GenerationFixture::current()->plan();
    $input = $plan->root.'/invocation.json';
    file_put_contents($input, json_encode(['root' => $plan->root, 'sources' => $plan->sources, 'manifest' => $plan->manifest], JSON_THROW_ON_ERROR));
    $code = <<<'PHP'
require $argv[1];
$data = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
$plan = new \SocKit\Internal\Generation\GenerationPlan(...$data);
try {
    (new \SocKit\Internal\Generation\ExclusiveWriter(static function (string $event): void { if ($event === 'installed') { usleep(20000); } }))->install($plan);
    exit(0);
} catch (\SocKit\Internal\Generation\GenerationFailure) { exit(1); }
PHP;
    $command = [PHP_BINARY, '-r', $code, dirname(__DIR__, 3).'/vendor/autoload.php', $input];
    $a = new Process($command);
    $b = new Process($command);
    $a->setTimeout(30);
    $b->setTimeout(30);
    $a->start();
    $b->start();
    $exits = [$a->wait(), $b->wait()];
    sort($exits);
    expect($exits)->toBe([0, 1])->and($a->getErrorOutput().$b->getErrorOutput())->toBe('');
    foreach ($plan->sources as $path => $source) {
        expect(file_get_contents($plan->root.'/'.$path))->toBe($source);
    }
    expect(glob($plan->root.'/.sockit-stage-*'))->toBe([]);
});
