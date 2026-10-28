<?php

declare(strict_types=1);

namespace SocKit\Schema;

use SocKit\Attributes\Api;
use SocKit\Modules\ModuleManifest;
use SocKit\Schema\Internal\SchemaValues;

#[Api(since: '1.0')]
final readonly class SchemaComponent
{
    /** @var array<string, int> Minimum required selected component versions. */
    public array $dependencies;

    /** @var list<SchemaStep> Ordered append-only history, not sorted by ID. */
    public array $steps;

    /** @param array<array-key, SchemaStep> $steps
     * @param array<string, int> $dependencies */
    public function __construct(public string $id, public int $version, array $steps, array $dependencies = [])
    {
        SchemaValues::require(valid: preg_match(ModuleManifest::ID_PATTERN, $id) === 1 && $version >= 1, key: 'component');
        SchemaValues::require(valid: array_is_list($steps) && $steps !== [], key: 'component.steps');
        $copy = $seen = $versions = [];
        $lastVersion = 1;
        foreach ($steps as $step) {
            SchemaValues::require(valid: ! isset($seen[$step->id]), key: 'steps.'.$step->id, rule: 'SCHEMA_STEP_DUPLICATE');
            SchemaValues::require(valid: $step->version >= $lastVersion && $step->version <= $version, key: 'steps.'.$step->id, rule: 'SCHEMA_HISTORY_INVALID');
            $lastVersion = $step->version;
            $versions[$step->version] = true;
            $seen[$step->id] = true;
            $copy[] = $step;
        }
        SchemaValues::require(valid: array_keys($versions) === range(1, $version), key: 'component.version', rule: 'SCHEMA_HISTORY_INVALID');
        $this->steps = $copy;
        $copy = [];
        foreach ($dependencies as $dependency => $minimum) {
            SchemaValues::require(valid: preg_match(ModuleManifest::ID_PATTERN, $dependency) === 1 && $minimum >= 1, key: 'dependencies.'.$dependency);
            $copy[$dependency] = $minimum;
        }
        ksort($copy);
        $this->dependencies = $copy;
    }
}
