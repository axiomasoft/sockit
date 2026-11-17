<?php

declare(strict_types=1);

namespace SocKit\Database;

use Closure;
use Illuminate\Container\Container;
use LogicException;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Data\TablePrefix;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Laravel\PackmanMigration;
use SocKit\Configuration\SockitOptions;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelStorage;

/**
 * Base SocKit migration extending {@see PackmanMigration} with panel-aware physical storage resolution
 * (connection, SQL schema, table prefix, logical table overrides) and PostgreSQL CHECK constraints.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
abstract class SockitMigration extends PackmanMigration
{
    private ?PanelStorage $activeStorage = null;

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

    /** @param Closure(): void $callback */
    protected function forEachPanelStorage(string $module, Closure $callback, bool $reverse = false): void
    {
        $targets = $this->resolveStorageTargets($module);

        if ($reverse) {
            $targets = array_reverse($targets);
        }

        $db = $this->db();
        $driver = $db->getDriverName();

        foreach ($targets as $storage) {
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

                continue;
            }

            try {
                $callback();
            } finally {
                $this->activeStorage = $previousStorage;
            }
        }
    }

    protected function addCheckIfPostgres(string $table, string $name, string $expression): void
    {
        if ($this->db()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->check($table, $name, $expression);
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
