<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Database;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;

/**
 * Schema set provider for SocKit's optional Posts module.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class PostsSchemaSetProvider implements SchemaSetProvider
{
    /** @return list<SchemaSet> */
    #[Override]
    public function sets(): array
    {
        return [
            new SchemaSet(
                package: 'axiomasoft/sockit',
                name: 'sockit-posts',
                scope: SchemaScope::Central,
                path: dirname(__DIR__, 4).'/database/migrations/posts',
                version: 1,
                drivers: ['pgsql', 'sqlite'],
                lifecycle: SchemaLifecycle::Platform,
                requires: ['axiomasoft/sockit'],
            ),
        ];
    }
}
