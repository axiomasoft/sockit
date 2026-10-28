<?php

declare(strict_types=1);

namespace SocKit\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Configuration\SocKitConfig;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\PanelDefinitions;
use SocKit\Panels\PanelStorage;
use SocKit\Schema\Exceptions\InvalidSchemaException;
use SocKit\Schema\StoragePreflight;

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
            $deployments = $this->laravel->make('config')->get('sockit.storage.deployments', []);
            StoragePreflight::validateDeployments($deployments);
            assert(is_array($deployments));
            $identity = StoragePreflight::inspect(storage: $storage, connection: $connection, deployment: $deployments[$storage->connection] ?? null);
            foreach ($storage->tables as $entity => $table) {
                $name = $identity['schema'].'.'.$table;
                if (! $connection->getSchemaBuilder()->hasTable($name)) {
                    $errors[] = 'schema.missing.'.$entity;
                }
            }
            if (isset($storage->tables['outbox']) && ! in_array('schema.missing.outbox', $errors, true)) {
                $name = $identity['schema'].'.'.$storage->tables['outbox'];
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
        } catch (InvalidSchemaException $error) {
            $errors[] = 'connection.identity_unproven';
            $errors[] = $error->detail()['rule'];
        } catch (\Throwable) {
            $errors[] = 'connection_or_schema.unavailable';
        }
        $result = ['panel' => $panel, 'errors' => $errors, 'deliveries' => $stats, 'install_schema_compiler' => 'not_checked_p3'];
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $errors === [] ? 0 : 1;
    }
}
