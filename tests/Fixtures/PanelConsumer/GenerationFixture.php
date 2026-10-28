<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\PanelConsumer;

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use SocKit\Internal\Console\MakePanelCommand;
use SocKit\Internal\Generation\GenerationPlan;
use SocKit\Internal\Generation\PanelGenerator;
use SocKit\Modules\Modules;
use SocKit\Panels\PanelDefinitions;
use SocKit\SocKitServiceProvider;
use SocKit\Tests\Fixtures\Schema\DemoModelContribution;
use SocKit\Tests\Fixtures\Schema\SchemaProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class GenerationFixture
{
    private static ?self $current = null;

    public static function current(): self
    {
        return self::$current ??= new self;
    }

    public static function reset(): void
    {
        self::$current?->cleanup();
        self::$current = null;
    }

    public readonly string $root;

    public readonly Application $app;

    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/sockit-generator-'.bin2hex(random_bytes(16));
        if (! mkdir($this->root, 0700)) {
            throw new \RuntimeException('Cannot create private filesystem target');
        }
        file_put_contents($this->root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['Acme\\' => 'app/']]], JSON_THROW_ON_ERROR));
        $this->app = new Application($this->root);
        $this->app->instance('config', new Repository(['sockit' => ['modules' => ['enabled' => ['acme.demo-targets']], 'storage' => ['deployments' => [
            'owner_a' => ['cluster' => 'generator', 'endpoint' => ['host' => '127.0.0.1', 'port' => 5432]],
        ]]], 'database' => ['connections' => ['owner_a' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => 'generator_test', 'prefix' => 'driver_']]],
            'schema_fixture' => ['contribution' => DemoModelContribution::class]]));
        $this->app->bind('db', static function (): never {
            throw new \RuntimeException('Generation must never resolve a database');
        });
        $this->app->register(SocKitServiceProvider::class);
        $this->app->register(SchemaProvider::class);
        $this->app->boot();
    }

    /** @param array{root?:string, name?:string, id?:string, host?:string, modules?:list<string>, prefix?:string, connection?:string, schema?:string} $overrides */
    public function plan(array $overrides = []): GenerationPlan
    {
        return $this->generator()->plan(...[...['root' => $this->root, 'name' => 'Article', 'id' => 'acme.article', 'host' => 'article',
            'modules' => ['acme.demo-targets'], 'prefix' => 'article_', 'connection' => 'owner_a', 'schema' => 'public'], ...$overrides]);
    }

    public function generator(): PanelGenerator
    {
        return new PanelGenerator(modules: $this->app->make(Modules::class), panels: $this->app->make(PanelDefinitions::class), config: $this->app->make('config'));
    }

    /** @param array<string, string|bool> $options
     * @return array{exit:int, output:string} */
    public function command(array $options = []): array
    {
        $command = new MakePanelCommand;
        $command->setLaravel($this->app);
        $cli = new \Symfony\Component\Console\Application;
        $cli->setAutoExit(false);
        $cli->setCatchExceptions(false);
        $cli->addCommand($command);
        $output = new BufferedOutput;
        $exit = $cli->run(new ArrayInput(['command' => 'sockit:make-panel', 'name' => 'Article', '--id' => 'acme.article', '--host' => 'article',
            '--modules' => 'acme.demo-targets', '--prefix' => 'article_', '--connection' => 'owner_a', ...$options]), $output);

        return ['exit' => $exit, 'output' => $output->fetch()];
    }

    /** @return array<string, string> Includes directories and links to prove zero mutation. */
    public function tree(): array
    {
        $tree = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($walk as $file) {
            if ($file instanceof \SplFileInfo) {
                $tree[substr($file->getPathname(), strlen($this->root) + 1)] = $file->isLink() ? 'link:'.readlink($file->getPathname()) : ($file->isDir() ? 'directory' : (string) hash_file('sha256', $file->getPathname()));
            }
        }
        ksort($tree);

        return $tree;
    }

    public function cleanup(): void
    {
        new Filesystem()->deleteDirectory($this->root);
    }
}
