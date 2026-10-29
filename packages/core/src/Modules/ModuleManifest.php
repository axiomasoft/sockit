<?php

declare(strict_types=1);

namespace SocKit\Modules;

use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ModuleManifest
{
    public const string ID_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:-[a-z0-9]+)*)+$/D';

    public const array DATABASES = ['pgsql', 'mysql', 'sqlite'];

    /** @param array<array-key, string> $databases */
    public function __construct(public string $id, public ?string $resourceRoot = null, public array $databases = []) {}
}
