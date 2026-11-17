<?php

declare(strict_types=1);

namespace SocKit\Database;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;

/**
 * Declares SocKit's core platform schema set (`sockit-core`). Module schema sets are contributed
 * dynamically by enabled module plugins (`ReactionsPlugin`, `PostsPlugin`, `GroupsPlugin`).
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class SockitSchemaSetProvider implements SchemaSetProvider
{
    /** @return list<SchemaSet> */
    #[Override]
    public function sets(): array
    {
        return [
            new SchemaSet(
                package: 'axiomasoft/sockit',
                name: 'sockit-core',
                scope: SchemaScope::Central,
                path: dirname(__DIR__, 2).'/database/migrations/core',
                version: 1,
                drivers: ['pgsql', 'sqlite'],
                lifecycle: SchemaLifecycle::Platform,
            ),
        ];
    }
}
