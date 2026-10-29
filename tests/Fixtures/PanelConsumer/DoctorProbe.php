<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\PanelConsumer;

use PDO;
use PDOStatement;
use RuntimeException;

/** Real verified test PDO; rejects every diagnostic statement outside a read-only transaction. */
final class DoctorProbe extends PDO
{
    /** @var list<string> */
    public array $sql = [];

    public function exec(string $statement): int|false
    {
        $this->sql[] = $statement;
        if (! in_array($statement, ['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY', 'SET LOCAL search_path TO pg_catalog'], true)) {
            throw new RuntimeException('Doctor attempted a mutating/session statement');
        }

        return parent::exec($statement);
    }

    /** @param array<int, mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->observe($query);

        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->observe($query);

        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    private function observe(string $sql): void
    {
        $this->sql[] = $sql;
        $readOnly = parent::query('SHOW transaction_read_only');
        if (! $this->inTransaction() || $readOnly === false || $readOnly->fetchColumn() !== 'on' || preg_match('/^\s*select\b/i', $sql) !== 1) {
            throw new RuntimeException('Doctor query is not in the owned read-only transaction');
        }
    }
}
