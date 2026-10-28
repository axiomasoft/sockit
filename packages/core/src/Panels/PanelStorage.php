<?php

declare(strict_types=1);

namespace SocKit\Panels;

use SocKit\Attributes\Api;
use SocKit\Panels\Internal\PanelValues;

#[Api(since: '1.0')]
final readonly class PanelStorage
{
    /** P1 owns only kernel entities. Module schema fragments extend maps in P3.
     * @var list<string> */
    public const array KERNEL_ENTITIES = ['surface_instances', 'receipts', 'outbox', 'tombstones'];

    /** @var array<string, string> Fully resolved declared names, without driver prefix. */
    public array $tables;

    /** @var array<string, class-string> */
    public array $models;

    /** @param array<string, string> $tables
     * @param array<string, class-string> $models */
    public function __construct(public string $connection, public ?string $schema, public string $prefix, array $tables, array $models, public string $ids = 'uuid7')
    {
        PanelValues::require(valid: preg_match('~^[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,95}$~D', $connection) === 1, key: 'connection', rule: 'PANEL_TABLE_INVALID');
        PanelValues::require(valid: $schema === null || PanelValues::identifier($schema), key: 'schema', rule: 'PANEL_TABLE_INVALID');
        PanelValues::require(valid: $prefix === '' || PanelValues::identifier($prefix), key: 'prefix', rule: 'PANEL_TABLE_INVALID');
        PanelValues::require(valid: $ids === 'uuid7', key: 'ids', rule: 'PANEL_TABLE_INVALID');
        $resolved = [];
        foreach ($tables as $key => $table) {
            PanelValues::require(valid: in_array($key, self::KERNEL_ENTITIES, true) && PanelValues::identifier($table), key: 'tables.'.$key, rule: 'PANEL_TABLE_INVALID');
            $resolved[$key] = $table;
        }
        PanelValues::require(valid: count(array_unique($resolved)) === count($resolved), key: 'tables', rule: 'PANEL_STORAGE_COLLISION');
        $modelMap = [];
        foreach ($models as $key => $model) {
            // No autoload, Eloquent, SQL, or model binding verification until P3.
            PanelValues::require(valid: in_array($key, self::KERNEL_ENTITIES, true) && preg_match('~^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$~D', $model) === 1, key: 'models.'.$key, rule: 'PANEL_MODEL_INVALID');
            $modelMap[$key] = $model;
        }
        ksort($resolved);
        ksort($modelMap);
        $this->tables = $resolved;
        $this->models = $modelMap;
    }
}
