<?php

declare(strict_types=1);

namespace SocKit\Database;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use LogicException;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Data\TablePrefix;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Laravel\PackmanMigration;
use SocKit\Configuration\SockitOptions;
use SocKit\Database\Internal\SockitRows;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelStorage;
use SocKit\Support\Ids;

/**
 * Base SocKit migration extending {@see PackmanMigration} with panel-aware physical storage resolution
 * (connection, SQL schema, table prefix, logical table overrides), PostgreSQL CHECK constraints,
 * and frozen installation-snapshot rollback ownership per P3.3 / PANEL-11.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
abstract class SockitMigration extends PackmanMigration
{
    private const string SNAPSHOT_TABLE = 'sockit_schema_snapshots';

    private ?PanelStorage $activeStorage = null;

    /** @var array<string, string> */
    private array $recordedTables = [];

    #[Override]
    protected function table(string $logical): string
    {
        $container = Container::getInstance();

        if ($container->bound(SchemaOwnership::class)) {
            $ownership = $container->make(SchemaOwnership::class);

            if ($ownership->ownerOf($logical) === null) {
                throw new LogicException("Table '{$logical}' has no owner: declare its noun through SchemaOwnership.");
            }
        }

        if ($this->activeStorage instanceof PanelStorage) {
            return $this->activeStorage->resolveTable($logical);
        }

        $prefix = $container->bound(SockitOptions::class)
            ? $container->make(SockitOptions::class)->tablePrefix
            : new TablePrefix('sk_');

        return $prefix->table($logical);
    }

    /**
     * @param  Closure(Blueprint): void  $define
     */
    #[Override]
    protected function create(string $logical, Closure $define): string
    {
        $physical = parent::create($logical, $define);
        $this->recordedTables[$logical] = $physical;

        return $physical;
    }

    /** @param Closure(): void $callback */
    protected function forEachPanelStorage(string $module, Closure $callback, bool $reverse = false): void
    {
        if ($reverse) {
            $this->runSnapshottedDown($module, $callback);

            return;
        }

        $this->ensureSnapshotTableExists();

        $targets = $this->resolveStorageTargets($module);
        $db = $this->db();
        $connectionName = $db->getName() ?? 'default';

        foreach ($targets as $storage) {
            $this->recordedTables = [];

            $this->withPanelStorage($storage, $callback);

            $effectiveMap = array_merge($storage->tables, $this->recordedTables);

            $db->table(self::SNAPSHOT_TABLE)->insert([
                'id' => Ids::uuid7(),
                'module' => $module,
                'connection' => $connectionName,
                'panel_schema' => $storage->schema,
                'table_prefix' => $storage->prefix,
                'tables_map' => json_encode($effectiveMap, JSON_THROW_ON_ERROR),
                'created_at' => gmdate('Y-m-d H:i:s.uP'),
            ]);
        }
    }

    protected function addCheckIfPostgres(string $table, string $name, string $expression): void
    {
        if ($this->db()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->check($table, $name, $expression);
    }

    /** @param Closure(): void $callback */
    private function runSnapshottedDown(string $module, Closure $callback): void
    {
        $db = $this->db();
        $schema = $this->schema();

        if (! $schema->hasTable(self::SNAPSHOT_TABLE)) {
            return;
        }

        $connectionName = $db->getName() ?? 'default';
        $rows = $db->table(self::SNAPSHOT_TABLE)
            ->where('module', $module)
            ->where('connection', $connectionName)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        foreach ($rows as $row) {
            /** @var array<string, string> $tablesMap */
            $tablesMap = SockitRows::jsonArray($row, 'tables_map');
            $storage = new PanelStorage(
                connection: $connectionName,
                schema: SockitRows::optStr($row, 'panel_schema'),
                prefix: SockitRows::str($row, 'table_prefix', 'sk_'),
                tables: $tablesMap,
            );

            $this->withPanelStorage($storage, $callback);

            $db->table(self::SNAPSHOT_TABLE)
                ->where('id', SockitRows::str($row, 'id'))
                ->delete();
        }

        if ($db->table(self::SNAPSHOT_TABLE)->count() === 0) {
            $schema->dropIfExists(self::SNAPSHOT_TABLE);
        }
    }

    /** @param Closure(): void $callback */
    private function withPanelStorage(PanelStorage $storage, Closure $callback): void
    {
        $db = $this->db();
        $driver = $db->getDriverName();
        $previousStorage = $this->activeStorage;
        $this->activeStorage = $storage;

        if ($driver === 'pgsql' && $storage->schema !== null) {
            $db->statement('create schema if not exists "'.$storage->schema.'"');
            $rawSearchPath = $db->scalar("select current_setting('search_path')");
            $previousSearchPath = is_string($rawSearchPath) && $rawSearchPath !== '' ? $rawSearchPath : 'public';
            $db->statement("select set_config('search_path', ?, false)", [$storage->schema]);

            try {
                $callback();
            } finally {
                $db->statement("select set_config('search_path', ?, false)", [$previousSearchPath]);
                $this->activeStorage = $previousStorage;
            }

            return;
        }

        try {
            $callback();
        } finally {
            $this->activeStorage = $previousStorage;
        }
    }

    private function ensureSnapshotTableExists(): void
    {
        $schema = $this->schema();

        if ($schema->hasTable(self::SNAPSHOT_TABLE)) {
            return;
        }

        $schema->create(self::SNAPSHOT_TABLE, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('module', 64);
            $table->string('connection', 96);
            $table->string('panel_schema', 96)->nullable();
            $table->string('table_prefix', 32);
            $table->text('tables_map');
            $table->string('created_at', 48);

            $table->index(['module', 'connection']);
        });
    }

    /** @return list<PanelStorage> */
    private function resolveStorageTargets(string $module): array
    {
        $container = Container::getInstance();
        $options = $container->bound(SockitOptions::class) ? $container->make(SockitOptions::class) : null;
        $activeConnection = $this->db()->getName();

        if (! $container->bound(PanelDefinitions::class)) {
            return [new PanelStorage(prefix: $options?->tablePrefix->value() ?? 'sk_')];
        }

        $panels = $container->make(PanelDefinitions::class);

        if (! $panels->isFrozen() || $panels->all() === []) {
            return [new PanelStorage(prefix: $options?->tablePrefix->value() ?? 'sk_')];
        }

        $targets = [];
        $seen = [];

        foreach ($panels->all() as $panel) {
            if ($module !== 'core' && ! $panel->hasModule($module)) {
                continue;
            }

            $defaultConnection = ($options instanceof SockitOptions ? $options->connection : null) ?? $activeConnection;
            $panelConnection = $panel->storage->connection !== ''
                ? $panel->storage->connection
                : $defaultConnection;

            if ($panelConnection !== $activeConnection) {
                continue;
            }

            $signature = ($panel->storage->schema ?? '').'|'.$panel->storage->prefix.'|'.json_encode($panel->storage->tables, JSON_THROW_ON_ERROR);

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $targets[] = $panel->storage;
        }

        return $targets;
    }
}
