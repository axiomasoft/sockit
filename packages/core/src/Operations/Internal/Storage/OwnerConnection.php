<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal\Storage;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\OperationHandles;
use SocKit\Support\Data\CommandNamespace;

/** Internal owner repositories. A transaction callback must perform local owner SQL only; no remote resolvers. */
final readonly class OwnerConnection
{
    /** @param array<string, string> $tables */
    public function __construct(private Connection $connection, private OperationHandles $handles, private OperationHandle $handle, private ?CommandNamespace $namespace, private array $tables, private string $prefix) {}

    /** @template T
     * @param Closure(self): T $callback
     * @return T */
    public function transaction(Closure $callback): mixed
    {
        if ($this->connection->transactionLevel() !== 0) {
            throw new InvalidConfigurationException(message: 'An operation requires its own owner transaction', detail: ['panel' => $this->handle->surface->panel->value, 'key' => 'transaction']);
        }
        $this->assertRouting();
        $this->handles->assertCurrent(handle: $this->handle, namespace: $this->namespace);

        return $this->connection->transaction(fn (): mixed => $callback($this));
    }

    /** Known logical entities only. Policy/receipt/CAS orchestration is added by its owning P2 items. */
    public function table(string $entity): Builder
    {
        $this->assertRouting();
        if (! isset($this->tables[$entity])) {
            throw new InvalidConfigurationException(message: 'Unknown kernel entity', detail: ['panel' => $this->handle->surface->panel->value, 'key' => 'tables.'.$entity]);
        }
        if ($this->connection->transactionLevel() === 0) {
            $this->handles->assertCurrent(handle: $this->handle, namespace: $this->namespace);
        }

        return $this->connection->table($this->tables[$entity]);
    }

    private function assertRouting(): void
    {
        if ($this->connection->getTablePrefix() !== $this->prefix) {
            throw new InvalidConfigurationException(message: 'Owner connection prefix changed after binding', detail: ['panel' => $this->handle->surface->panel->value, 'key' => 'connection.prefix']);
        }
    }

    /** A wakeup attached to this owner commit; durable delivery belongs to P2.6. */
    public function afterCommit(Closure $callback): void
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new InvalidConfigurationException(message: 'Owner afterCommit requires an active transaction', detail: ['panel' => $this->handle->surface->panel->value, 'key' => 'transaction']);
        }
        $this->connection->afterCommit($callback);
    }
}
