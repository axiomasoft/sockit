<?php

declare(strict_types=1);

namespace SocKit\Database;

use Illuminate\Container\Container;
use LogicException;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Data\TablePrefix;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Laravel\PackmanMigration;
use SocKit\Configuration\SockitOptions;

/**
 * Base SocKit migration extending {@see PackmanMigration} with SocKit prefix ownership
 * verification and PostgreSQL-only CHECK constraint support.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
abstract class SockitMigration extends PackmanMigration
{
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

        $prefix = $container->bound(SockitOptions::class)
            ? $container->make(SockitOptions::class)->tablePrefix
            : new TablePrefix('sk_');

        return $prefix->table($logical);
    }

    protected function addCheckIfPostgres(string $table, string $name, string $expression): void
    {
        if ($this->db()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->check($table, $name, $expression);
    }
}
