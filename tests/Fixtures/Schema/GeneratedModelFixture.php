<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use SocKit\Schema\ModelBindings;
use SocKit\Schema\ModelEmitter;
use SocKit\Schema\PanelModel;
use SocKit\Tests\Integration\Support\PgTarget;

final class GeneratedModelFixture
{
    public readonly SnapshotFixture $snapshot;

    /** @var array<string, string> */
    public array $files = [];

    private readonly ?ConnectionResolverInterface $resolver;

    public function __construct()
    {
        $this->snapshot = new SnapshotFixture(modelRelations: true);
        $this->resolver = Model::getConnectionResolver();
        $manager = $this->snapshot->capsule->getDatabaseManager();
        $this->snapshot->capsule->addConnection(config: PgTarget::fromEnvironment()->decoyConfiguration(), name: 'decoy');
        $manager->setDefaultConnection('decoy');
        Model::setConnectionResolver($manager);
        foreach ($this->snapshot->schemas as $id => $schema) {
            $this->snapshot->migrations[$id]->up();
            foreach (new ModelEmitter()->render($schema) as $class => $source) {
                $this->files[$class] = SnapshotFixture::file($source);
                if (! class_exists($class, false)) {
                    require $this->files[$class];
                }
            }
            new ModelBindings()->validate($schema);
        }
    }

    /** @return class-string<PanelModel> */
    public function model(string $entity = 'demo_targets', string $panel = 'a'): string
    {
        $class = $this->snapshot->schemas[$panel]->storage->models[$entity];
        assert(is_a($class, PanelModel::class, true));

        return $class;
    }

    public static function instance(mixed $value): PanelModel
    {
        if (! $value instanceof PanelModel) {
            throw new \RuntimeException('Expected emitted panel model');
        }

        return $value;
    }

    public static function key(PanelModel $model): string
    {
        $id = $model->getKey();
        if (! is_string($id)) {
            throw new \RuntimeException('Expected lossless string primary key');
        }

        return $id;
    }

    /** @return Collection<int, Model> */
    public static function many(mixed $value): Collection
    {
        if (! $value instanceof Collection) {
            throw new \RuntimeException('Expected emitted model relation collection');
        }

        return $value;
    }

    public function cleanup(): void
    {
        foreach ($this->files as $path) {
            unlink($path);
        }
        if ($this->resolver !== null) {
            Model::setConnectionResolver($this->resolver);
        } else {
            Model::unsetConnectionResolver();
        }
        $this->snapshot->cleanup();
    }
}
