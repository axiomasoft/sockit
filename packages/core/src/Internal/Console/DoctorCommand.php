<?php

declare(strict_types=1);

namespace SocKit\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Configuration\SocKitConfig;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\PanelDefinitions;
use SocKit\Panels\PanelStorage;

final class DoctorCommand extends Command
{
    protected $signature = 'sockit:doctor {--panel=} {--json}';

    protected $description = 'Check panel runtime readiness without modifying storage';

    public function handle(): int
    {
        $panel = $this->option('panel');
        try {
            if (! is_string($panel) || $panel === '') {
                throw new \InvalidArgumentException;
            }
            $id = new PanelId($panel);
            $definition = $this->laravel->make(PanelDefinitions::class)->get($id);
        } catch (\Throwable) {
            $this->line('panel.invalid_or_unknown');

            return 2;
        }
        $errors = [];
        $stats = [];
        foreach (SocKitConfig::resolverContracts() as $key => $contract) {
            try {
                $this->laravel->make($contract);
            } catch (\Throwable) {
                $errors[] = 'resolver.unavailable.'.$key;
            }
        }
        $storage = $definition->storage;
        foreach (PanelStorage::KERNEL_ENTITIES as $entity) {
            if (! isset($storage->tables[$entity])) {
                $errors[] = 'map.missing.'.$entity;
            }
        }
        try {
            $connection = $this->laravel->make(ConnectionResolverInterface::class)->connection($storage->connection);
            if ($connection->getDriverName() !== 'pgsql') {
                throw new \RuntimeException;
            }
            // A host-provided stable deployment identity is required; aliases alone prove nothing.
            $identity = $this->laravel->make('config')->get('sockit.deployment_identity');
            if (! is_string($identity) || $identity === '') {
                $errors[] = 'connection.identity_unproven';
            }
            $server = $connection->selectOne('SELECT current_database() AS db, inet_server_addr()::text AS address, inet_server_port() AS port');
            if (! $server instanceof \stdClass || ! is_string($server->db) || ! is_string($server->address) || $server->port === null) {
                $errors[] = 'connection.identity_unproven';
            }
            foreach ($storage->tables as $entity => $table) {
                $name = ($storage->schema === null ? '' : $storage->schema.'.').$table;
                if (! $connection->getSchemaBuilder()->hasTable($name)) {
                    $errors[] = 'schema.missing.'.$entity;
                }
            }
            if (isset($storage->tables['outbox']) && ! in_array('schema.missing.outbox', $errors, true)) {
                $name = ($storage->schema === null ? '' : $storage->schema.'.').$storage->tables['outbox'];
                $rows = $connection->table($name)->where('panel', $panel)->pluck('deliveries');
                foreach ($rows as $row) {
                    if (! is_string($row)) {
                        throw new \RuntimeException;
                    }
                    $states = json_decode($row, true, flags: JSON_THROW_ON_ERROR);
                    if (! is_array($states)) {
                        throw new \RuntimeException;
                    }
                    foreach ($states as $state) {
                        if (! is_array($state) || ! isset($state['status']) || ! is_string($state['status'])) {
                            throw new \RuntimeException;
                        }
                        $stats[$state['status']] = ($stats[$state['status']] ?? 0) + 1;
                    }
                }
            }
        } catch (\Throwable) {
            $errors[] = 'connection_or_schema.unavailable';
        }
        $result = ['panel' => $panel, 'errors' => $errors, 'deliveries' => $stats, 'install_schema_compiler' => 'not_checked_p3'];
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $errors === [] ? 0 : 1;
    }
}
