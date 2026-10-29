<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Application;
use SocKit\Schema\PanelModel;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
if (! $app instanceof Application) {
    throw new RuntimeException('Invalid application');
}
$app->make(Kernel::class)->bootstrap();
/** @return class-string<PanelModel> */
function generatedRelationClass(string $class): string
{
    if (! is_a($class, PanelModel::class, true)) {
        throw new RuntimeException('Missing generated model');
    }

    return $class;
}
$parent = generatedRelationClass($argv[1])::query()->create(['name' => 'parent']);
$child = generatedRelationClass($argv[2])::query()->create(['parent_id' => $parent->getKey()]);
$loaded = $child->fresh();
if (! $loaded instanceof PanelModel) {
    throw new RuntimeException('Missing hydrated child');
}
$linked = $loaded->getRelationValue('parent');
$inverse = $parent->getRelationValue('demoChildrenParent');
if (! $linked instanceof PanelModel || $linked->getKey() !== $parent->getKey() || ! $inverse instanceof Collection || $inverse->count() !== 1 || $inverse->first()?->getKey() !== $child->getKey()) {
    throw new RuntimeException('Relation roundtrip failed');
}
echo json_encode(['belongs_to' => $linked->getKey(), 'has_many' => $inverse->count(), 'connection' => $loaded->getConnectionName()], JSON_THROW_ON_ERROR);
