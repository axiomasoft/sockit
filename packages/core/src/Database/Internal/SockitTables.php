<?php

declare(strict_types=1);

namespace SocKit\Database\Internal;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;
use SocKit\Configuration\SockitOptions;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelDefinition;
use SocKit\Panels\Data\PanelId;

/**
 * Resolves panel-isolated database connections and physical table names (including SQL schema qualification).
 */
#[Internal]
final readonly class SockitTables
{
    public function __construct(
        private DatabaseManager $db,
        private SockitOptions $options,
        private PanelDefinitions $panels,
    ) {}

    public function connection(?PanelId $panelId = null): Connection
    {
        $name = null;

        if ($panelId instanceof PanelId && $this->panels->isFrozen() && $this->panels->has($panelId)) {
            $panelConnection = $this->panels->get($panelId)->storage->connection;

            if ($panelConnection !== '') {
                $name = $panelConnection;
            }
        }

        if ($name === null && $this->options->connection !== null && $this->options->connection !== '') {
            $name = $this->options->connection;
        }

        return $this->db->connection($name);
    }

    public function table(string $logical, ?PanelId $panelId = null): string
    {
        if ($panelId instanceof PanelId && $this->panels->isFrozen() && $this->panels->has($panelId)) {
            $storage = $this->panels->get($panelId)->storage;

            if ($storage->schema !== null && $this->connection($panelId)->getDriverName() !== 'sqlite') {
                return $storage->qualifiedTable($logical);
            }

            return $storage->resolveTable($logical);
        }

        return $this->options->tablePrefix->table($logical);
    }

    public function panelDefinition(?PanelId $panelId): ?PanelDefinition
    {
        if (! $panelId instanceof PanelId || ! $this->panels->isFrozen() || ! $this->panels->has($panelId)) {
            return null;
        }

        return $this->panels->get($panelId);
    }
}
