<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use SocKit\Schema\ModelBindings;
use SocKit\Schema\PanelModel;
use SocKit\Tests\Fixtures\Schema\MigrationFixture;
use SocKit\Tests\Unit\Audience\Support\Scenario;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$fixture = new MigrationFixture(root: $argv[1]);
$boot = Scenario::object(json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR));
foreach (['selected', 'enableModule', 'consumers'] as $key) {
    if (! is_bool($boot[$key] ?? null)) {
        throw new RuntimeException('Invalid consumer flag');
    }
}
if (! is_int($boot['version'] ?? null)) {
    throw new RuntimeException('Invalid consumer version');
}
$fixture->boot(selected: $boot['selected'], version: $boot['version'], prefix: Scenario::text($boot['prefix']), contribution: Scenario::text($boot['contribution']),
    enableModule: $boot['enableModule'], variant: Scenario::text($boot['variant']), consumers: $boot['consumers'], generated: Scenario::text($boot['generated']));
$options = Scenario::object(json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR));
if (($options['operation'] ?? null) === 'schema') {
    echo json_encode(['schema' => $fixture->schema()->jsonSerialize()], JSON_THROW_ON_ERROR);
    exit(0);
}
if (($options['operation'] ?? null) === 'validate') {
    $schema = $fixture->schema();
    new ModelBindings()->validate($schema);
    $result = ['models_valid' => true];
    if (($options['crud'] ?? false) === true) {
        Model::setConnectionResolver($fixture->capsule->getDatabaseManager());
        $class = $schema->storage->models['demo_targets'];
        if (! is_subclass_of($class, PanelModel::class)) {
            throw new RuntimeException('Invalid consumer model');
        }
        $model = new $class(['installation' => 'host', 'panel' => 'acme.article', 'external_key' => 'json-test', 'metadata' => ['nested' => ['ok' => true]]]);
        $model->save();
        $roundtrip = $class::query()->whereKey($model->getKey())->firstOrFail();
        $result['metadata'] = $roundtrip->getAttribute('metadata');
        $result['relations'] = [];
        foreach (['parent', 'demoTargetsParent'] as $method) {
            $call = [$model, $method];
            if (! is_callable($call)) {
                throw new RuntimeException('Missing consumer relation');
            }
            $relation = Closure::fromCallable($call)();
            if (! $relation instanceof Relation) {
                throw new RuntimeException('Invalid consumer relation');
            }
            $result['relations'][] = $relation::class;
        }
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit(0);
}
if (! is_bool($options['upgrade'] ?? null) || ! is_bool($options['dryRun'] ?? null)) {
    throw new RuntimeException('Invalid command options');
}
$result = $fixture->commandLocal(upgrade: $options['upgrade'], dryRun: $options['dryRun'], panel: Scenario::text($options['panel']));
echo json_encode($result, JSON_THROW_ON_ERROR);
